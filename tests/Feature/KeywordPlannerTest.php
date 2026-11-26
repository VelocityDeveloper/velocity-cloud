<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class KeywordPlannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['cloud.keyword_planner' => [
            'client_id' => 'ci', 'client_secret' => 'cs', 'refresh_token' => 'rt', 'developer_token' => 'dt',
            'customer_id' => '123-456-7890', 'login_customer_id' => '999-000-1111', 'api' => 'https://ads.test/v26',
        ]]);
        $riset = tempnam(sys_get_temp_dir(), 'riset');
        file_put_contents($riset, "| 2026-11-20 | sewa vps | 590 | 30 | komersial | - | target → /cloud-server/ |\n");
        config(['cloud.gsc.riset_kata_kunci' => $riset]);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']),
            'ads.test/*' => Http::response(['results' => [
                ['text' => 'vps murah', 'keywordIdeaMetrics' => ['avgMonthlySearches' => '320', 'competition' => 'HIGH', 'competitionIndex' => '80',
                    'lowTopOfPageBidMicros' => '2500000000', 'highTopOfPageBidMicros' => '9000000000',
                    'monthlySearchVolumes' => [['month' => 'OCTOBER', 'year' => '2026', 'monthlySearches' => '300'], ['month' => 'NOVEMBER', 'year' => '2026', 'monthlySearches' => '340']]]],
                ['text' => 'sewa vps', 'keywordIdeaMetrics' => ['avgMonthlySearches' => '590', 'competition' => 'MEDIUM']],
            ]]),
        ]);
    }

    public function test_ide_kata_kunci_diurut_volume_dan_ditandai_mapping(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/admin/keyword-planner?kata=Sewa VPS, vps murah&lokasi=2360')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/SeoKeyword')->where('galat', null)
                ->where('hasil.0.kata', 'sewa vps')->where('hasil.0.dipetakan', true)->where('hasil.0.persaingan', 'sedang')
                ->where('hasil.1.kata', 'vps murah')->where('hasil.1.dipetakan', false)
                ->where('hasil.1.cpc_rendah', 2500)->where('hasil.1.cpc_tinggi', 9000)->where('hasil.1.tren.1', ['2026-11', 340])
                ->where('isian.kata', 'sewa vps, vps murah'));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'customers/1234567890:generateKeywordIdeas')
            && $r->hasHeader('developer-token', 'dt') && $r->hasHeader('login-customer-id', '9990001111')
            && $r['keywordSeed']['keywords'] === ['sewa vps', 'vps murah'] && $r['geoTargetConstants'] === ['geoTargetConstants/2360']);
    }

    public function test_tanpa_isian_tidak_memanggil_google_dan_bukan_admin_ditolak(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/admin/keyword-planner')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('hasil', null)->where('siap', true)
                ->where('starter.0.nama', 'Token AI')->where('starter.0.url', 'https://velocitydatacenter.com/token-ai/'));
        foreach (\App\Services\Seo\KeywordPlanner::STARTER as $s) {
            $this->assertLessThanOrEqual(20, count($s['kata'])); // batas seed Google
        }
        Http::assertNothingSent();

        $this->actingAs(User::factory()->create())->get('/admin/keyword-planner?kata=vps')->assertForbidden();
    }

    public function test_galat_google_tampil_tanpa_500(): void
    {
        // stub Http::fake pertama (setUp) selalu menang → ganti factory dulu
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']), 'ads.test/*' => Http::response(['error' => ['message' => 'x', 'details' => [['errors' => [['message' => 'Quota habis']]]]]], 429)]);

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/admin/keyword-planner?kata=hosting')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('hasil', null)->where('galat', 'Google Ads: Quota habis'));
    }

    public function test_alat_mcp_untuk_bot_buang_negatif_dan_tandai_mapping(): void
    {
        $neg = tempnam(sys_get_temp_dir(), 'neg');
        file_put_contents($neg, "murah vps\n");
        config(['cloud.gsc.kata_negatif' => $neg]);

        \App\Mcp\Servers\VelocityCloud::tool(\App\Mcp\Tools\KeywordPlanner::class, ['kata' => ['sewa vps']])
            ->assertOk()->assertSee('"kata":"sewa vps"')->assertSee('"di_mapping":true')
            ->assertDontSee('vps murah'); // kena negatif "murah vps" (urutan bebas)

        \App\Mcp\Servers\VelocityCloud::tool(\App\Mcp\Tools\KeywordPlanner::class, ['starter' => 'token-ai'])->assertOk();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'generateKeywordIdeas') && ($r['keywordAndUrlSeed']['url'] ?? null) === 'https://velocitydatacenter.com/token-ai/');

        \App\Mcp\Servers\VelocityCloud::tool(\App\Mcp\Tools\KeywordPlanner::class, [])->assertHasErrors();
    }
}
