<?php

namespace Tests\Feature;

use App\Actions\Billing\ProsesTagihan;
use App\Actions\Billing\TandaiLunas;
use App\Enums\PlanKategori;
use App\Jobs\DeployLanding;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TokenAiTest extends TestCase
{
    use RefreshDatabase;

    private function plan(): Plan
    {
        return Plan::create(['kategori' => PlanKategori::Ai, 'slug' => 'ai-deepseek-v4-1-flash', 'nama' => 'DeepSeek V4.1 Flash', 'harga' => 5000, 'spek' => ['model' => 'deepseek-v4.1-flash']]);
    }

    public function test_beli_lunas_memberi_api_key_dan_saldo_lalu_topup_menambah(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $plan = $this->plan();

        $this->actingAs($user)->post(route('katalog.store', $plan), ['label' => 'Bot'])->assertSessionHasErrors('token_juta');
        $this->actingAs($user)->post(route('katalog.store', $plan), ['label' => 'Bot', 'token_juta' => 3])->assertRedirect();

        $inv = Invoice::sole();
        $this->assertSame([15000, 3], [$inv->jumlah, $inv->token_juta]);
        app(TandaiLunas::class)->handle($inv, 'transfer');

        $s = Service::sole();
        $this->assertSame('aktif', $s->status->value);
        $this->assertNull($s->jatuh_tempo);
        $this->assertSame(3_000_000, $s->tokenAi->saldo);
        $kunci = $this->actingAs($user)->get(route('layanan.show', $s))->assertOk()
            ->assertInertia(fn ($p) => $p->where('token.saldo', 3_000_000)->where('token.model', 'deepseek-v4.1-flash'))
            ->viewData('page')['props']['layanan']['kredensial']['api_key'];
        $this->assertSame(hash('sha256', $kunci), $s->tokenAi->kunci_hash);

        $this->actingAs($user)->post(route('layanan.topup', $s), ['token_juta' => 2])->assertRedirect();
        $topup = Invoice::latest('id')->first();
        $this->assertSame(10000, $topup->jumlah);
        app(TandaiLunas::class)->handle($topup, 'qris');
        $this->assertSame(5_000_000, $s->tokenAi->fresh()->saldo);
        $this->assertSame(1, $s->tokenAi()->count()); // kunci tidak berganti saat top-up
        Queue::assertNothingPushed(); // tanpa provisioning pod
    }

    public function test_topup_belum_dibayar_tidak_menangguhkan_dan_tidak_ada_tagihan_bulanan(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan();
        $this->actingAs($user)->post(route('katalog.store', $plan), ['label' => 'Bot', 'token_juta' => 1]);
        app(TandaiLunas::class)->handle(Invoice::sole(), 'transfer');
        $s = Service::sole();
        $this->actingAs($user)->post(route('layanan.topup', $s), ['token_juta' => 1]);

        $this->travel(30)->days();
        $this->assertSame([0, 0], app(ProsesTagihan::class)->handle());
        $this->assertSame('aktif', $s->fresh()->status->value);
    }

    public function test_topup_ditolak_untuk_layanan_orang_lain_dan_bukan_ai(): void
    {
        $plan = $this->plan();
        $s = Service::create(['user_id' => User::factory()->create()->id, 'plan_id' => $plan->id, 'label' => 'x', 'status' => 'aktif', 'harga' => 5000]);
        $this->actingAs(User::factory()->create())->post(route('layanan.topup', $s), ['token_juta' => 1])->assertNotFound();
    }

    public function test_ubah_harga_token_ditulis_ke_landing_dan_sync_tidak_menonaktifkan(): void
    {
        Queue::fake();
        $dir = sys_get_temp_dir().'/tokenai-'.uniqid();
        mkdir($dir);
        file_put_contents("$dir/paket.json", json_encode(['vps' => [], 'managed' => [],
            'ai' => [['slug' => 'ai-deepseek-v4-1-flash', 'nama' => 'DeepSeek V4.1 Flash', 'harga' => 5000]]]));
        file_put_contents("$dir/apps.json", '[]');
        config(['cloud.katalog_dir' => $dir]);

        $this->artisan('katalog:sync')->assertSuccessful();
        $plan = Plan::where('slug', 'ai-deepseek-v4-1-flash')->sole();
        $this->assertTrue($plan->aktif);

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->put(route('admin.paket.update', $plan), ['harga' => 6000, 'aktif' => true, 'stok' => null])->assertRedirect();
        Queue::assertPushed(DeployLanding::class);
        $this->assertSame(6000, json_decode(file_get_contents("$dir/paket.json"), true)['ai'][0]['harga']);

        $this->artisan('katalog:sync')->assertSuccessful();
        $this->assertSame([6000, true], [$plan->fresh()->harga, $plan->fresh()->aktif]);
    }

    public function test_dashboard_menampilkan_persen_pemakaian_token(): void
    {
        $user = User::factory()->create();
        $service = \App\Models\Service::create(['user_id' => $user->id, 'plan_id' => $this->plan()->id, 'label' => 'chatbot', 'status' => \App\Enums\ServiceStatus::Aktif, 'harga' => 5000]);
        $service->tokenAi()->create(['kunci_hash' => str_repeat('a', 64), 'saldo' => 7_500_000, 'terpakai' => 2_500_000]);

        $this->actingAs($user)->get(route('customer.dashboard'))->assertInertia(fn ($page) => $page
            ->where('token_ai.0.kuota', 10_000_000)
            ->where('token_ai.0.persen', 25));
    }

    public function test_halaman_layanan_menampilkan_grafik_pemakaian_milik_sendiri_dan_sukses_saja(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan();
        $service = \App\Models\Service::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'label' => 'chatbot', 'status' => \App\Enums\ServiceStatus::Aktif, 'harga' => 5000]);
        $t = $service->tokenAi()->create(['kunci_hash' => str_repeat('b', 64), 'saldo' => 1_000_000, 'terpakai' => 0]);
        $lain = \App\Models\Service::create(['user_id' => User::factory()->create()->id, 'plan_id' => $plan->id, 'label' => 'x', 'status' => \App\Enums\ServiceStatus::Aktif, 'harga' => 5000]);
        $tLain = $lain->tokenAi()->create(['kunci_hash' => str_repeat('c', 64), 'saldo' => 1_000_000, 'terpakai' => 0]);
        $baris = fn ($id, $status, $total, $waktu) => \Illuminate\Support\Facades\DB::table('token_ai_pemakaian')->insert(['token_ai_id' => $id, 'model' => 'm', 'prompt' => $total, 'completion' => 0, 'total' => $total, 'status' => $status, 'created_at' => $waktu]);
        $baris($t->id, 200, 100, now());
        $baris($t->id, 200, 50, now());
        $baris($t->id, 502, 0, now());
        $baris($tLain->id, 200, 999, now());
        $baris($t->id, 200, 30, now()->subDays(35));

        $this->actingAs($user)->get(route('layanan.show', $service))->assertInertia(fn ($page) => $page
            ->has('token.tren', 30)
            ->where('token.tren.29.token', 150)
            ->where('token.tren.29.request', 2)
            ->where('token.tren_lalu.24.token', 30));

        // Detail pemakaian: potong saldo (3 harga) & biaya memakai harga paket saat ini, bukan harga layanan saat dipesan
        \Illuminate\Support\Facades\DB::table('token_ai_pemakaian')->insert(['token_ai_id' => $t->id, 'model' => 'm', 'prompt' => 5000, 'cache_hit' => 4000, 'completion' => 10, 'total' => 5010, 'potong' => 1000, 'status' => 200, 'created_at' => now()]);
        $plan->update(['harga' => 28500]);
        $this->actingAs($user)->get(route('layanan.show', $service))->assertInertia(fn ($page) => $page
            ->where('token.harga', 28500)
            ->where('token.pemakaian.0.cache_hit', 4000)
            ->where('token.pemakaian.0.potong', 1000)
            ->where('token.pemakaian.0.biaya', 28.5)
            ->where('token.pemakaian.1.potong', 30)); // baris lama tanpa potong = total
    }
}
