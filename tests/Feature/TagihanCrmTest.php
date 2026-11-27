<?php

namespace Tests\Feature;

use App\Actions\Billing\TandaiLunas;
use App\Actions\Order\BuatPesanan;
use App\Enums\InvoiceStatus;
use App\Enums\PlanKategori;
use App\Jobs\KirimTagihanCrm;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use App\Services\Crm\TagihanCrm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Tagihan lunas Midtrans → billing + bank midtrans_vd CRM (POST vdc-tagihan). */
class TagihanCrmTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cloud.crm.url' => 'http://crm.test/api/public', 'cloud.crm.key' => 'kunci-uji', 'cloud.crm.kirim_tagihan' => true]);
        $this->user = User::factory()->create(['name' => 'Budi', 'email' => 'budi@contoh.id', 'whatsapp' => '08123']);
        $plan = Plan::create(['kategori' => PlanKategori::App, 'slug' => 'app-n8n', 'nama' => 'n8n', 'harga' => 50000]);
        $this->invoice = app(BuatPesanan::class)->handle($this->user, $plan, 'n8n');
    }

    private function lunasMidtrans(Invoice $invoice): Invoice
    {
        app(TandaiLunas::class)->handle($invoice, 'midtrans', 'qris trx-1');

        return $invoice->fresh();
    }

    private array $jawaban = [];

    /** Jawaban CRM bisa diganti di tengah tes (Http::fake berikutnya tidak menimpa yang pertama). */
    private function crmMenjawab(int $status = 201, array $body = ['billing_id' => 11, 'bank_id' => 22, 'customer_id' => 33, 'baru' => true]): void
    {
        $sudah = $this->jawaban !== [];
        $this->jawaban = [$status, $body];
        if (! $sudah) {
            Http::fake(['crm.test/api/public/vdc-tagihan' => fn () => Http::response($this->jawaban[1], $this->jawaban[0])]);
        }
    }

    public function test_lunas_midtrans_dikirim_dengan_isi_sesuai_kontrak(): void
    {
        $this->crmMenjawab();
        $invoice = $this->lunasMidtrans($this->invoice);

        $this->assertSame(11, $invoice->crm_billing_id);
        $this->assertSame(22, $invoice->crm_bank_id);
        $this->assertNotNull($invoice->crm_terkirim_pada);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer kunci-uji')
            && $r['referensi'] === $invoice->nomor
            && $r['sumber'] === 'app.velocitydatacenter.com'
            && $r['total'] === $invoice->total() && $r['jumlah'] === 50000 && $r['kode_unik'] === $invoice->kode_unik
            && $r['midtrans'] === 'qris trx-1' && $r['midtrans_mode'] === 'production'
            && $r['kategori'] === 'app' && $r['jenis'] === 'baru' && $r['paket'] === 'n8n'
            && $r['nama_web'] === $invoice->service->subdomain.'.'.config('cloud.domain_app')
            && $r['customer'] === ['nama' => 'Budi', 'email' => 'budi@contoh.id', 'hp' => '08123']);

        // job diulang (retry/kirim ulang) setelah berhasil tidak mengirim lagi
        KirimTagihanCrm::dispatchSync($invoice);
        Http::assertSentCount(1);
    }

    public function test_perpanjangan_ditandai_dan_custom_domain_jadi_nama_web(): void
    {
        $this->crmMenjawab();
        $this->lunasMidtrans($this->invoice);
        $service = $this->invoice->service->fresh();
        $service->update(['custom_domain' => 'toko.contoh.id']);
        $kedua = Invoice::create(['user_id' => $this->user->id, 'service_id' => $service->id, 'nomor' => 'INV-UJI-2', 'jumlah' => 50000,
            'status' => InvoiceStatus::BelumDibayar, 'periode_mulai' => now(), 'periode_selesai' => now()->addMonth(), 'jatuh_tempo' => now()]);
        $this->lunasMidtrans($kedua);

        Http::assertSent(fn (Request $r) => $r['referensi'] === 'INV-UJI-2' && $r['jenis'] === 'perpanjangan' && $r['nama_web'] === 'toko.contoh.id');
    }

    public function test_token_ai_pelanggan_berbeda_tidak_berbagi_nama_web(): void
    {
        $this->crmMenjawab();
        $plan = Plan::create(['kategori' => PlanKategori::Ai, 'slug' => 'deepseek', 'nama' => 'DeepSeek V4.1 Flash', 'harga' => 25000]);
        $lain = User::factory()->create();
        $a = $this->lunasMidtrans(app(BuatPesanan::class)->handle($this->user, $plan, 'DeepSeek V4.1 Flash', 1));
        $b = $this->lunasMidtrans(app(BuatPesanan::class)->handle($lain, $plan, 'DeepSeek V4.1 Flash', 1));

        $this->assertNotSame($a->service_id, $b->service_id);
        foreach ([$a, $b] as $i) {
            Http::assertSent(fn (Request $r) => $r['referensi'] === $i->nomor && $r['kategori'] === 'ai'
                && $r['nama_web'] === 'vdc-ai-'.$i->service_id && $r['paket'] === 'DeepSeek V4.1 Flash 1 juta token');
        }
    }

    public function test_bukan_midtrans_atau_saklar_mati_tidak_dikirim(): void
    {
        Queue::fake();
        app(TandaiLunas::class)->handle($this->invoice, 'transfer', 'BCA');
        Queue::assertNotPushed(KirimTagihanCrm::class);

        config(['cloud.crm.kirim_tagihan' => false]);
        $plan = Plan::where('slug', 'app-n8n')->first();
        $lain = app(BuatPesanan::class)->handle($this->user, $plan, 'n8n 2');
        app(TandaiLunas::class)->handle($lain, 'midtrans', 'qris trx-2');
        Queue::assertNotPushed(KirimTagihanCrm::class);
    }

    public function test_tagihan_midtrans_sandbox_tidak_dikirim(): void
    {
        $this->crmMenjawab();
        // dibayar lewat Snap sandbox (penanda mode disimpan saat transaksi dibuat)
        $this->invoice->forceFill(['midtrans' => ['order' => 'x', 'mode' => 'sandbox']])->save();
        $invoice = $this->lunasMidtrans($this->invoice);
        $this->assertNull($invoice->crm_bank_id);

        // tagihan lama tanpa penanda: dibayar sebelum VDC pindah ke Midtrans produksi = sandbox
        $lama = app(BuatPesanan::class)->handle($this->user, Plan::where('slug', 'app-n8n')->first(), 'n8n lama');
        $lama->forceFill(['status' => InvoiceStatus::Lunas, 'metode' => 'midtrans', 'referensi' => 'qris lama',
            'dibayar_pada' => '2026-11-14 09:12:13'])->save();
        $this->artisan('crm:kirim-tagihan', ['--no-interaction' => true])->expectsOutputToContain('0 tagihan')->assertSuccessful();
        $this->assertFalse(TagihanCrm::perluDikirim($lama));

        $lama->forceFill(['dibayar_pada' => '2026-11-23 11:20:40'])->save(); // sesudah pindah produksi
        $this->assertSame('production', TagihanCrm::mode($lama->fresh()));
        Http::assertNothingSent();
    }

    public function test_ditolak_crm_dicatat_tanpa_diulang_dan_admin_bisa_kirim_ulang(): void
    {
        $this->crmMenjawab(422, ['message' => 'Kategori tidak didukung.']);
        $invoice = $this->lunasMidtrans($this->invoice);
        $this->assertNull($invoice->crm_bank_id);
        $this->assertStringContainsString('Kategori tidak didukung', $invoice->crm_galat);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.tagihan.index'))->assertInertia(fn ($p) => $p
            ->where('tagihan.0.crm.terkirim', false)->where('tagihan.0.crm.galat', $invoice->crm_galat));

        $this->crmMenjawab(200, ['billing_id' => 11, 'bank_id' => 22, 'customer_id' => 33, 'baru' => false]);
        $this->actingAs($admin)->post(route('admin.tagihan.crm', $invoice))->assertRedirect();
        $invoice->refresh();
        $this->assertSame(22, $invoice->crm_bank_id);
        $this->assertNull($invoice->crm_galat);
        $this->actingAs($admin)->post(route('admin.tagihan.crm', $invoice))->assertStatus(422); // sudah terkirim

        $this->actingAs($this->user)->post(route('admin.tagihan.crm', $invoice))->assertForbidden();
    }

    public function test_backfill_uji_tidak_mengirim_lalu_kirim_sungguhan(): void
    {
        config(['cloud.crm.kirim_tagihan' => false]); // tagihan lama: lunas sebelum fitur ada
        $invoice = $this->lunasMidtrans($this->invoice);
        $this->crmMenjawab();

        $this->artisan('crm:kirim-tagihan', ['--sejak' => now()->toDateString(), '--uji' => true])
            ->expectsOutputToContain($invoice->nomor)->assertSuccessful();
        Http::assertNothingSent();

        $this->artisan('crm:kirim-tagihan', ['--sejak' => now()->addDay()->toDateString(), '--no-interaction' => true])
            ->expectsOutputToContain('0 tagihan')->assertSuccessful();
        Http::assertNothingSent();

        $this->artisan('crm:kirim-tagihan', ['--no-interaction' => true])->assertSuccessful();
        $this->assertSame(22, $invoice->fresh()->crm_bank_id);
        Http::assertSentCount(1);
    }
}
