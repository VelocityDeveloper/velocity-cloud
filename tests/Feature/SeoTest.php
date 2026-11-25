<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Seo\LaporanSeo;
use App\Services\Seo\PetaKataKunci;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_tanpa_kredensial_belum_terhubung_dan_non_admin_ditolak(): void
    {
        config(['cloud.gsc.kredensial' => '/tidak/ada.json']);
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.seo.index'))
            ->assertInertia(fn (Assert $p) => $p->component('admin/Seo')->where('laporan.status', 'belum_terhubung'));
        $this->actingAs(User::factory()->create())->get(route('admin.seo.index'))->assertForbidden();
    }

    public function test_laporan_dari_gsc(): void
    {
        $kunci = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($kunci, $pem);
        $berkas = tempnam(sys_get_temp_dir(), 'gsc');
        file_put_contents($berkas, json_encode(['client_email' => 'sa@x.iam.gserviceaccount.com', 'private_key' => $pem, 'token_uri' => 'https://oauth2.googleapis.com/token']));
        config(['cloud.gsc.kredensial' => $berkas]);
        $kemarin = CarbonImmutable::yesterday()->toDateString();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']),
            'searchconsole.googleapis.com/*' => fn ($req) => Http::response(['rows' => match ($req['dimensions'][0]) {
                'date' => $req['endDate'] === $kemarin
                    ? [['keys' => [$kemarin], 'clicks' => 3, 'impressions' => 100, 'ctr' => 0.03, 'position' => 8.0]]
                    : [],
                'page' => [['keys' => ['https://velocitydatacenter.com/app/n8n/'], 'clicks' => 3, 'impressions' => 100, 'ctr' => 0.03, 'position' => 8.0]],
                default => [['keys' => ['hosting n8n'], 'clicks' => 3, 'impressions' => 100, 'ctr' => 0.03, 'position' => 8.0]],
            }]),
        ]);

        $l = app(LaporanSeo::class)->semua('7h');
        // halaman menambah pengunjung landing per hari, sepanjang tren GSC (untuk grafik gabungan)
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.seo.index', ['periode' => '7h']))
            ->assertInertia(fn (Assert $p) => $p->where('laporan.status', 'ok')->has('laporan.pengunjung', 7));
        unlink($berkas);

        $this->assertSame('ok', $l['status']);
        $this->assertSame(['klik' => 3, 'tayangan' => 100, 'ctr' => 0.03, 'posisi' => 8.0], $l['ringkasan']);
        $this->assertCount(7, $l['tren']);
        $this->assertSame(0, $l['ringkasan_lalu']['tayangan']);
        $this->assertSame('/app/n8n/', $l['halaman'][0]['nama']);
        $this->assertSame('hosting n8n', $l['kueri'][0]['nama']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'sc-domain%3Avelocitydatacenter.com'));
    }

    public function test_peta_kata_kunci_dari_riset_dan_gsc(): void
    {
        $riset = tempnam(sys_get_temp_dir(), 'riset');
        file_put_contents($riset, implode("\n", [
            '| Tanggal | Kata kunci | Volume | KD | Niat | Sumber | Status |',
            '|---|---|---|---|---|---|---|',
            '| 2026-11-18 | vps murah indonesia | 1300 | 21 | beli | Ubersuggest | dipakai → /blog/vps-murah/ |',
            '| 2026-11-18 | vps murah (induk) | 8100 | 45 | beli | Ubersuggest | cadangan |',
            '| 2026-11-19 | sewa vps | 590 | 57 | beli | Ubersuggest | dipakai → /blog/sewa-vps/ |',
            '| 2026-11-19 | sewa vps murah | 210 | 51 | beli | Ubersuggest | turunan (dipakai) |',
            '| 2026-11-19 | cloud server murah | 90 | 30 | beli | Ubersuggest | alternatif | vps murah, - | tinggi |',
        ]));
        config(['cloud.gsc.riset_kata_kunci' => $riset, 'cloud.gsc.kredensial' => '/tidak/ada.json']);

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.seo.peta'))
            ->assertInertia(fn (Assert $p) => $p->component('admin/SeoPeta')->where('peta.simpul', function ($simpul) {
                $s = collect($simpul)->keyBy('id');
                $this->assertSame('target', $s['vps murah indonesia']['status']);
                $this->assertSame(['vps murah'], $s['vps murah indonesia']['induk']);
                $this->assertSame(8100, $s['vps murah']['volume']); // "(induk)" dibuang dari kata
                $this->assertEqualsCanonicalizing(['vps murah', 'sewa vps'], $s['sewa vps murah']['induk']); // dua induk
                $this->assertTrue($s->has('hal:/blog/sewa-vps/'));
                $this->assertSame(['vps murah'], $s['cloud server murah']['induk']); // kolom Induk: alternatif beda kata
                $this->assertSame('alternatif · nilai jual tinggi', $s['cloud server murah']['catatan']);

                return true;
            }));
        unlink($riset);

        // kueri GSC baru: cabang dari target riset, halaman = tayangan terbanyak
        $peta = app(PetaKataKunci::class)->susun([], [
            ['keys' => ['n8n', 'https://x.com/app/n8n/'], 'clicks' => 1, 'impressions' => 50, 'position' => 9.0],
            ['keys' => ['hosting n8n', 'https://x.com/blog/a/'], 'clicks' => 0, 'impressions' => 5, 'position' => 20.0],
            ['keys' => ['hosting n8n', 'https://x.com/app/n8n/'], 'clicks' => 2, 'impressions' => 30, 'position' => 10.0],
        ]);
        $h = collect($peta['simpul'])->keyBy('id')['hosting n8n'];
        $this->assertSame(['/app/n8n/', 35, ['n8n']], [$h['url'], $h['tayangan'], $h['induk']]);
    }

    public function test_kata_kunci_negatif_disembunyikan_dan_bisa_dibatalkan(): void
    {
        $berkas = tempnam(sys_get_temp_dir(), 'negatif');
        config(['cloud.gsc.kata_negatif' => $berkas]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.seo.negatif'), ['kata' => 'VPS Windows'])->assertRedirect();
        $this->actingAs($admin)->post(route('admin.seo.negatif'), ['kata' => 'vps murah forex / untuk trading'])->assertRedirect();
        $peta = app(PetaKataKunci::class);
        $this->assertSame(['vps windows', 'vps murah forex'], $peta->negatif());

        $riset = $peta->riset(implode("\n", [
            '| 2026-11-18 | vps murah windows | 320 | 44 | beli | Ubersuggest | cadangan |',
            '| 2026-11-18 | vps murah | 8100 | 45 | beli | Ubersuggest | cadangan |',
        ]));
        $hasil = $peta->susun($riset, [['keys' => ['windows vps gratis', 'https://x.com/'], 'clicks' => 0, 'impressions' => 3, 'position' => 9.0]], $peta->negatif());
        $this->assertSame(['#akar', 'vps murah'], array_column(array_filter($hasil['simpul'], fn ($s) => $s['jenis'] !== 'halaman'), 'id'));
        $this->assertSame(2, $hasil['disembunyikan']); // urutan kata bebas: "windows vps gratis" ikut kena

        $this->actingAs($admin)->post(route('admin.seo.negatif'), ['kata' => 'vps windows', 'hapus' => true]);
        $this->assertSame(['vps murah forex'], $peta->negatif());
        $this->actingAs(User::factory()->create())->post(route('admin.seo.negatif'), ['kata' => 'x'])->assertForbidden();
        unlink($berkas);
    }
}
