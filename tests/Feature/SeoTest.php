<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Seo\LaporanSeo;
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
                'page' => [['keys' => ['https://velocitydeveloper.ai.id/app/n8n/'], 'clicks' => 3, 'impressions' => 100, 'ctr' => 0.03, 'position' => 8.0]],
                default => [['keys' => ['hosting n8n'], 'clicks' => 3, 'impressions' => 100, 'ctr' => 0.03, 'position' => 8.0]],
            }]),
        ]);

        $l = app(LaporanSeo::class)->semua('7h');
        unlink($berkas);

        $this->assertSame('ok', $l['status']);
        $this->assertSame(['klik' => 3, 'tayangan' => 100, 'ctr' => 0.03, 'posisi' => 8.0], $l['ringkasan']);
        $this->assertCount(7, $l['tren']);
        $this->assertSame(0, $l['ringkasan_lalu']['tayangan']);
        $this->assertSame('/app/n8n/', $l['halaman'][0]['nama']);
        $this->assertSame('hosting n8n', $l['kueri'][0]['nama']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'sc-domain%3Avelocitydeveloper.ai.id'));
    }
}
