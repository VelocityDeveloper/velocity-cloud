<?php

namespace Tests\Feature;

use App\Actions\Order\BuatPesanan;
use App\Enums\CheckoutStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PlanKategori;
use App\Http\Middleware\KlienCheckout;
use App\Jobs\KirimWebhookCheckout;
use App\Models\CheckoutKlien;
use App\Models\CheckoutSesi;
use App\Models\CheckoutWebhook;
use App\Models\Pengaturan;
use App\Models\Plan;
use App\Models\User;
use App\Services\Pembayaran\Checkout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/** VDC Pay: API sesi bayar untuk web lain → halaman bayar → Midtrans → webhook bertanda tangan ke klien. */
class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const KUNCI_MIDTRANS = 'SB-Mid-server-uji';

    private const WEBHOOK = 'https://app.velocitycybermedia.id/vdcpay/webhook';

    private CheckoutKlien $klien;

    private string $apiKey;

    private string $rahasia;

    /** Balasan palsu API status Midtrans: [isi, kode HTTP]. */
    private array $statusMidtrans = [[], 200];

    /** Kode HTTP balasan webhook klien berurutan; habis = 200. */
    private array $balasWebhook = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Satu fake untuk semua (Http::fake bertumpuk tidak saling menimpa): Snap, status Midtrans, webhook klien
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), '/snap/v1/transactions')) {
                return Http::response(['token' => 't', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/t']);
            }
            if (str_contains($r->url(), 'api.sandbox.midtrans.com/v2/')) {
                return Http::response(...$this->statusMidtrans);
            }
            $kode = array_shift($this->balasWebhook) ?? 200;

            return Http::response($kode === 200 ? ['ok' => true] : 'down', $kode);
        });
        Pengaturan::simpan('midtrans.server_key', Crypt::encryptString(self::KUNCI_MIDTRANS));
        Pengaturan::simpan('midtrans.aktif', true);
        $this->klien = CheckoutKlien::create(['nama' => 'Velocia', 'kode' => 'VCM', 'url_webhook' => self::WEBHOOK,
            'host_kembali' => ['app.velocitycybermedia.id']]);
        ['api_key' => $this->apiKey, 'rahasia_webhook' => $this->rahasia] = $this->klien->kunciBaru();
    }

    private function buat(array $ubah = [], ?string $kunci = null)
    {
        return $this->withToken($kunci ?? $this->apiKey)->postJson('/api/checkout/sessions', $ubah + [
            'ref' => 'ORD-1001', 'jumlah' => 99000, 'deskripsi' => 'Velocia Bisnis 1 bulan', 'email' => 'pembeli@contoh.id',
            'url_sukses' => 'https://app.velocitycybermedia.id/billing/sukses', 'url_batal' => 'https://app.velocitycybermedia.id/billing',
            'metadata' => ['tenant' => '5'],
        ]);
    }

    private function sesi(): CheckoutSesi
    {
        $this->buat()->assertCreated();

        return CheckoutSesi::firstOrFail();
    }

    /** Sesi sudah punya transaksi Snap $order. */
    private function sesiMenunggu(string $order = 'CS-VCM-1-261124100000'): CheckoutSesi
    {
        $s = $this->sesi();
        $s->forceFill(['status' => CheckoutStatus::Menunggu,
            'midtrans' => ['order' => $order, 'url' => 'https://snap/x', 'sampai' => now()->addHours(23)->toIso8601String()]])->save();

        return $s;
    }

    /** Notifikasi Midtrans bertanda tangan sah; API status dipalsukan. */
    private function notifikasi(string $order, string $status, int $jumlah = 99000, string $fraud = 'accept')
    {
        $kotor = number_format($jumlah, 2, '.', '');
        $this->statusMidtrans = [['order_id' => $order, 'status_code' => '200', 'transaction_status' => $status,
            'fraud_status' => $fraud, 'gross_amount' => $kotor, 'payment_type' => 'qris', 'transaction_id' => 'trx-9'], 200];

        return $this->postJson('/midtrans/notifikasi', ['order_id' => $order, 'status_code' => '200', 'gross_amount' => $kotor,
            'transaction_status' => $status, 'signature_key' => hash('sha512', $order.'200'.$kotor.self::KUNCI_MIDTRANS)]);
    }

    private function kirimanWebhook()
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (Request $r) => $r->url() === self::WEBHOOK)->values();
    }

    public function test_api_wajib_kunci_klien_aktif(): void
    {
        $this->postJson('/api/checkout/sessions', [])->assertUnauthorized();
        $this->buat(kunci: 'vdcpay_salah')->assertUnauthorized();

        $this->klien->update(['aktif' => false]);
        $this->buat()->assertUnauthorized();
        $this->assertSame(0, CheckoutSesi::count());
    }

    public function test_buat_sesi_idempoten_per_ref(): void
    {
        $r = $this->buat()->assertCreated()->assertJsonPath('status', 'dibuat')->assertJsonPath('jumlah', 99000)
            ->assertJsonPath('metadata.tenant', '5');
        $this->assertStringStartsWith('cs_', $r['id']);
        $this->assertSame(route('checkout.show', $r['id']), $r['url']);

        $this->buat()->assertOk()->assertJsonPath('id', $r['id']); // kiriman ulang (timeout jaringan) = sesi yang sama
        $this->buat(['jumlah' => 149000])->assertStatus(409)->assertJsonPath('id', $r['id']);
        $this->buat(['ref' => 'ORD-1002', 'url_sukses' => 'https://jahat.id/x'])->assertStatus(422)->assertJsonPath('kolom', 'url_sukses');
        $this->buat(['ref' => 'ORD-1002', 'url_sukses' => 'http://app.velocitycybermedia.id/x'])->assertStatus(422);
        $this->buat(['ref' => 'ORD 1002'])->assertStatus(422)->assertJsonValidationErrors('ref');

        Pengaturan::simpan('midtrans.aktif', false);
        $this->buat(['ref' => 'ORD-1003'])->assertStatus(503);
        $this->assertSame(1, CheckoutSesi::count());
    }

    public function test_status_hanya_untuk_klien_pemilik(): void
    {
        $id = $this->buat()->json('id');
        $lain = CheckoutKlien::create(['nama' => 'Lain', 'kode' => 'LN', 'url_webhook' => 'https://lain.id/w', 'host_kembali' => ['lain.id']]);

        $this->withToken($this->apiKey)->getJson("/api/checkout/sessions/$id")->assertOk()->assertJsonPath('ref', 'ORD-1001');
        $this->withToken($lain->kunciBaru()['api_key'])->getJson("/api/checkout/sessions/$id")->assertNotFound();
    }

    public function test_halaman_bayar_membuat_snap_berawalan_klien_dan_dipakai_ulang(): void
    {
        $s = $this->sesi();

        $this->get(route('checkout.show', $s))->assertOk()->assertInertia(fn ($p) => $p->component('checkout/Show')
            ->where('sesi.klien', 'Velocia')->where('sesi.jumlah', 99000)->where('bisa_bayar', true)->where('sesi.url_sukses', null));
        $this->post(route('checkout.bayar', $s), [], ['X-Inertia' => 'true'])
            ->assertStatus(409)->assertHeader('X-Inertia-Location', 'https://app.sandbox.midtrans.com/snap/v4/redirection/t');
        $this->post(route('checkout.bayar', $s), [], ['X-Inertia' => 'true'])->assertStatus(409);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => preg_match('/^CS-VCM-'.$s->id.'-\d{12}$/', $r['transaction_details']['order_id'])
            && $r['transaction_details']['gross_amount'] === 99000 && $r['customer_details']['email'] === 'pembeli@contoh.id'
            && str_ends_with($r['callbacks']['finish'], '/bayar/'.$s->uuid.'/selesai'));
        $this->assertSame(CheckoutStatus::Menunggu, $s->fresh()->status);
    }

    public function test_settlement_melunasi_dan_webhook_bertanda_tangan_dikirim_sekali(): void
    {
        $s = $this->sesiMenunggu();
        $this->freezeSecond();

        $this->notifikasi('CS-VCM-1-261124100000', 'settlement')->assertOk();
        $this->notifikasi('CS-VCM-1-261124100000', 'settlement')->assertOk(); // Midtrans mengirim ulang

        $s->refresh();
        $this->assertSame(CheckoutStatus::Lunas, $s->status);
        $this->assertSame('qris trx-9', $s->referensi);
        $this->assertSame(1, CheckoutWebhook::count());
        $w = CheckoutWebhook::first();
        $this->assertSame(['checkout.lunas', 'terkirim', 1], [$w->jenis, $w->status, $w->percobaan]);

        $kiriman = $this->kirimanWebhook();
        $this->assertCount(1, $kiriman);
        $r = $kiriman[0];
        // Verifikasi seperti yang harus dilakukan klien (docs/vdc-pay.md)
        parse_str(str_replace(',', '&', $r->header('VDC-Signature')[0]), $sig);
        $this->assertSame(now()->timestamp, (int) $sig['t']);
        $this->assertTrue(hash_equals(hash_hmac('sha256', $sig['t'].'.'.$r->body(), $this->rahasia), $sig['v1']));
        $this->assertSame($w->event_id, $r->header('VDC-Event-Id')[0]);
        $isi = json_decode($r->body(), true);
        $this->assertSame(['checkout.lunas', 'ORD-1001', 'lunas', 'qris', '5'],
            [$isi['jenis'], $isi['data']['ref'], $isi['data']['status'], $isi['data']['metode'], $isi['data']['metadata']['tenant']]);

        $this->get(route('checkout.selesai', $s))->assertRedirect('https://app.velocitycybermedia.id/billing/sukses?checkout_id='.$s->uuid);
    }

    public function test_status_gagal_ikut_dikirim_dan_transaksi_lama_dibayar_belakangan_tetap_lunas(): void
    {
        Queue::fake();
        $s = $this->sesiMenunggu('CS-VCM-1-261124100000');

        $this->notifikasi('CS-VCM-1-261124100000', 'expire')->assertOk();
        $this->assertSame(CheckoutStatus::Kedaluwarsa, $s->fresh()->status);
        $this->notifikasi('CS-VCM-1-261124100000', 'settlement')->assertOk();
        $this->assertSame(CheckoutStatus::Lunas, $s->fresh()->status);
        $this->notifikasi('CS-VCM-1-261124100000', 'expire')->assertOk(); // tidak boleh menimpa lunas
        $this->notifikasi('CS-VCM-1-261124100000', 'refund')->assertOk();

        $this->assertSame(CheckoutStatus::Refund, $s->fresh()->status);
        $this->assertSame(['checkout.kedaluwarsa', 'checkout.lunas', 'checkout.refund'], CheckoutWebhook::orderBy('id')->pluck('jenis')->all());
        Queue::assertPushed(KirimWebhookCheckout::class, 3);
    }

    public function test_batal_ditolak_nominal_beda_dan_order_lama(): void
    {
        Queue::fake();
        $s = $this->sesiMenunggu('CS-VCM-1-261124110000');

        $this->notifikasi('CS-VCM-1-261124100000', 'expire')->assertOk(); // transaksi lama; yang baru masih berjalan
        $this->notifikasi('CS-VCM-1-261124110000', 'settlement', jumlah: 98999)->assertOk();
        $this->notifikasi('CS-VCM-1-261124110000', 'capture', fraud: 'challenge')->assertOk();
        $this->notifikasi('CS-XXX-1-261124110000', 'settlement')->assertOk(); // kode klien tidak cocok
        $this->assertSame(CheckoutStatus::Menunggu, $s->fresh()->status);

        $this->notifikasi('CS-VCM-1-261124110000', 'deny')->assertOk();
        $this->assertSame(CheckoutStatus::Ditolak, $s->fresh()->status);
        $this->assertSame(['checkout.ditolak'], CheckoutWebhook::pluck('jenis')->all());
    }

    public function test_bayar_dobel_dan_refund_transaksi_lain_tidak_mencabut_lunas(): void
    {
        Queue::fake();
        $s = $this->sesiMenunggu('CS-VCM-1-261124110000');

        $this->notifikasi('CS-VCM-1-261124100000', 'settlement')->assertOk(); // T1 (VA lama) dibayar
        $this->notifikasi('CS-VCM-1-261124110000', 'settlement')->assertOk(); // T2 juga dibayar: dobel, dicatat saja
        $this->assertSame('CS-VCM-1-261124100000', $s->fresh()->order_lunas);
        $this->notifikasi('CS-VCM-1-261124110000', 'refund')->assertOk(); // admin me-refund T2

        $this->assertSame(CheckoutStatus::Lunas, $s->fresh()->status);
        $this->assertSame(['checkout.lunas'], CheckoutWebhook::pluck('jenis')->all());

        $this->notifikasi('CS-VCM-1-261124100000', 'refund')->assertOk(); // refund pelunas yang sebenarnya
        $this->assertSame(CheckoutStatus::Refund, $s->fresh()->status);
    }

    public function test_snap_tidak_memundurkan_sesi_yang_lunas_bersamaan(): void
    {
        Queue::fake();
        $s = $this->sesi();
        $s->forceFill(['status' => CheckoutStatus::Lunas])->save(); // lunas masuk sesudah controller mengecek

        $url = app(Checkout::class)->urlBayar($s);

        $this->assertSame(route('checkout.show', $s), $url);
        $this->assertSame(CheckoutStatus::Lunas, $s->fresh()->status);
    }

    public function test_penjadwal_mengirim_ulang_event_yang_job_nya_hilang_tanpa_menggandakan(): void
    {
        Queue::fake();
        $s = $this->sesiMenunggu();
        $this->notifikasi('CS-VCM-1-261124100000', 'settlement'); // job masih di antrean (worker mati)
        $hilang = CheckoutWebhook::create(['event_id' => 'evt_hilang', 'checkout_sesi_id' => $s->id, 'jenis' => 'checkout.refund',
            'payload' => []]); // tercatat tapi tidak pernah di-dispatch
        CheckoutWebhook::query()->update(['created_at' => now()->subMinutes(11)]);

        $this->artisan('checkout:tutup')->assertSuccessful();
        $this->artisan('checkout:tutup')->assertSuccessful();

        Queue::assertPushed(KirimWebhookCheckout::class, 2); // dispatch awal + satu untuk event yang hilang
        Queue::assertPushed(KirimWebhookCheckout::class, fn ($j) => $j->webhook->is($hilang));
    }

    public function test_refund_sebagian_tidak_mencabut_lunas_dan_transaksi_menggantung_ditutup(): void
    {
        Queue::fake();
        $s = $this->sesiMenunggu();
        $this->notifikasi('CS-VCM-1-261124100000', 'settlement');
        $this->notifikasi('CS-VCM-1-261124100000', 'partial_refund')->assertOk();
        $this->assertSame(CheckoutStatus::Lunas, $s->fresh()->status);

        $this->buat(['ref' => 'ORD-9'])->assertCreated();
        $beda = CheckoutSesi::firstWhere('ref', 'ORD-9'); // dibayar dengan nominal beda, Snap sudah lewat masa berlaku
        $beda->forceFill(['status' => CheckoutStatus::Menunggu, 'berlaku_sampai' => now()->subDay(),
            'midtrans' => ['order' => 'CS-VCM-'.$beda->id.'-261123100000', 'url' => 'x', 'sampai' => now()->subHour()->toIso8601String()]])->save();
        $this->statusMidtrans = [['status_code' => '200', 'transaction_status' => 'settlement', 'gross_amount' => '1000.00'], 200];

        $this->artisan('checkout:tutup')->expectsOutput('1 sesi ditutup.')->assertSuccessful();
        $this->assertSame(CheckoutStatus::Kedaluwarsa, $beda->fresh()->status);
    }

    public function test_api_ref_bersamaan_jumlah_string_dan_batas_kunci_salah(): void
    {
        $this->buat(['jumlah' => '99000'])->assertCreated();
        $this->buat(['jumlah' => '99000'])->assertOk(); // bukan 409 palsu

        foreach (range(1, KlienCheckout::BATAS_SALAH) as $_) {
            $this->buat([], 'vdcpay_salah')->assertUnauthorized();
        }
        $this->buat([], 'vdcpay_salah')->assertStatus(429);
    }

    public function test_tagihan_vdc_tetap_diproses_seperti_biasa(): void
    {
        Queue::fake();
        $s = $this->sesiMenunggu();
        $user = User::factory()->create();
        $invoice = app(BuatPesanan::class)->handle($user, Plan::create(['kategori' => PlanKategori::App, 'slug' => 'app-n8n', 'nama' => 'n8n', 'harga' => 50000]), 'n8n');

        $this->notifikasi($invoice->nomor.'-261124090000', 'settlement', jumlah: $invoice->total())->assertOk();

        $this->assertSame(InvoiceStatus::Lunas, $invoice->fresh()->status);
        $this->assertSame(CheckoutStatus::Menunggu, $s->fresh()->status);
        $this->assertSame(0, CheckoutWebhook::count());
    }

    public function test_webhook_gagal_diulang_lalu_terkirim_atau_ditandai_gagal(): void
    {
        Queue::fake();
        $s = $this->sesiMenunggu();
        $this->notifikasi('CS-VCM-1-261124100000', 'settlement');
        $w = CheckoutWebhook::firstOrFail();

        $this->balasWebhook = [500];
        try {
            (new KirimWebhookCheckout($w))->handle();
            $this->fail('harus melempar agar antrean mengulang');
        } catch (RuntimeException) {
        }
        $w->refresh();
        $this->assertSame(['antri', 1, 500, 'down'], [$w->status, $w->percobaan, $w->kode_respons, $w->galat]);
        $this->assertSame([60, 300, 900, 3600, 10800, 21600, 43200], (new KirimWebhookCheckout($w))->backoff());

        (new KirimWebhookCheckout($w))->handle(); // percobaan berikutnya berhasil
        $w->refresh();
        $this->assertSame(['terkirim', 2, null], [$w->status, $w->percobaan, $w->galat]);
        (new KirimWebhookCheckout($w))->handle(); // sudah terkirim: tidak dikirim lagi
        $this->assertCount(2, $this->kirimanWebhook());

        (new KirimWebhookCheckout($w))->failed(new RuntimeException('x')); // job ganda gagal: tidak menimpa terkirim
        $this->assertSame('terkirim', $w->fresh()->status);

        // Habis percobaan → gagal → admin kirim ulang (yang masih antri tidak boleh dikirim ulang manual)
        $w->update(['status' => 'antri']);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->post(route('admin.checkout-webhook.ulang', $w))->assertRedirect();
        Queue::assertPushed(KirimWebhookCheckout::class, 1);
        (new KirimWebhookCheckout($w))->failed(new RuntimeException('x'));
        $this->assertSame('gagal', $w->fresh()->status);
        $this->actingAs($admin)->post(route('admin.checkout-webhook.ulang', $w))->assertRedirect();
        $this->assertSame('antri', $w->fresh()->status);
        Queue::assertPushed(KirimWebhookCheckout::class, 2); // kunci unik dilepas admin walau dispatch awal masih memegangnya
        $this->assertSame(CheckoutStatus::Lunas, $s->fresh()->status);
    }

    public function test_penjadwal_menutup_sesi_kedaluwarsa(): void
    {
        Queue::fake();
        $belum = $this->sesi(); // tidak pernah klik bayar
        $belum->update(['berlaku_sampai' => now()->subMinute()]);
        $this->buat(['ref' => 'ORD-2'])->assertCreated();
        $snapHilang = CheckoutSesi::firstWhere('ref', 'ORD-2'); // Snap dibuka, metode bayar tidak dipilih
        $snapHilang->forceFill(['status' => CheckoutStatus::Menunggu, 'berlaku_sampai' => now()->subDay(),
            'midtrans' => ['order' => 'CS-VCM-'.$snapHilang->id.'-261123100000', 'url' => 'x', 'sampai' => now()->subHour()->toIso8601String()]])->save();
        $this->buat(['ref' => 'ORD-3'])->assertCreated();
        $vaBerjalan = CheckoutSesi::firstWhere('ref', 'ORD-3'); // VA masih berlaku walau batas mulai bayar lewat
        $vaBerjalan->forceFill(['status' => CheckoutStatus::Menunggu, 'berlaku_sampai' => now()->subMinute(),
            'midtrans' => ['order' => 'CS-VCM-'.$vaBerjalan->id.'-261124100000', 'url' => 'x', 'sampai' => now()->addHours(5)->toIso8601String()]])->save();
        $this->statusMidtrans = [['status_code' => '404', 'status_message' => "Transaction doesn't exist."], 404];

        $this->artisan('checkout:tutup')->expectsOutput('2 sesi ditutup.')->assertSuccessful();

        $this->assertSame(CheckoutStatus::Kedaluwarsa, $belum->fresh()->status);
        $this->assertSame(CheckoutStatus::Kedaluwarsa, $snapHilang->fresh()->status);
        $this->assertSame(CheckoutStatus::Menunggu, $vaBerjalan->fresh()->status);
        $this->assertSame(2, CheckoutWebhook::where('jenis', 'checkout.kedaluwarsa')->count());
        $this->get(route('checkout.show', $belum))->assertInertia(fn ($p) => $p->where('bisa_bayar', false));
        $this->post(route('checkout.bayar', $belum))->assertStatus(409);
    }

    public function test_admin_kelola_klien_dan_kunci_tampil_sekali(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $data = ['nama' => 'Toko', 'kode' => 'TK1', 'url_webhook' => 'https://toko.id/w', 'host_kembali' => "toko.id\nWWW.toko.id", 'aktif' => true];

        $this->actingAs(User::factory()->create())->post(route('admin.checkout-klien.store'), $data)->assertForbidden();
        $this->actingAs($admin)->post(route('admin.checkout-klien.store'), ['kode' => 'inv-1'] + $data)->assertSessionHasErrors('kode');
        $this->actingAs($admin)->post(route('admin.checkout-klien.store'), ['url_webhook' => 'http://toko.id/w'] + $data)->assertSessionHasErrors('url_webhook');
        $this->actingAs($admin)->post(route('admin.checkout-klien.store'), $data)->assertSessionHasNoErrors()
            ->assertInertiaFlash('kunci_klien.klien', 'Toko');

        $k = CheckoutKlien::firstWhere('kode', 'TK1');
        $this->assertSame(['toko.id', 'www.toko.id'], $k->host_kembali);
        $this->assertNotNull($k->kunci_hash);
        $this->assertStringNotContainsString('whsec_', $k->getRawOriginal('rahasia_webhook')); // terenkripsi
        $this->actingAs($admin)->put(route('admin.checkout-klien.update', $k), ['kode' => 'XX'] + $data)->assertSessionHasErrors('kode');

        $halaman = $this->actingAs($admin)->get(route('admin.pembayaran.index'));
        $halaman->assertInertia(fn ($p) => $p->has('vdcpay.klien', 2)->where('vdcpay.klien.0.kode', 'TK1'));
        $this->assertStringNotContainsString($this->apiKey, $halaman->getContent());
        $this->assertStringNotContainsString($this->rahasia, $halaman->getContent());
    }
}
