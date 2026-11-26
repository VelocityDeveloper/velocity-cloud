<?php

namespace Tests\Feature;

use App\Actions\Billing\TandaiLunas;
use App\Enums\InvoiceStatus;
use App\Enums\PlanKategori;
use App\Enums\ServiceStatus;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Promo;
use App\Models\PromoKlaim;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PromoTest extends TestCase
{
    use RefreshDatabase;

    private Plan $ai;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->ai = Plan::create(['kategori' => PlanKategori::Ai, 'slug' => 'ai-deepseek-v4-1-flash', 'nama' => 'DeepSeek', 'harga' => 5000, 'spek' => ['model' => 'deepseek-v4.1-flash']]);
    }

    private function promo(array $ubah = []): Promo
    {
        return Promo::create([...['nama' => 'Gratis 20 jt', 'bonus_token_juta' => 20, 'min_pembelian' => 100000, 'kategori' => ['vps', 'app', 'ai']], ...$ubah]);
    }

    private function tagihan(User $u, PlanKategori $k, int $jumlah, ?int $tokenJuta = null): Invoice
    {
        $plan = $k === PlanKategori::Ai ? $this->ai : Plan::create(['kategori' => $k, 'slug' => $k->value.'-'.uniqid(), 'nama' => 'Paket', 'harga' => $jumlah]);
        $s = Service::create(['user_id' => $u->id, 'plan_id' => $plan->id, 'label' => 'x', 'status' => ServiceStatus::MenungguPembayaran, 'harga' => $plan->harga]);

        return Invoice::create(['user_id' => $u->id, 'service_id' => $s->id, 'nomor' => Invoice::nomorBaru(), 'jumlah' => $jumlah, 'token_juta' => $tokenJuta,
            'status' => InvoiceStatus::BelumDibayar, 'periode_mulai' => today(), 'periode_selesai' => today()->addMonth(), 'jatuh_tempo' => today()]);
    }

    private function saldoToken(User $u): int
    {
        return (int) Service::where('user_id', $u->id)->where('plan_id', $this->ai->id)->get()->sum(fn ($s) => $s->tokenAi?->saldo);
    }

    public function test_promo_ber_plan_selalu_masuk_ke_paket_itu(): void
    {
        $this->promo(['plan_id' => $this->ai->id]);
        $lain = Plan::create(['kategori' => PlanKategori::Ai, 'slug' => 'ai-claude', 'nama' => 'Claude', 'harga' => 11900, 'urutan' => -1]);
        $u = User::factory()->create();

        // beli token model lain: saldo model itu hanya yang dibeli, bonus ke layanan DeepSeek baru
        $s = Service::create(['user_id' => $u->id, 'plan_id' => $lain->id, 'label' => 'x', 'status' => ServiceStatus::MenungguPembayaran, 'harga' => 11900]);
        $inv = Invoice::create(['user_id' => $u->id, 'service_id' => $s->id, 'nomor' => Invoice::nomorBaru(), 'jumlah' => 119000, 'token_juta' => 10,
            'status' => InvoiceStatus::BelumDibayar, 'periode_mulai' => today(), 'periode_selesai' => today(), 'jatuh_tempo' => today()]);
        app(TandaiLunas::class)->handle($inv, 'qris');
        $this->assertSame(10_000_000, $s->fresh()->tokenAi->saldo);
        $this->assertSame(20_000_000, $this->saldoToken($u));

        // beli VPS padahal punya layanan Claude aktif: bonus tetap ke DeepSeek yang sama
        app(TandaiLunas::class)->handle($this->tagihan($u, PlanKategori::Vps, 114000), 'transfer');
        $this->assertSame(1, Service::where('plan_id', $this->ai->id)->count());
        $this->assertSame(40_000_000, $this->saldoToken($u));
        $this->assertSame(10_000_000, $s->fresh()->tokenAi->saldo);
    }

    public function test_vps_minimal_100rb_dapat_layanan_token_baru_dan_tercatat(): void
    {
        $p = $this->promo();
        $u = User::factory()->create();
        app(TandaiLunas::class)->handle($this->tagihan($u, PlanKategori::Vps, 114000), 'transfer');

        $token = Service::where('plan_id', $this->ai->id)->sole();
        $this->assertSame([ServiceStatus::Aktif, 20_000_000], [$token->status, $token->tokenAi->saldo]);
        $this->assertArrayHasKey('kredensial', $token->detail);
        $k = PromoKlaim::sole();
        $this->assertSame([$p->id, $u->id, $token->id, 20, 114000], [$k->promo_id, $k->user_id, $k->token_service_id, $k->token_juta, $k->nominal]);
        $n = $u->unreadNotifications()->sole();
        $this->assertSame(['bonus', '/layanan/'.$token->id], [$n->data['jenis'], $n->data['url']]);
        $this->assertStringContainsString('20.000.000 token', $n->data['judul']);

        // pembelian baru kedua masuk ke layanan token yang sama, tidak membuat layanan baru
        $app = $this->tagihan($u, PlanKategori::App, 110000);
        app(TandaiLunas::class)->handle($app, 'transfer');
        $this->assertSame(1, Service::where('plan_id', $this->ai->id)->count());
        $this->assertSame(40_000_000, $token->tokenAi->fresh()->saldo);

        // perpanjangan layanan yang sama tidak dapat bonus
        $panjang = $app->replicate(['kode_unik'])->fill(['nomor' => Invoice::nomorBaru(), 'status' => InvoiceStatus::BelumDibayar, 'dibayar_pada' => null]);
        $panjang->save();
        app(TandaiLunas::class)->handle($panjang, 'transfer');
        $this->assertSame(2, PromoKlaim::count());
        $this->assertSame(40_000_000, $token->tokenAi->fresh()->saldo);
    }

    public function test_beli_token_pertama_bonus_masuk_ke_kunci_yang_sama(): void
    {
        $this->promo();
        $u = User::factory()->create();
        app(TandaiLunas::class)->handle($this->tagihan($u, PlanKategori::Ai, 100000, 20), 'qris');

        $s = Service::sole();
        $this->assertSame(1, $s->tokenAi()->count());
        $this->assertSame(40_000_000, $s->tokenAi->saldo);
    }

    public function test_tidak_dapat_bonus_bila_tidak_memenuhi_syarat(): void
    {
        $u = User::factory()->create();
        $this->promo();
        $this->promo(['nama' => 'Kedaluwarsa', 'selesai' => now()->subMinute()]);
        $this->promo(['nama' => 'Belum mulai', 'mulai' => now()->addDay()]);
        $this->promo(['nama' => 'Mati', 'aktif' => false]);

        app(TandaiLunas::class)->handle($this->tagihan($u, PlanKategori::Vps, 99000), 'transfer'); // di bawah minimal
        app(TandaiLunas::class)->handle($this->tagihan($u, PlanKategori::Managed, 350000), 'transfer'); // kategori tidak ikut

        $this->assertSame(0, PromoKlaim::count());
        $this->assertSame(0, $this->saldoToken($u));
        $this->assertSame(0, $u->notifications()->count());
    }

    public function test_admin_atur_promo_dan_lihat_pemakaian(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post(route('admin.promo.store'), [
            'nama' => 'Gratis 20 jt', 'bonus_token_juta' => 20, 'min_pembelian' => 100000, 'kategori' => ['vps', 'app', 'ai'],
            'mulai' => null, 'selesai' => '2026-12-31T23:59', 'aktif' => true,
        ])->assertSessionHasNoErrors();
        $p = Promo::sole();
        $this->assertSame('2026-12-31 23:59', $p->selesai->format('Y-m-d H:i'));

        $this->actingAs($admin)->post(route('admin.promo.store'), ['nama' => 'x', 'bonus_token_juta' => 1, 'min_pembelian' => 0, 'kategori' => ['salah']])
            ->assertSessionHasErrors('kategori.0');

        $this->travelTo('2026-12-01 10:00');
        $u = User::factory()->create();
        app(TandaiLunas::class)->handle($this->tagihan($u, PlanKategori::Vps, 200000), 'transfer');
        app(TandaiLunas::class)->handle($this->tagihan($u, PlanKategori::App, 110000), 'transfer');

        $this->actingAs($admin)->get(route('admin.promo.index', ['promo' => $p->id]))->assertOk()
            ->assertInertia(fn ($pg) => $pg->component('admin/Promo')
                ->where('promo.0.klaim_count', 2)->where('promo.0.pelanggan', 1)->where('promo.0.token_total', 40)
                ->where('promo.0.omzet', 310000)->where('promo.0.status', 'berlaku')
                ->has('klaim', 2)->where('klaim.0.email', $u->email)->where('klaim.0.saldo_token', 40_000_000));

        $this->actingAs($admin)->delete(route('admin.promo.destroy', $p))->assertStatus(422); // sudah dipakai
        $this->actingAs($admin)->put(route('admin.promo.update', $p), [...$p->only('nama', 'bonus_token_juta', 'min_pembelian', 'kategori'), 'aktif' => false])
            ->assertSessionHasNoErrors();
        $this->assertFalse($p->fresh()->aktif);

        $this->actingAs($u)->get(route('admin.promo.index'))->assertForbidden();
    }

    public function test_katalog_menampilkan_promo_berlaku(): void
    {
        $this->promo(['selesai' => now()->addWeek()]);
        $this->promo(['nama' => 'Mati', 'aktif' => false]);
        $this->actingAs(User::factory()->create())->get(route('katalog.index', 'cloud'))->assertOk()
            ->assertInertia(fn ($pg) => $pg->has('promo', 1)->where('promo.0.nama', 'Gratis 20 jt'));
    }
}
