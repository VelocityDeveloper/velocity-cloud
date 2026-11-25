<?php

namespace Tests\Feature;

use App\Enums\PlanKategori;
use App\Jobs\DeployLanding;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaketTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/paket-test-'.uniqid().'/src/data';
        mkdir($this->dir, 0700, true);
        file_put_contents("$this->dir/paket.json", json_encode(['vps' => [['nama' => 'Cloud 1', 'harga' => 114000, 'cpu' => '1 Core', 'ram' => '1 GB', 'disk' => '15 GB', 'fitur' => []]], 'managed' => []], JSON_PRETTY_PRINT));
        file_put_contents("$this->dir/apps.json", json_encode([['slug' => 'n8n', 'nama' => 'n8n', 'kategori' => 'Otomasi', 'harga' => 50000, 'ringkas' => 'x', 'fitur' => [], 'pod' => ['cpu' => 1, 'ram_mb' => 1024]]], JSON_PRETTY_PRINT));
        config(['cloud.katalog_dir' => $this->dir]);
    }

    private function vps(?int $stok = null): Plan
    {
        return Plan::create(['kategori' => PlanKategori::Vps, 'slug' => 'vps-cloud-1', 'nama' => 'Cloud 1', 'harga' => 114000, 'stok' => $stok]);
    }

    public function test_stok_habis_menolak_pesanan_dan_gagal_mengembalikan_stok(): void
    {
        $user = User::factory()->create();
        $plan = $this->vps(1);

        $this->actingAs($user)->post(route('katalog.store', $plan), ['label' => 'satu', 'os' => 'ubuntu-24.04'])->assertRedirect();
        $this->assertSame(0, $plan->sisaStok());
        $this->actingAs($user)->post(route('katalog.store', $plan), ['label' => 'dua', 'os' => 'ubuntu-24.04'])->assertStatus(422);
        $this->assertSame(1, Service::count());

        Service::sole()->update(['status' => 'dibatalkan']);
        $this->assertTrue($plan->fresh()->bisaDipesan());
    }

    public function test_admin_ubah_harga_menulis_json_landing_dan_build_ulang(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $plan = $this->vps();

        $this->actingAs($admin)->put(route('admin.paket.update', $plan), ['harga' => 150000, 'aktif' => true, 'stok' => 5])->assertRedirect();

        $plan->refresh();
        $this->assertSame([150000, 5], [$plan->harga, $plan->stok]);
        $this->assertSame(150000, json_decode(file_get_contents("$this->dir/paket.json"), true)['vps'][0]['harga']);
        Queue::assertPushed(DeployLanding::class);

        // Off → "aktif": false di JSON + build; on lagi → kunci dihapus.
        Queue::fake();
        $this->actingAs($admin)->put(route('admin.paket.update', $plan), ['harga' => 150000, 'aktif' => false, 'stok' => 5])->assertRedirect();
        $this->assertFalse($plan->fresh()->aktif);
        $this->assertFalse(json_decode(file_get_contents("$this->dir/paket.json"), true)['vps'][0]['aktif']);
        Queue::assertPushed(DeployLanding::class);
        $this->actingAs($admin)->put(route('admin.paket.update', $plan), ['harga' => 150000, 'aktif' => true, 'stok' => 5])->assertRedirect();
        $this->assertArrayNotHasKey('aktif', json_decode(file_get_contents("$this->dir/paket.json"), true)['vps'][0]);

        // Hanya stok berubah → tanpa build landing.
        Queue::fake();
        $this->actingAs($admin)->put(route('admin.paket.update', $plan), ['harga' => 150000, 'aktif' => true, 'stok' => null])->assertRedirect();
        Queue::assertNothingPushed();

        $this->actingAs(User::factory()->create())->put(route('admin.paket.update', $plan), ['harga' => 1, 'aktif' => true])->assertForbidden();
    }

    public function test_admin_ubah_harga_input_cache_ai_dan_paket_baru_masuk_landing(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $plan = Plan::create(['kategori' => PlanKategori::Ai, 'slug' => 'ai-model-x', 'nama' => 'Model X', 'harga' => 10000, 'aktif' => false, 'spek' => ['model' => 'model-x', 'satuan' => '1 juta token']]);

        // Belum ada di paket.json landing: dinyalakan = ditambahkan, bukan galat
        $this->actingAs($admin)->put(route('admin.paket.update', $plan), ['harga' => 12000, 'aktif' => true, 'harga_input' => 3000, 'harga_cache' => 300])->assertSessionHasNoErrors();
        $this->assertSame(['model' => 'model-x', 'satuan' => '1 juta token', 'harga_input' => 3000, 'harga_cache' => 300], $plan->refresh()->spek);
        $this->assertSame(['slug' => 'ai-model-x', 'nama' => 'Model X', 'model' => 'model-x', 'harga' => 12000, 'harga_input' => 3000, 'harga_cache' => 300], json_decode(file_get_contents("$this->dir/paket.json"), true)['ai'][0]);

        // Provider saja: harga input/cache tetap
        $this->actingAs($admin)->put(route('admin.paket.update', $plan), ['harga' => 12000, 'aktif' => true, 'provider' => 'Anthropic'])->assertSessionHasNoErrors();
        $this->assertSame(['Anthropic', 3000], [$plan->refresh()->spek['provider'], $plan->spek['harga_input']]);

        // Saklar on/off tanpa harga input/cache = tetap; input kosong = kunci dihapus
        $this->actingAs($admin)->put(route('admin.paket.update', $plan), ['harga' => 12000, 'aktif' => false])->assertSessionHasNoErrors();
        $this->assertSame(3000, $plan->refresh()->spek['harga_input']);
        $this->actingAs($admin)->put(route('admin.paket.update', $plan), ['harga' => 12000, 'aktif' => true, 'harga_input' => null, 'harga_cache' => null])->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('harga_input', $plan->refresh()->spek);
        $this->assertCount(1, $ai = json_decode(file_get_contents("$this->dir/paket.json"), true)['ai']);
        $this->assertArrayNotHasKey('harga_input', $ai[0]);
    }

    public function test_harga_promo_ai_berlaku_hanya_dalam_periode(): void
    {
        Queue::fake();
        $this->travelTo('2026-12-01 10:00');
        $admin = User::factory()->create(['is_admin' => true]);
        $plan = Plan::create(['kategori' => PlanKategori::Ai, 'slug' => 'ai-model-x', 'nama' => 'Model X', 'harga' => 10000, 'aktif' => true, 'spek' => ['model' => 'model-x']]);

        $this->actingAs($admin)->put(route('admin.paket.update', $plan), ['harga' => 10000, 'aktif' => true, 'harga_promo' => 10000])->assertSessionHasErrors('harga_promo');
        $this->actingAs($admin)->put(route('admin.paket.update', $plan), ['harga' => 10000, 'aktif' => true, 'harga_promo' => 7000, 'promo_mulai' => '2026-12-05T00:00', 'promo_selesai' => '2026-12-01T00:00'])->assertSessionHasErrors('promo_selesai');
        $this->actingAs($admin)->put(route('admin.paket.update', $plan), ['harga' => 10000, 'aktif' => true, 'harga_promo' => 7000, 'promo_mulai' => '2026-12-05T00:00', 'promo_selesai' => '2026-12-10T23:59'])->assertSessionHasNoErrors();
        $this->assertSame([7000, '2026-12-05T00:00', '2026-12-10T23:59'], [$plan->refresh()->spek['harga_promo'], $plan->spek['promo_mulai'], $plan->spek['promo_selesai']]);
        $this->assertArrayNotHasKey('harga_promo', json_decode(file_get_contents("$this->dir/paket.json"), true)['ai'][0]); // landing tetap harga normal

        $pelanggan = User::factory()->create();
        $beli = fn () => $this->actingAs($pelanggan)->post(route('katalog.store', $plan), ['label' => 'Bot', 'token_juta' => 2]);
        $beli();
        $this->assertSame(20000, \App\Models\Invoice::latest('id')->first()->jumlah); // belum mulai

        $this->travelTo('2026-12-05 00:00');
        $beli();
        $this->assertSame(14000, \App\Models\Invoice::latest('id')->first()->jumlah);
        $this->actingAs($pelanggan)->get(route('katalog.show', $plan))->assertInertia(fn ($p) => $p
            ->where('paket.harga_promo', 7000)->where('paket.promo_selesai', '2026-12-10T23:59')->missing('paket.spek.harga_promo'));

        $this->travelTo('2026-12-10 23:59:30');
        $this->assertSame(7000, $plan->refresh()->hargaBeli());
        $this->travelTo('2026-12-11 00:00');
        $this->assertSame(10000, $plan->refresh()->hargaBeli());

        // dikosongkan = promo dihapus dari spek
        $this->actingAs($admin)->put(route('admin.paket.update', $plan), ['harga' => 10000, 'aktif' => true, 'harga_promo' => null, 'promo_mulai' => null, 'promo_selesai' => null])->assertSessionHasNoErrors();
        $this->assertSame(['model' => 'model-x'], $plan->refresh()->spek);
    }

    public function test_admin_hapus_paket_ai_yang_belum_dipakai(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $plan = Plan::create(['kategori' => PlanKategori::Ai, 'slug' => 'ai-model-x', 'nama' => 'Model X', 'harga' => 10000, 'aktif' => false, 'spek' => ['model' => 'model-x']]);
        $this->actingAs($admin)->put(route('admin.paket.update', $plan), ['harga' => 10000, 'aktif' => true])->assertSessionHasNoErrors();
        $this->assertCount(1, json_decode(file_get_contents("$this->dir/paket.json"), true)['ai']); // dinyalakan = masuk landing

        // Dipakai layanan (status apa pun) = ditolak
        $s = Service::create(['user_id' => $admin->id, 'plan_id' => $plan->id, 'label' => 'x', 'status' => 'dibatalkan', 'harga' => 10000]);
        $this->actingAs($admin)->delete(route('admin.paket.destroy', $plan))->assertSessionHasErrors('hapus');
        $this->assertModelExists($plan);

        $s->delete();
        $this->actingAs(User::factory()->create())->delete(route('admin.paket.destroy', $plan))->assertForbidden();
        $this->actingAs($admin)->delete(route('admin.paket.destroy', $plan))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertModelMissing($plan);
        $this->assertSame([], json_decode(file_get_contents("$this->dir/paket.json"), true)['ai']);

        // Hanya paket AI
        $this->actingAs($admin)->delete(route('admin.paket.destroy', $this->vps()))->assertNotFound();
    }

    public function test_admin_ubah_spek_vps_dan_limit_pod_app(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $vps = $this->vps();

        $this->actingAs($admin)->put(route('admin.paket.update', $vps), ['harga' => 114000, 'aktif' => true, 'cpu' => '2 Core', 'ram' => '4 GB', 'disk' => '40 GB NVMe', 'bandwidth' => '2 TB/bln'])->assertRedirect();
        $this->assertSame(['cpu' => '2 Core', 'ram' => '4 GB', 'disk' => '40 GB NVMe', 'bandwidth' => '2 TB/bln'], $vps->fresh()->spek);
        $json = json_decode(file_get_contents("$this->dir/paket.json"), true)['vps'][0];
        $this->assertSame(['2 Core', '4 GB', '40 GB NVMe', '2 TB/bln'], [$json['cpu'], $json['ram'], $json['disk'], $json['bandwidth']]);
        Queue::assertPushed(DeployLanding::class);
        $this->actingAs($admin)->put(route('admin.paket.update', $vps), ['harga' => 114000, 'aktif' => true, 'cpu' => '2 Core', 'ram' => 'banyak', 'disk' => '40 GB'])->assertSessionHasErrors('ram');

        // App: limit pod (angka) ditulis ke apps.json field pod; spek tampilan ikut dihitung ulang.
        file_put_contents("$this->dir/apps.json", json_encode([['slug' => 'n8n', 'nama' => 'n8n', 'kategori' => 'Otomasi', 'harga' => 50000, 'ringkas' => 'x', 'fitur' => [], 'pod' => ['cpu' => 1, 'ram_mb' => 1024, 'komponen' => ['n8n']]]]));
        config(['cloud.pods.n8n.limit' => ['cpu' => 1, 'memori' => '1024m']]);
        $app = Plan::create(['kategori' => PlanKategori::App, 'slug' => 'app-n8n', 'nama' => 'n8n', 'harga' => 50000, 'spek' => Plan::spekPod('Otomasi', 1, 1024)]);
        $this->actingAs($admin)->get(route('admin.paket.index', 'app'))->assertInertia(fn ($p) => $p->where('paket.0.pod', ['cpu' => 1, 'ram_mb' => 1024, 'disk_gb' => null]));

        Queue::fake();
        $this->actingAs($admin)->put(route('admin.paket.update', $app), ['harga' => 50000, 'aktif' => true, 'cpu' => 1.5, 'ram_mb' => 2048, 'disk_gb' => 10])->assertRedirect();
        $this->assertSame(['cpu' => 1.5, 'ram_mb' => 2048, 'komponen' => ['n8n'], 'disk_gb' => 10], json_decode(file_get_contents("$this->dir/apps.json"), true)[0]['pod']);
        $this->assertSame(['kategori_app' => 'Otomasi', 'cpu' => '1,5 vCPU', 'ram' => '2 GB', 'disk' => '10 GB'], $app->fresh()->spek);
        $this->assertSame(10, $app->fresh()->limitPod()['disk_gb']);
        Queue::assertPushed(DeployLanding::class);
        $this->actingAs($admin)->put(route('admin.paket.update', $app), ['harga' => 50000, 'aktif' => true, 'cpu' => 0.3, 'ram_mb' => 64])->assertSessionHasErrors(['cpu', 'ram_mb']);

        // Disk wajib diisi bersama CPU/RAM.
        $this->actingAs($admin)->put(route('admin.paket.update', $app), ['harga' => 50000, 'aktif' => true, 'cpu' => 1.5, 'ram_mb' => 2048, 'disk_gb' => null])->assertSessionHasErrors('disk_gb');

        // Saklar on/off tanpa spek tidak mengubah spek.
        $this->actingAs($admin)->put(route('admin.paket.update', $app), ['harga' => 50000, 'aktif' => false])->assertRedirect();
        $this->assertSame('2 GB', $app->fresh()->spek['ram']);
    }

    public function test_sync_tidak_menimpa_aktif_dan_stok(): void
    {
        $plan = $this->vps(3);
        $plan->update(['aktif' => false]);

        $this->artisan('katalog:sync')->assertSuccessful();

        $plan->refresh();
        $this->assertFalse($plan->aktif);
        $this->assertSame(3, $plan->stok);
        $this->assertTrue(Plan::where('slug', 'app-n8n')->sole()->aktif);
        // apps.json tanpa disk_gb → disk default.
        $this->assertSame(Plan::DISK_DEFAULT_GB.' GB', Plan::where('slug', 'app-n8n')->sole()->spek['disk']);
    }

    public function test_halaman_paket_per_kategori(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->vps();
        Plan::create(['kategori' => PlanKategori::Ai, 'slug' => 'ai-x', 'nama' => 'AI X', 'harga' => 5000]);

        $this->actingAs($admin)->get('/admin/paket')->assertRedirect('/admin/paket/vps');
        $this->actingAs($admin)->get(route('admin.paket.index', 'ai'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('admin/Paket')->where('kategori.value', 'ai')->has('paket', 1)->where('paket.0.nama', 'AI X'));
        $this->actingAs($admin)->get('/admin/paket/salah')->assertNotFound();
        $this->actingAs(User::factory()->create())->get(route('admin.paket.index', 'vps'))->assertForbidden();
    }
}
