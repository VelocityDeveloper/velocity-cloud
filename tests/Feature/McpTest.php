<?php

namespace Tests\Feature;

use App\Enums\PlanKategori;
use App\Jobs\DeployLanding;
use App\Mcp\Servers\VelocityCloud;
use App\Mcp\Tools;
use App\Models\AgenCatatan;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class McpTest extends TestCase
{
    use RefreshDatabase;

    public function test_ubah_paket_lewat_mcp_mencatat_tindakan_dan_menjaga_field_lain(): void
    {
        Queue::fake();
        $dir = sys_get_temp_dir().'/mcp-test-'.uniqid();
        mkdir($dir);
        file_put_contents("$dir/paket.json", json_encode(['vps' => [['nama' => 'Cloud 1', 'harga' => 114000]], 'managed' => []]));
        config(['cloud.katalog_dir' => $dir]);
        $plan = Plan::create(['kategori' => PlanKategori::Vps, 'slug' => 'vps-cloud-1', 'nama' => 'Cloud 1', 'harga' => 114000, 'stok' => 5]);

        VelocityCloud::tool(Tools\UbahPaket::class, ['slug' => 'vps-cloud-1', 'harga' => 99000, 'alasan' => 'uji'])
            ->assertOk()->assertSee('"situs_diperbarui":true');

        $plan->refresh();
        $this->assertSame([99000, true, 5], [$plan->harga, $plan->aktif, $plan->stok]); // aktif & stok tetap
        Queue::assertPushed(DeployLanding::class);
        $this->assertSame('diterapkan', AgenCatatan::sole()->status);

        VelocityCloud::tool(Tools\UbahPaket::class, ['slug' => 'tidak-ada', 'alasan' => 'x'])->assertHasErrors();
    }

    public function test_usulan_disetujui_admin_terbaca_agen(): void
    {
        VelocityCloud::tool(Tools\CatatTindakan::class, ['judul' => 'Naikkan harga Cloud 4', 'isi' => 'data...', 'status' => 'usulan'])->assertOk();
        $c = AgenCatatan::sole();

        $this->actingAs(User::factory()->create())->post(route('admin.agen.putuskan', $c), ['status' => 'disetujui'])->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->post(route('admin.agen.putuskan', $c), ['status' => 'disetujui'])->assertRedirect();

        VelocityCloud::tool(Tools\DaftarTindakan::class, ['status' => 'disetujui'])->assertOk()->assertSee('Naikkan harga Cloud 4');
        VelocityCloud::tool(Tools\CatatTindakan::class, ['id' => $c->id, 'status' => 'diterapkan'])->assertOk();
        $this->assertSame('diterapkan', $c->fresh()->status);
    }

    public function test_alat_baca_jalan(): void
    {
        VelocityCloud::tool(Tools\RingkasanBisnis::class)->assertOk()->assertSee('paket_dijual');
        VelocityCloud::tool(Tools\LaporanAnalitik::class, ['periode' => '7h'])->assertOk()->assertSee('ringkasan');
        VelocityCloud::tool(Tools\DaftarPaket::class)->assertOk();
        VelocityCloud::tool(Tools\CatatLaporan::class, ['judul' => 'Laporan harian', 'isi' => '# ok'])->assertOk();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.agen.index'))->assertOk();
    }

    public function test_kalender_laporan_per_bulan_wib(): void
    {
        $buat = fn (string $utc) => tap(AgenCatatan::create(['jenis' => 'laporan', 'judul' => $utc, 'isi' => 'x', 'agen' => 'dudung']), fn ($c) => $c->forceFill(['created_at' => $utc])->save());
        $buat('2026-10-31 16:59:00'); // 23:59 WIB 31 Okt → Oktober
        $buat('2026-10-31 17:00:00'); // 00:00 WIB 1 Nov → November
        $buat('2026-11-30 16:59:59'); // 23:59 WIB 30 Nov → November
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get(route('admin.agen.index', ['jenis' => 'laporan', 'bulan' => '2026-11']))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p->where('bulan', '2026-11')->has('catatan', 2));
        $this->actingAs($admin)->get(route('admin.agen.index', ['jenis' => 'laporan', 'bulan' => '2026-10']))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p->has('catatan', 1));
        $this->actingAs($admin)->get(route('admin.agen.index', ['bulan' => 'bukan-bulan']))->assertSessionHasErrors('bulan');
        $this->actingAs($admin)->get(route('admin.agen.index', ['jenis' => 'tindakan']))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p->where('bulan', null));
    }

    public function test_siklus_belajar_metrik_awal_evaluasi_pelajaran(): void
    {
        config(['cloud.gsc.kredensial' => '/tidak/ada.json']); // jangan panggil Google di tes
        $hit = fn (string $path, string $jenis, string $waktu, ?string $event = null) => \Illuminate\Support\Facades\DB::table('analitik_hits')->insert([
            'waktu' => $waktu, 'jenis' => $jenis, 'vid' => uniqid(), 'sid' => uniqid(), 'pid' => 'p', 'path' => $path, 'event' => $event, 'internal' => false,
        ]);
        $this->travelTo('2026-11-01 10:00:00');
        $hit('/app/n8n/', 'pv', '2026-10-30 09:00:00');

        VelocityCloud::tool(Tools\CatatTindakan::class, ['judul' => 'Judul n8n memuat harga', 'isi' => 'hipotesis CTR naik', 'status' => 'diterapkan', 'target' => '/app/n8n/'])
            ->assertOk()->assertSee('metrik awal /app/n8n/');
        $t = AgenCatatan::latest('id')->first();
        $this->assertSame(1, $t->metrik_awal['tampilan']);
        $this->assertNull($t->metrik_awal['gsc']);

        VelocityCloud::tool(Tools\CatatPelajaran::class, ['judul' => 'x', 'isi' => 'y', 'bukti' => [$t->id]])->assertHasErrors(); // belum dievaluasi

        $this->travelTo('2026-11-09 10:00:00');
        foreach (range(1, 3) as $i) {
            $hit('/app/n8n/', 'pv', '2026-11-0'.(3 + $i).' 09:00:00');
        }
        $hit('/app/n8n/', 'ev', '2026-11-05 09:00:00', 'klik_pesan');
        VelocityCloud::tool(Tools\MulaiSiklus::class)->assertOk()->assertSee('tindakan_perlu_evaluasi')->assertSee('"klik_pesan":1');

        VelocityCloud::tool(Tools\EvaluasiTindakan::class, ['id' => $t->id, 'putusan' => 'berhasil', 'catatan' => '1 → 3 tampilan'])->assertOk();
        $t->refresh();
        $this->assertSame('berhasil', $t->hasil['putusan']);
        $this->assertSame(3, $t->hasil['metrik']['tampilan']);
        VelocityCloud::tool(Tools\EvaluasiTindakan::class, ['id' => $t->id, 'putusan' => 'gagal', 'catatan' => 'x'])->assertOk(); // boleh dinilai ulang

        VelocityCloud::tool(Tools\CatatPelajaran::class, ['judul' => 'Harga di judul', 'isi' => 'Pola', 'bukti' => [$t->id]])->assertOk();
        $p = \App\Models\AgenPelajaran::first();
        VelocityCloud::tool(Tools\CatatPelajaran::class, ['id' => $p->id, 'skor_delta' => -2, 'bukti' => [$t->id]])->assertOk()->assertSee('skor -1, aktif');
        VelocityCloud::tool(Tools\CatatPelajaran::class, ['id' => $p->id, 'skor_delta' => -1, 'bukti' => [$t->id]])->assertOk()->assertSee('usang');
        $this->assertSame([$t->id], $p->fresh()->bukti);
        VelocityCloud::tool(Tools\MetrikHalamanTool::class, ['path' => '/app/n8n/', 'hari' => 7])->assertOk()->assertSee('"tampilan":3');
        VelocityCloud::tool(Tools\MetrikHalamanTool::class, ['path' => 'http://evil'])->assertHasErrors();
    }

    public function test_tool_mcp_baru_arahan_target_promo_atribusi_dan_tiket(): void
    {
        config(['cloud.gsc.kredensial' => '/tidak/ada.json']);
        $admin = User::factory()->create(['is_admin' => true]);
        $pelanggan = User::factory()->create(['is_admin' => false, 'analitik_vid' => 'vidpelanggan1']);
        \Illuminate\Support\Facades\DB::table('analitik_hits')->insert([
            'waktu' => now()->subHour()->toDateTimeString(), 'jenis' => 'pv', 'vid' => 'vidpelanggan1', 'sid' => 'sesi00001',
            'pid' => 'pid00001', 'path' => '/token-ai/', 'sumber' => 'google', 'medium' => 'organic', 'negara' => 'ID', 'internal' => false,
        ]);

        // Simpan arahan pemilik & target dari dashboard, lalu pastikan terbaca di mulai-siklus
        $this->actingAs($admin)->post(route('admin.agen.arahan'), ['teks' => 'Fokus perbaiki CTA /token-ai/'])->assertRedirect();
        $this->actingAs($admin)->put(route('admin.agen.target'), [
            'mingguan' => ['sesi_asli' => 20, 'klik_pesan' => 8, 'klik_daftar' => 4, 'order_lunas' => 3],
            'bulanan' => ['sesi_asli' => 80, 'klik_pesan' => 30, 'klik_daftar' => 15, 'order_lunas' => 10, 'omzet_lunas' => 3000000],
        ])->assertRedirect();

        VelocityCloud::tool(Tools\MulaiSiklus::class)->assertOk()
            ->assertSee('Fokus perbaiki CTA /token-ai/')
            ->assertSee('"sesi_asli"')
            ->assertSee('"klik_daftar"');

        // Atribusi pendaftar (tanpa email)
        VelocityCloud::tool(Tools\AtribusiPendaftar::class, ['hari' => 30])->assertOk()
            ->assertSee('google / organic')
            ->assertDontSee($pelanggan->email);

        // Tambah & ubah promo lewat MCP
        VelocityCloud::tool(Tools\UbahPromo::class, [
            'nama' => 'Bonus 10 Juta Token', 'bonus_token_juta' => 10, 'min_pembelian' => 50000,
            'kategori' => ['ai', 'app'], 'alasan' => 'Dorong konversi awal',
        ])->assertOk()->assertSee('Bonus 10 Juta Token');
        VelocityCloud::tool(Tools\DaftarPromo::class)->assertOk()->assertSee('Bonus 10 Juta Token');

        // Ringkasan tiket (tanpa PII)
        $tiket = \App\Models\Tiket::create(['user_id' => $pelanggan->id, 'jenis' => 'support', 'status' => 'terbuka', 'judul' => 'Cara pakai API key']);
        \App\Models\TiketPesan::create(['tiket_id' => $tiket->id, 'user_id' => $pelanggan->id, 'dari_admin' => false, 'isi' => 'Bagaimana setting base URL?']);
        VelocityCloud::tool(Tools\RingkasanTiket::class)->assertOk()
            ->assertSee('Cara pakai API key')
            ->assertDontSee($pelanggan->email);

        // Kontrol pelajaran dari dashboard admin
        $p = \App\Models\AgenPelajaran::create(['judul' => 'Pola uji', 'isi' => 'Isi', 'bukti' => [], 'skor' => 1, 'status' => 'aktif']);
        $this->actingAs($admin)->post(route('admin.agen.pelajaran.status', $p), ['status' => 'usang'])->assertRedirect();
        $this->assertSame('usang', $p->fresh()->status);
    }
}
