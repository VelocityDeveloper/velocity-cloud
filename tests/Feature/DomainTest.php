<?php

namespace Tests\Feature;

use App\Actions\Billing\TandaiLunas;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Jobs\KirimDomainCrm;
use App\Models\Invoice;
use App\Models\Pengaturan;
use App\Models\Service;
use App\Models\User;
use App\Services\Chat\AlatLayanan;
use App\Services\Crm\DomainCrm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DomainTest extends TestCase
{
    use RefreshDatabase;

    private const KONTAK = ['nama' => 'Budi', 'email' => 'budi@contoh.com', 'hp' => '081234567890', 'alamat' => 'Jl. Mawar 1', 'kota' => 'Sleman', 'provinsi' => 'DIY', 'kode_pos' => '55281'];

    /** Jawaban CRM berikutnya: [body, status] per endpoint (stub Http::fake pertama selalu menang, jadi satu stub membaca ini). */
    private array $crm = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['cloud.crm.url' => 'http://crm.test/api', 'cloud.crm.key' => 'kunci', 'cloud.crm.customer_url' => 'http://crm.test/cust', 'cloud.crm.customer_key' => 'kunci-cust']);
        Pengaturan::simpan('domain.tld_aktif', ['.com']);
        // jangan pernah menulis data landing asli / memicu build landing dari tes
        config(['cloud.katalog_dir' => $dir = sys_get_temp_dir().'/vc-uji-domain-'.uniqid()]);
        mkdir($dir);
        Http::fake(fn (Request $r) => Http::response(...$this->crm[match (true) {
            str_contains($r->url(), 'domain-order') => 'order',
            str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/domains') => 'domains',
            str_contains($r->url(), '/nameservers') => $r->method() === 'PUT' ? 'ubah_ns' : 'ns',
            str_contains($r->url(), 'domain-tld') => 'tld',
            default => 'harga',
        }] ?? [[], 500]));
    }

    private function hargaCrm(bool $tersedia = true, bool $tercatat = false): void
    {
        $this->crm['harga'] = [[
            'domain' => 'tokobudi.com', 'tld' => '.com', 'tersedia' => $tersedia, 'pesan' => 'ok', 'tercatat' => $tercatat,
            'harga' => [['tahun' => 1, 'register' => 200000, 'transfer' => 180000], ['tahun' => 2, 'register' => 400000, 'transfer' => null]],
        ], 200];
    }

    private function pesan(array $isi = [])
    {
        return $this->actingAs(User::factory()->create())->post(route('domain.store'), $isi + ['domain' => 'TokoBudi.com', 'jenis' => 'Register', 'periode' => 1, 'kontak' => self::KONTAK]);
    }

    public function test_cek_meneruskan_harga_crm_dengan_kunci_dan_404_bila_tak_dijual(): void
    {
        $this->hargaCrm();
        $this->actingAs(User::factory()->create())->getJson(route('domain.cek', ['domain' => 'https://www.TokoBudi.com/']))
            ->assertOk()->assertJsonPath('harga.0.register', 200000);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer kunci') && $r['domain'] === 'tokobudi.com');

        $this->crm['harga'] = [['message' => 'x'], 404];
        $this->getJson(route('domain.cek', ['domain' => 'tokobudi.zzz']))->assertNotFound();
    }

    public function test_pesan_register_harga_dari_crm_bukan_browser(): void
    {
        $this->hargaCrm();
        $this->pesan(['harga' => 1])->assertRedirect();

        $service = Service::sole();
        $this->assertSame(ServiceStatus::MenungguPembayaran, $service->status);
        $this->assertSame(['tokobudi.com', 'Register', 1], [$service->detail['domain'], $service->detail['jenis'], $service->detail['periode']]);
        $this->assertSame(200000, Invoice::sole()->jumlah);
    }

    public function test_pesan_ditolak_bila_domain_terpakai_periode_tak_dijual_atau_tercatat(): void
    {
        $this->hargaCrm(tersedia: false);
        $this->pesan()->assertSessionHasErrors('domain');
        $this->pesan(['jenis' => 'Transfer', 'periode' => 2, 'epp' => 'X'])->assertSessionHasErrors('domain'); // transfer 2 th tak dijual
        $this->pesan(['jenis' => 'Transfer'])->assertSessionHasErrors('epp');

        $this->hargaCrm(tercatat: true);
        $this->pesan()->assertSessionHasErrors('domain');
        $this->assertSame(0, Service::count());
    }

    public function test_lunas_mengirim_ke_crm_tanpa_jatuh_tempo_dan_aktif(): void
    {
        Queue::fake();
        $this->hargaCrm(tersedia: false);
        $this->pesan(['jenis' => 'Transfer', 'epp' => 'EPP-123'])->assertRedirect();
        $invoice = Invoice::sole();
        app(TandaiLunas::class)->handle($invoice, 'midtrans');

        $service = Service::sole();
        $this->assertSame(ServiceStatus::Disiapkan, $service->status);
        $this->assertNull($service->jatuh_tempo);
        Queue::assertPushed(KirimDomainCrm::class);

        $this->crm['order'] = [[
            'order' => 'ORD2611250001', 'invoice' => 'VDI-1', 'customer_id' => 9,
            'domain' => ['domain' => 'tokobudi.com', 'status' => 'Active', 'registrar_status' => 'terkirim', 'expiry_date' => '2027-11-25'],
            'registrasi' => ['ok' => true, 'message' => 'OK'],
        ], 201];
        (new KirimDomainCrm($service))->handle(app(DomainCrm::class));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'domain-order') && $r['referensi'] === $invoice->nomor && $r['epp_code'] === 'EPP-123' && $r['harga'] === 180000
            && $r['type'] === 'Transfer' && $r['sumber'] === 'app.velocitydatacenter.com' && $r['kontak']['kota'] === 'Sleman');
        $service->refresh();
        $this->assertSame(ServiceStatus::Aktif, $service->status);
        $this->assertSame('ORD2611250001', $service->detail['crm']['order']);
        $this->assertStringNotContainsString('EPP-123', json_encode($service->detail)); // EPP tersimpan terenkripsi
    }

    public function test_registrar_gagal_atau_crm_menolak_jadi_gagal(): void
    {
        $service = Service::create([
            'user_id' => User::factory()->create()->id,
            'plan_id' => \App\Models\Plan::create(['kategori' => 'domain', 'slug' => 'domain', 'nama' => 'Domain', 'harga' => 0, 'aktif' => false])->id,
            'label' => 'tokobudi.com', 'status' => ServiceStatus::Disiapkan, 'harga' => 200000,
            'detail' => ['domain' => 'tokobudi.com', 'jenis' => 'Register', 'periode' => 1, 'kontak' => self::KONTAK],
        ]);
        Invoice::create(['user_id' => $service->user_id, 'service_id' => $service->id, 'nomor' => 'INV-X', 'jumlah' => 200000, 'status' => InvoiceStatus::Lunas,
            'periode_mulai' => today(), 'periode_selesai' => today()->addYear(), 'jatuh_tempo' => today()]);

        $this->crm['order'] = [['order' => 'ORD1', 'invoice' => 'VDI-1', 'domain' => ['registrar_pesan' => 'saldo kurang'], 'registrasi' => ['ok' => false, 'message' => 'Insufficient Deposit']], 201];
        (new KirimDomainCrm($service))->handle(app(DomainCrm::class));
        $this->assertSame(ServiceStatus::Gagal, $service->fresh()->status);
        $this->assertStringContainsString('Insufficient Deposit', $service->fresh()->pesan_gagal);

        $service->refresh()->update(['status' => ServiceStatus::Disiapkan, 'pesan_gagal' => null]);
        $this->crm['order'] = [['message' => 'The given data was invalid.', 'errors' => ['items.0.domain' => ['Domain sudah tercatat']]], 422];
        $job = (new KirimDomainCrm($service))->withFakeQueueInteractions();
        $job->handle(app(DomainCrm::class));
        $job->assertFailed();
    }

    public function test_tld_dimatikan_admin_tidak_bisa_dicek_maupun_dipesan(): void
    {
        $this->hargaCrm();
        Pengaturan::simpan('domain.tld_aktif', ['.id']);

        $this->actingAs(User::factory()->create())->getJson(route('domain.cek', ['domain' => 'tokobudi.com']))->assertNotFound();
        $this->pesan()->assertSessionHasErrors('domain');
        $this->assertSame(0, Service::count());
    }

    public function test_admin_paket_domain_menampilkan_tld_crm_dan_menyimpan_pilihan(): void
    {
        $this->withoutVite(); // halaman baru belum ada di manifest sebelum build
        $admin = User::factory()->create(['is_admin' => true]);
        $this->crm['tld'] = [[
            ['tld' => '.com', 'registrar' => 'liquid', 'harga' => [['tahun' => 1, 'register' => 200000, 'transfer' => 180000]]],
            ['tld' => '.id', 'registrar' => 'srsx', 'harga' => [['tahun' => 1, 'register' => 250000, 'transfer' => null]]],
        ], 200];

        $this->actingAs($admin)->get('/admin/paket/domain')->assertOk()
            ->assertInertia(fn ($p) => $p->component('admin/PaketDomain')->where('tld.0.aktif', true)->where('tld.1.aktif', false)->where('tidak_di_crm', []));

        Queue::fake();
        $this->actingAs($admin)->put('/admin/paket/domain', ['tld' => ['.com', '.id']])->assertRedirect();
        Queue::assertPushed(\App\Jobs\DeployLanding::class);
        $landing = json_decode(file_get_contents(config('cloud.katalog_dir').'/domain.json'), true);
        $this->assertSame([['tld' => '.com', 'tahun' => 1, 'register' => 200000, 'transfer' => 180000], ['tld' => '.id', 'tahun' => 1, 'register' => 250000, 'transfer' => null]], $landing);
        $this->assertSame(['.com', '.id'], Pengaturan::tldDomain());
        $this->actingAs($admin)->put('/admin/paket/domain', ['tld' => []])->assertRedirect();
        $this->assertSame([], Pengaturan::tldDomain());
        $this->assertSame("[]\n", file_get_contents(config('cloud.katalog_dir').'/domain.json'));
        $this->assertFalse(\App\Console\Commands\DomainLanding::tulis([])); // sama = tidak build ulang

        $this->actingAs(User::factory()->create())->put('/admin/paket/domain', ['tld' => ['.com']])->assertForbidden();
    }

    public function test_tld_tanpa_registrar_tetap_disiapkan_untuk_tim(): void
    {
        $service = Service::create([
            'user_id' => User::factory()->create()->id,
            'plan_id' => \App\Models\Plan::create(['kategori' => 'domain', 'slug' => 'domain', 'nama' => 'Domain', 'harga' => 0, 'aktif' => false])->id,
            'label' => 'desa.id', 'status' => ServiceStatus::Disiapkan, 'harga' => 50000,
            'detail' => ['domain' => 'contoh.desa.id', 'jenis' => 'Register', 'periode' => 1, 'kontak' => self::KONTAK],
        ]);
        Invoice::create(['user_id' => $service->user_id, 'service_id' => $service->id, 'nomor' => 'INV-Y', 'jumlah' => 50000, 'status' => InvoiceStatus::Lunas,
            'periode_mulai' => today(), 'periode_selesai' => today()->addYear(), 'jatuh_tempo' => today()]);
        $this->crm['order'] = [['order' => 'ORD9', 'invoice' => 'VDI-9', 'domain' => ['status' => 'Active'], 'registrasi' => null], 201];

        (new KirimDomainCrm($service))->handle(app(DomainCrm::class));

        $this->assertSame(ServiceStatus::Disiapkan, $service->fresh()->status);
        $this->assertStringContainsString('manual', $service->fresh()->detail['akses']);
    }

    private function domainAktif(User $user): Service
    {
        return Service::create([
            'user_id' => $user->id,
            'plan_id' => \App\Models\Plan::firstOrCreate(['slug' => 'domain'], ['kategori' => 'domain', 'nama' => 'Domain', 'harga' => 0, 'aktif' => false])->id,
            'label' => 'tokobudi.com', 'status' => ServiceStatus::Aktif, 'harga' => 200000,
            'detail' => ['domain' => 'tokobudi.com', 'jenis' => 'Register', 'periode' => 1, 'kontak' => self::KONTAK, 'crm' => ['order' => 'ORD1', 'customer_id' => 77]],
        ]);
    }

    public function test_chat_cek_dan_pesan_domain(): void
    {
        $user = User::factory()->create(['name' => 'Budi', 'whatsapp' => null]);
        $this->hargaCrm();

        $cek = json_decode(AlatLayanan::jalankan($user, 'cek_domain', ['domain' => 'tokobudi.com']), true);
        $this->assertStringContainsString('tersedia', $cek['status']);
        $this->assertSame(200000, $cek['harga_rupiah'][0]['register']);
        Pengaturan::simpan('domain.tld_aktif', ['.id']);
        $this->assertFalse(json_decode(AlatLayanan::jalankan($user, 'cek_domain', ['domain' => 'tokobudi.com']), true)['dijual'] ?? true);
        Pengaturan::simpan('domain.tld_aktif', ['.com']);

        // belum ada alamat WHOIS → diarahkan ke form sekali
        $this->assertStringContainsString('/domain', json_decode(AlatLayanan::jalankan($user, 'pesan_domain', ['domain' => 'tokobudi.com', 'jenis' => 'Register']), true)['galat']);
        $this->assertSame(0, Service::count());
        $this->assertContains('pesan_domain', AlatLayanan::KONFIRMASI);

        $lama = $this->domainAktif($user);
        $lama->update(['label' => 'lama.com', 'detail' => [...$lama->detail, 'domain' => 'lama.com']]);
        $hasil = json_decode(AlatLayanan::jalankan($user, 'pesan_domain', ['domain' => 'TokoBudi.com', 'jenis' => 'Register', 'periode' => 1]), true);
        $this->assertSame(Invoice::sole()->nomor, $hasil['tagihan']);
        $this->assertSame('Sleman', Service::where('label', 'tokobudi.com')->sole()->detail['kontak']['kota']);
    }

    public function test_chat_info_dan_ubah_nameserver_domain_milik_sendiri(): void
    {
        $user = User::factory()->create();
        $s = $this->domainAktif($user);
        $this->crm['domains'] = [['data' => [['id' => 5, 'domain' => 'lain.com', 'status' => 'Active'], ['id' => 9, 'domain' => 'tokobudi.com', 'status' => 'Active', 'expiry_date' => '2027-11-25', 'next_due_date' => '2027-11-25']]], 200];
        $this->crm['ns'] = [['message' => 'OK', 'data' => ['nameservers' => ['ns1.lama.com', 'ns2.lama.com']]], 200];
        $this->crm['ubah_ns'] = [['message' => 'Nameserver berhasil diubah', 'data' => null], 200];

        $daftar = json_decode(AlatLayanan::jalankan($user, 'daftar_layanan', []), true);
        $this->assertSame(['info_domain', 'ubah_nameserver'], $daftar[0]['alat']);

        $info = json_decode(AlatLayanan::jalankan($user, 'info_domain', ['id' => $s->id]), true);
        $this->assertSame(['Active', '2027-11-25', ['ns1.lama.com', 'ns2.lama.com']], [$info['status'], $info['aktif_sampai'], $info['nameserver']]);

        $teks = AlatLayanan::jalankan($user, 'ubah_nameserver', ['id' => $s->id, 'nameserver' => ['NS1.Baru.com.', 'ns2.baru.com']]);
        $this->assertStringContainsString('berhasil', $teks);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/cust/customers/77/domains/9/nameservers')
            && $r['ns'] === ['ns1.baru.com', 'ns2.baru.com'] && $r->hasHeader('vdnet-key', 'kunci-cust'));
        $this->assertContains('ubah_nameserver', AlatLayanan::KONFIRMASI);

        // domain milik user lain tidak bisa disentuh
        $lain = User::factory()->create();
        $this->assertArrayHasKey('galat', json_decode(AlatLayanan::jalankan($lain, 'info_domain', ['id' => $s->id]), true));
    }
}
