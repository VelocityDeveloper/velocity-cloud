<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Analitik\Laporan;
use App\Services\Analitik\Pengurai;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AnalitikTest extends TestCase
{
    use RefreshDatabase;

    private const UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    private function baris(array $q, string $ua = self::UA, string $ip = '1.2.3.4', string $t = '2026-11-11T09:00:00+07:00'): string
    {
        $q += ['vid' => 'pengunjung01', 'sid' => 'sesi000001', 'pid' => 'tampilan01', 'p' => '/'];

        return json_encode(['t' => $t, 'ip' => $ip, 'ua' => $ua, 'q' => http_build_query($q), 'negara' => 'ID', 'kota' => '', 'bahasa' => '']);
    }

    public function test_pengurai_mengklasifikasi_sumber_perangkat_dan_menolak_bot(): void
    {
        $p = new Pengurai(['9.9.9.9']);
        $pv = $p->urai($this->baris(['e' => 'pv', 's' => 1, 'n' => 1, 'r' => 'https://www.google.co.id/', 'w' => 390, 'h' => 844, 'l' => 'id-ID']));
        $this->assertSame(['google', 'organic', 'mobile', 'Safari', 'iOS', '390x844', true, true, false],
            [$pv['sumber'], $pv['medium'], $pv['perangkat'], $pv['browser'], $pv['os'], $pv['layar'], $pv['awal_sesi'], $pv['baru'], $pv['internal']]);
        $this->assertSame('2026-11-11 09:00:00', $pv['waktu']);

        $this->assertSame(['facebook', 'social'], $p->sumber('https://l.facebook.com/x'));
        $this->assertSame(['contoh.com', 'referral'], $p->sumber('https://www.contoh.com/a'));
        $this->assertSame(['(direct)', '(none)'], $p->sumber(null));
        $this->assertSame(['newsletter', 'email'], $p->sumber('https://www.google.com/', 'Newsletter', 'Email'));

        $this->assertTrue($p->urai($this->baris(['e' => 'pv'], ip: '9.9.9.9'))['internal']);
        $pCidr = new Pengurai(['36.65.0.0/16'], ['vidujibot01']);
        $this->assertTrue($pCidr->urai($this->baris(['e' => 'pv'], ip: '36.65.12.34'))['internal']);
        $this->assertFalse($pCidr->urai($this->baris(['e' => 'pv'], ip: '36.66.12.34'))['internal']);
        $this->assertTrue($pCidr->urai($this->baris(['e' => 'pv', 'vid' => 'vidujibot01'], ip: '1.2.3.4'))['internal']);
        $this->assertNull($p->urai($this->baris(['e' => 'pv'], 'Googlebot/2.1')));
        $this->assertNull($p->urai($this->baris(['e' => 'pv', 'vid' => '<script>'])));
        $this->assertNull($p->urai($this->baris(['e' => 'xx'])));
        $this->assertNull($p->urai('bukan json'));
        $this->assertSame(3_600_000, $p->urai($this->baris(['e' => 'eg', 'd' => 99_999_999, 'sc' => 300]))['durasi_ms']);
    }

    public function test_impor_membaca_bertahap_dan_tahan_rotasi(): void
    {
        Storage::fake();
        $log = tempnam(sys_get_temp_dir(), 'an');
        config(['cloud.analitik.log' => $log]);
        file_put_contents($log, $this->baris(['e' => 'pv'])."\n".$this->baris(['e' => 'pv', 'pid' => 'tampilan02'])."\n");
        $this->artisan('analitik:impor')->assertSuccessful();
        $this->assertSame(2, DB::table('analitik_hits')->count());

        // baris belum lengkap tidak dibaca; impor ulang tidak menggandakan
        file_put_contents($log, $this->baris(['e' => 'pv', 'pid' => 'tampilan03']), FILE_APPEND);
        $this->artisan('analitik:impor')->assertSuccessful();
        $this->assertSame(2, DB::table('analitik_hits')->count());
        file_put_contents($log, "\n", FILE_APPEND);
        $this->artisan('analitik:impor')->assertSuccessful();
        $this->assertSame(3, DB::table('analitik_hits')->count());

        // rotasi: berkas lama jadi .1 (dengan sisa baris baru), berkas baru dibuat
        file_put_contents($log, $this->baris(['e' => 'pv', 'pid' => 'tampilan04'])."\n", FILE_APPEND);
        rename($log, "$log.1");
        file_put_contents($log, $this->baris(['e' => 'pv', 'pid' => 'tampilan05'])."\n");
        $this->artisan('analitik:impor')->assertSuccessful();
        $this->assertSame(5, DB::table('analitik_hits')->count());
        @unlink($log);
        @unlink("$log.1");
    }

    public function test_laporan_menghitung_metrik_ala_ga(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00:00'));
        $p = new Pengurai;
        $hit = fn (array $q, string $jam = '09:00:00') => DB::table('analitik_hits')->insert($p->urai($this->baris($q, t: "2026-11-11T{$jam}+07:00")));
        // sesi 1: bounce (1 tampilan, 3 dtk)
        $hit(['e' => 'pv', 's' => 1, 'n' => 1, 'vid' => 'pengunjunga', 'sid' => 'sesia00001', 'r' => 'https://www.google.com/']);
        $hit(['e' => 'eg', 'd' => 3000, 'sc' => 40, 'vid' => 'pengunjunga', 'sid' => 'sesia00001']);
        // sesi 2: engaged (2 tampilan + klik pesan)
        $hit(['e' => 'pv', 's' => 1, 'n' => 1, 'vid' => 'pengunjungb', 'sid' => 'sesib00001', 'pid' => 'tampilanb1']);
        $hit(['e' => 'pv', 'vid' => 'pengunjungb', 'sid' => 'sesib00001', 'pid' => 'tampilanb2', 'p' => '/cloud-server/'], '09:05:00');
        $hit(['e' => 'ev', 'en' => 'klik_pesan', 'el' => 'vps-cloud-1', 'vid' => 'pengunjungb', 'sid' => 'sesib00001', 'p' => '/cloud-server/'], '09:06:00');

        $l = (new Laporan)->semua('hari_ini');
        $this->assertSame(['pengguna' => 2, 'pengguna_baru' => 2, 'sesi' => 2, 'tampilan' => 3, 'tampilan_per_sesi' => 1.5, 'durasi_per_sesi' => 2, 'engaged_rate' => 0.5, 'bounce_rate' => 0.5, 'konversi' => 1], $l['ringkasan']);
        $this->assertSame('/', $l['halaman'][0]['path']);
        $this->assertSame(2, $l['halaman_landing'][0]['sesi']);
        $this->assertEqualsCanonicalizing(['/', '/cloud-server/'], array_column($l['halaman_keluar'], 'path'));
        $this->assertEqualsCanonicalizing(['google / organic', '(direct) / (none)'], array_column($l['sumber'], 'nama'));
        $this->assertSame('Indonesia', $l['negara'][0]['nama']);
        $this->assertSame('klik_pesan', $l['event'][0]['event']);
        $this->assertCount(24, $l['tren']);
        $this->assertSame(['label' => '09:00', 'pengguna' => 2, 'sesi' => 2, 'tampilan' => 3, 'baru' => 2, 'konversi' => 1], $l['tren'][9]);
        $this->assertNull($l['tren'][13]['pengguna']); // jam 13 belum terjadi (kini 12:00)
    }

    public function test_halaman_analitik_hanya_untuk_admin(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.analitik.index'))->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.analitik.index', ['periode' => '28h']))
            ->assertOk()->assertInertia(fn (Assert $p) => $p->component('admin/Analitik')->where('filter.periode', '28h')->has('laporan.ringkasan'));
    }
}
