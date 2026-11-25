<?php

namespace Tests\Feature;

use App\Enums\PlanKategori;
use App\Jobs\PodAction;
use App\Models\Node;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use App\Services\Chat\AlatLayanan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ChatAlatTest extends TestCase
{
    use RefreshDatabase;

    private function layanan(User $u, string $slug, ?Node $node = null, string $status = 'aktif', PlanKategori $kategori = PlanKategori::App): Service
    {
        $plan = Plan::firstOrCreate(['slug' => $slug], ['kategori' => $kategori, 'nama' => $slug, 'harga' => 50000, 'aktif' => true]);

        return Service::create(['user_id' => $u->id, 'plan_id' => $plan->id, 'label' => "L-$slug", 'status' => $status, 'harga' => 0,
            'node_id' => ($node ?? Node::create(['nama' => 'pod', 'agent_url' => 'http://agent.test', 'token' => str_repeat('a', 32)]))->id]);
    }

    private function alat(User $u, string $nama, array $arg = []): string
    {
        return $this->actingAs($u)->postJson('/chat/alat', ['nama' => $nama, 'argumen' => $arg])->assertOk()->json('hasil');
    }

    public function test_definisi_alat_dan_ringkasan(): void
    {
        $this->assertSame('daftar_layanan', AlatLayanan::definisi()[0]['function']['name']);
        $this->assertSame(['aksi_layanan', 'wp', 'perintah_vps', 'tulis_berkas', 'pesan_domain', 'ubah_nameserver'], AlatLayanan::KONFIRMASI);
        $this->assertSame('restart layanan #3', AlatLayanan::ringkas('aksi_layanan', ['id' => 3, 'aksi' => 'restart']));
        $this->assertSame('Tambah ke wp-content/uploads/a.html (2 KB, WordPress #5)', AlatLayanan::ringkas('tulis_berkas', ['id' => 5, 'path' => 'uploads/a.html', 'isi' => str_repeat('x', 1500), 'tambah' => true]));
        $this->assertSame('wp plugin list  (WordPress)', AlatLayanan::ringkas('wp', ['args' => ['plugin', 'list', ['bukan teks']]]));
    }

    public function test_daftar_hanya_milik_sendiri_dan_alat_sesuai_jenis(): void
    {
        $saya = User::factory()->create();
        $wp = $this->layanan($saya, 'app-wordpress');
        $this->layanan($saya, 'app-n8n', status: 'ditangguhkan');
        $this->layanan(User::factory()->create(), 'app-n8n');

        $daftar = json_decode($this->alat($saya, 'daftar_layanan'), true);

        $this->assertCount(2, $daftar);
        $byId = array_column($daftar, null, 'id');
        $this->assertSame(['status_layanan', 'log_layanan', 'aksi_layanan', 'wp', 'tulis_berkas'], $byId[$wp->id]['alat']);
        $this->assertSame([], collect($daftar)->firstWhere('status', 'ditangguhkan')['alat']);
    }

    public function test_layanan_orang_lain_atau_nonaktif_ditolak_tanpa_menghubungi_node(): void
    {
        Http::fake();
        Queue::fake();
        $saya = User::factory()->create();
        $orang = $this->layanan(User::factory()->create(), 'app-wordpress');
        $mati = $this->layanan($saya, 'app-wordpress', status: 'ditangguhkan');

        $this->assertStringContainsString('tidak ditemukan', $this->alat($saya, 'aksi_layanan', ['id' => $orang->id, 'aksi' => 'stop']));
        $this->assertStringContainsString('tidak bisa dikelola', $this->alat($saya, 'wp', ['id' => $mati->id, 'args' => ['plugin', 'list']]));
        $this->assertStringContainsString('tidak dikenal', $this->alat($saya, 'hapus_semua'));
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_aksi_dijadwalkan_dan_wp_diteruskan_ke_agent(): void
    {
        Queue::fake();
        Http::fake(['agent.test/pods/*/wp' => Http::response(['ok' => true, 'out' => 'akismet,inactive', 'err' => ''])]);
        $saya = User::factory()->create();
        $wp = $this->layanan($saya, 'app-wordpress');
        $n8n = $this->layanan($saya, 'app-n8n');

        $this->alat($saya, 'aksi_layanan', ['id' => $wp->id, 'aksi' => 'restart']);
        Queue::assertPushed(PodAction::class, fn ($j) => $j->service->is($wp) && $j->aksi === 'restart');
        $this->assertStringContainsString('aksi', $this->alat($saya, 'aksi_layanan', ['id' => $wp->id, 'aksi' => 'hapus']));

        $hasil = $this->alat($saya, 'wp', ['id' => $wp->id, 'args' => ['wp', 'plugin', 'list']]);
        $this->assertStringContainsString('akismet', $hasil);
        Http::assertSent(fn (Request $r) => $r->url() === "http://agent.test/pods/svc-{$wp->id}/wp" && $r['args'] === ['plugin', 'list']);

        $this->assertStringContainsString('hanya untuk App WordPress', $this->alat($saya, 'wp', ['id' => $n8n->id, 'args' => ['plugin', 'list']]));
    }

    public function test_perintah_vps_lewat_exec_incus(): void
    {
        $this->app['config']->set('cloud.vps.client_cert', '/dev/null');
        $this->app['config']->set('cloud.vps.client_key', '/dev/null');
        Http::fake([ // log exec dulu: path-nya juga mengandung "exec"
            '*/a.stdout*' => Http::response('Filesystem  Size'),
            '*/a.stderr*' => Http::response(''),
            '*/1.0/instances/*/exec*' => Http::response(['operation' => '/1.0/operations/op1']),
            '*/1.0/operations/op1/wait*' => Http::response(['metadata' => ['status' => 'Success', 'err' => '', 'metadata' => [
                'return' => 0, 'output' => ['1' => '/1.0/instances/svc-1/logs/exec-output/a.stdout', '2' => '/1.0/instances/svc-1/logs/exec-output/a.stderr'],
            ]]]),
        ]);
        $saya = User::factory()->create();
        $node = Node::create(['nama' => 'vps', 'jenis' => 'incus', 'agent_url' => 'https://incus.test:8443', 'token' => "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n"]);
        $vps = $this->layanan($saya, 'vps-cloud-1', $node, kategori: PlanKategori::Vps);

        $hasil = json_decode($this->alat($saya, 'perintah_vps', ['id' => $vps->id, 'perintah' => 'df -h']), true);

        $this->assertSame(['kode' => 0, 'stdout' => 'Filesystem  Size', 'stderr' => ''], $hasil);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), "/1.0/instances/svc-{$vps->id}/exec") && $r['command'] === ['timeout', '60', 'sh', '-c', 'df -h']);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'a.stdout'));
        $this->assertStringContainsString('hanya untuk Cloud Server', $this->alat($saya, 'perintah_vps', ['id' => $this->layanan($saya, 'app-n8n')->id, 'perintah' => 'ls']));
    }

    public function test_panduan_dan_contoh_seksi_utuh_dan_valid(): void
    {
        $saya = User::factory()->create();
        $panduan = $this->alat($saya, 'panduan_desain_wp');
        $this->assertStringContainsString('themes/<slug>/theme.json', $panduan);
        $this->assertStringContainsString('"accent-1"', $panduan);
        $this->assertGreaterThan(8000, mb_strlen($panduan)); // tidak dipotong BATAS_HASIL

        foreach (AlatLayanan::SEKSI as $seksi) {
            $this->assertStringStartsWith('<!-- wp:', $this->alat($saya, 'contoh_seksi_wp', ['seksi' => $seksi]), $seksi);
        }
        $this->assertStringContainsString('seksi', $this->alat($saya, 'contoh_seksi_wp', ['seksi' => '../panduan']));

        $alat = config('cloud.chat_ai.cek_blok');
        if (! is_executable($alat)) {
            $this->markTestSkipped('cek-blok tidak terpasang');
        }
        $berkas = [...glob(resource_path('ai/desain-wp/seksi/*.html')), ...glob(resource_path('ai/desain-wp/tema/*/*.html'))];
        $hasil = Process::timeout(120)->run([$alat, ...$berkas]);
        $this->assertSame(0, $hasil->exitCode(), $hasil->output());
    }

    public function test_cari_foto_pexels(): void
    {
        $kunci = tempnam(sys_get_temp_dir(), 'px');
        file_put_contents($kunci, "kunci-uji\n");
        config(['cloud.chat_ai.pexels_kunci' => $kunci]);
        Http::fake(['api.pexels.com/*' => Http::response(['photos' => [[
            'width' => 1600, 'height' => 1000, 'alt' => 'Coconut', 'photographer' => 'Ana',
            'src' => ['original' => 'https://images.pexels.com/photos/1/pexels-photo-1.jpeg'],
        ]]])]);

        $foto = json_decode($this->alat(User::factory()->create(), 'cari_foto', ['kata' => 'coconut', 'jumlah' => 99]), true);
        @unlink($kunci);

        $this->assertSame('https://images.pexels.com/photos/1/pexels-photo-1.jpeg?auto=compress&cs=tinysrgb&w=1600', $foto[0]['url_besar']);
        $this->assertSame('Ana', $foto[0]['fotografer']);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'kunci-uji') && $r['per_page'] === 8);
    }

    public function test_tulis_berkas_hanya_wp_content_dan_dicek_bloknya(): void
    {
        config(['cloud.chat_ai.cek_blok' => '/tidak/ada']);
        Http::fake(['agent.test/pods/*/berkas' => Http::response(['path' => 'themes/x/style.css', 'ukuran' => 10])]);
        $saya = User::factory()->create();
        $wp = $this->layanan($saya, 'app-wordpress');

        foreach (['../wp-config.php', 'themes/../../wp-config.php', 'themes/./x', 'wp-config.php', 'themes//x'] as $jahat) {
            $this->assertStringContainsString('format', $this->alat($saya, 'tulis_berkas', ['id' => $wp->id, 'path' => $jahat, 'isi' => 'x']), $jahat);
        }
        Http::assertNothingSent();

        $this->alat($saya, 'tulis_berkas', ['id' => $wp->id, 'path' => 'uploads/velocity-ai/beranda.html', 'isi' => '<!-- wp:paragraph --><p>a</p><!-- /wp:paragraph -->', 'tambah' => true]);
        Http::assertSent(fn (Request $r) => $r->url() === "http://agent.test/pods/svc-{$wp->id}/berkas" && $r['tambah'] === true && $r['path'] === 'uploads/velocity-ai/beranda.html');
        $this->assertStringContainsString('hanya untuk App WordPress', $this->alat($saya, 'tulis_berkas', ['id' => $this->layanan($saya, 'app-n8n')->id, 'path' => 'themes/a/b', 'isi' => 'x']));
    }
}
