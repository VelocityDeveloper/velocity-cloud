<?php

namespace Tests\Feature;

use App\Actions\Order\BuatPesanan;
use App\Enums\InvoiceStatus;
use App\Enums\PlanKategori;
use App\Enums\ServiceStatus;
use App\Jobs\ProvisionPod;
use App\Models\Invoice;
use App\Models\Pengaturan;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Bayar online Midtrans Snap: halaman bayar + notifikasi → lunas otomatis → provisioning. */
class MidtransTest extends TestCase
{
    use RefreshDatabase;

    private const KUNCI = 'SB-Mid-server-uji';

    private User $user;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Pengaturan::simpan('midtrans.server_key', Crypt::encryptString(self::KUNCI));
        Pengaturan::simpan('midtrans.aktif', true);
        Queue::fake();
        $this->user = User::factory()->create();
        $plan = Plan::create(['kategori' => PlanKategori::App, 'slug' => 'app-n8n', 'nama' => 'n8n', 'harga' => 50000]);
        $this->invoice = app(BuatPesanan::class)->handle($this->user, $plan, 'n8n');
    }

    /** Notifikasi bertanda tangan sah; status API Midtrans dipalsukan sesuai $status. */
    private function notifikasi(string $status, ?int $jumlah = null, string $order = '', string $fraud = 'accept')
    {
        $order = $order ?: $this->invoice->nomor.'-261114090000';
        $kotor = number_format($jumlah ?? $this->invoice->total(), 2, '.', '');
        Http::fake(['api.sandbox.midtrans.com/v2/*' => Http::response(['order_id' => $order, 'status_code' => '200',
            'transaction_status' => $status, 'fraud_status' => $fraud, 'gross_amount' => $kotor, 'payment_type' => 'qris', 'transaction_id' => 'trx-1'])]);

        return $this->postJson('/midtrans/notifikasi', ['order_id' => $order, 'status_code' => '200', 'gross_amount' => $kotor,
            'transaction_status' => $status, 'signature_key' => hash('sha512', $order.'200'.$kotor.self::KUNCI)]);
    }

    public function test_tombol_bayar_membuat_transaksi_snap_dan_dipakai_ulang(): void
    {
        Http::fake(['app.sandbox.midtrans.com/snap/v1/transactions' => Http::response(['token' => 't', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/t'])]);

        $this->actingAs($this->user)->get(route('tagihan.show', $this->invoice))->assertInertia(fn ($p) => $p->where('midtrans', true));
        $this->actingAs($this->user)->post(route('tagihan.midtrans', $this->invoice), [], ['X-Inertia' => 'true'])
            ->assertStatus(409)->assertHeader('X-Inertia-Location', 'https://app.sandbox.midtrans.com/snap/v4/redirection/t');
        $this->actingAs($this->user)->post(route('tagihan.midtrans', $this->invoice), [], ['X-Inertia' => 'true'])->assertStatus(409);

        Http::assertSentCount(1); // klik kedua memakai transaksi yang sama
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Basic '.base64_encode(self::KUNCI.':'))
            && $r['transaction_details']['gross_amount'] === $this->invoice->total()
            && str_starts_with($r['transaction_details']['order_id'], $this->invoice->nomor.'-')
            && $r['customer_details']['email'] === $this->user->email);
    }

    public function test_tagihan_orang_lain_dan_tanpa_kunci_ditolak(): void
    {
        $this->actingAs(User::factory()->create())->post(route('tagihan.midtrans', $this->invoice))->assertNotFound();

        Pengaturan::simpan('midtrans.aktif', false);
        $this->actingAs($this->user)->get(route('tagihan.show', $this->invoice))->assertInertia(fn ($p) => $p->where('midtrans', false));
        $this->actingAs($this->user)->post(route('tagihan.midtrans', $this->invoice))->assertStatus(409);
    }

    public function test_admin_menyimpan_kunci_terenkripsi_dan_mode_harus_cocok(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $url = route('admin.pembayaran.midtrans');

        $this->actingAs($this->user)->post($url, ['server_key' => 'SB-Mid-server-x'])->assertForbidden();
        $this->actingAs($admin)->post($url, ['server_key' => 'SB-Mid-server-baru1234', 'produksi' => true, 'aktif' => true])
            ->assertSessionHasErrors('server_key');
        $this->assertSame(self::KUNCI, \App\Services\Pembayaran\Midtrans::kunci());

        $this->actingAs($admin)->post($url, ['server_key' => 'Mid-server-prod1234', 'produksi' => true, 'aktif' => true])->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('Mid-server-prod1234', Pengaturan::find('midtrans.server_key')->nilai); // terenkripsi di DB
        $this->actingAs($admin)->post($url, ['server_key' => '', 'produksi' => true, 'aktif' => false])->assertSessionHasNoErrors(); // kosong = kunci lama

        $this->actingAs($admin)->get(route('admin.pembayaran.index'))->assertInertia(fn ($p) => $p
            ->where('midtrans.kunci_akhir', '1234')->where('midtrans.produksi', true)->where('midtrans.aktif', false)
            ->where('midtrans.url_notifikasi', rtrim(config('app.url'), '/').'/midtrans/notifikasi'));
        $this->assertStringNotContainsString('Mid-server-prod1234', $this->actingAs($admin)->get(route('admin.pembayaran.index'))->getContent());
    }

    public function test_settlement_melunasi_tagihan_dan_menyiapkan_layanan_sekali(): void
    {
        $this->notifikasi('settlement')->assertOk();
        $this->notifikasi('settlement')->assertOk(); // Midtrans bisa mengirim ulang notifikasi

        $i = $this->invoice->fresh();
        $this->assertSame(InvoiceStatus::Lunas, $i->status);
        $this->assertSame('midtrans', $i->metode);
        $this->assertSame('qris trx-1', $i->referensi);
        $this->assertSame(ServiceStatus::Disiapkan, $i->service->status);
        Queue::assertPushed(ProvisionPod::class, 1);
    }

    public function test_membuka_tagihan_melunasi_bila_notifikasi_tidak_sampai(): void
    {
        $order = $this->invoice->nomor.'-261114090759';
        $this->invoice->forceFill(['midtrans' => ['order' => $order, 'url' => 'x', 'total' => $this->invoice->total(), 'sampai' => now()->addDay()->toIso8601String()]])->save();
        Http::fake(['api.sandbox.midtrans.com/v2/*' => Http::response(['order_id' => $order, 'transaction_status' => 'settlement',
            'gross_amount' => $this->invoice->total().'.00', 'payment_type' => 'bank_transfer', 'transaction_id' => 'trx-2'])]);

        $this->actingAs($this->user)->get(route('tagihan.show', $this->invoice))
            ->assertInertia(fn ($p) => $p->where('tagihan.status', 'lunas')->where('midtrans', false));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v2/'.$order.'/status'));
        Queue::assertPushed(ProvisionPod::class, 1);
    }

    public function test_tanda_tangan_palsu_ditolak_tanpa_memanggil_api(): void
    {
        Http::fake();
        $this->postJson('/midtrans/notifikasi', ['order_id' => $this->invoice->nomor.'-1', 'status_code' => '200',
            'gross_amount' => '50000.00', 'signature_key' => str_repeat('a', 128)])->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(InvoiceStatus::BelumDibayar, $this->invoice->fresh()->status);
    }

    public function test_pending_nominal_beda_dan_fraud_tidak_melunasi(): void
    {
        $this->notifikasi('pending')->assertOk();
        $this->notifikasi('settlement', jumlah: $this->invoice->total() - 1)->assertOk();
        $this->notifikasi('capture', fraud: 'challenge')->assertOk();
        $this->notifikasi('settlement', order: 'INV-000000-9999-1')->assertOk(); // notifikasi uji dashboard Midtrans

        $this->assertSame(InvoiceStatus::BelumDibayar, $this->invoice->fresh()->status);
        Queue::assertNotPushed(ProvisionPod::class);
    }
}
