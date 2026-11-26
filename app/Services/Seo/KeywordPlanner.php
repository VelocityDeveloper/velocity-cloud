<?php

namespace App\Services\Seo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Ide kata kunci + volume bulanan dari Google Ads Keyword Planner (KeywordPlanIdeaService.generateKeywordIdeas). Baca saja. */
class KeywordPlanner
{
    /** Lokasi (geoTargetConstants) yang bisa dipilih di halaman. */
    public const LOKASI = ['2360' => 'Indonesia', '1007700' => 'DKI Jakarta', '1007703' => 'Jawa Barat', '1007706' => 'Jawa Timur', '1007705' => 'Jawa Tengah', '1007710' => 'DI Yogyakarta'];

    /** languageConstants: 1025 Indonesia, 1000 Inggris. */
    public const BAHASA = ['1025' => 'Indonesia', '1000' => 'Inggris'];

    /**
     * Starter riset per halaman landing (sekali klik di Keyword Planner): kata kunci dasar (maks 20) + path halaman.
     * Token AI: pilar & cabang dari riset Kang SEO (riset-kata-kunci.md 19 Nov) + model yang dijual di /token-ai/.
     */
    public const STARTER = [
        'token-ai' => ['nama' => 'Token AI', 'path' => '/token-ai/', 'kata' => [
            // 25 Nov: seed bervolume 0 (api ai harga, api chatgpt murah, api ai bayar rupiah) diganti varian bervolume dari Google
            'token ai', 'token ai murah', 'ai token', 'api ai', 'api ai murah', 'api ai indonesia',
            'api openai', 'harga api openai', 'api key openai', 'api chatgpt', 'chatgpt api', 'api gpt',
            'deepseek api', 'api deepseek murah', 'api claude', 'api gemini', 'gemini api', 'api llm',
        ]],
        // 25 Nov: seed produk lain dari pilar/cabang riset Kang SEO (riset-kata-kunci.md), maks 20 per starter
        'cloud-server' => ['nama' => 'Cloud Server', 'path' => '/cloud-server/', 'kata' => [
            'vps', 'vps murah', 'vps indonesia', 'vps murah indonesia', 'cloud vps murah', 'harga vps murah',
            'sewa vps', 'sewa vps murah', 'sewa vps indonesia', 'beli vps', 'beli vps murah', 'cloud server',
            'cloud server murah', 'cloud server harga', 'vps linux', 'vps murah 1 bulan', 'vps murah untuk ai', 'vps terbaik indonesia',
        ]],
        'managed-server' => ['nama' => 'Managed Server', 'path' => '/managed-server/', 'kata' => [
            'managed server', 'managed vps', 'managed vps server', 'managed server vps', 'jasa kelola server',
            'jasa setting vps', 'jasa maintenance server', 'managed server indonesia', 'managed cloud server', 'sewa server managed',
        ]],
        'hosting' => ['nama' => 'Shared Hosting', 'path' => '/hosting/', 'kata' => [
            'hosting', 'hosting murah', 'web hosting', 'hosting indonesia', 'hosting murah indonesia', 'harga hosting',
            'hosting website', 'hosting wordpress', 'hosting wordpress murah', 'hosting directadmin', 'shared hosting',
            'hosting 1 tahun', 'domain dan hosting', 'beli hosting', 'sewa hosting',
        ]],
        'domain' => ['nama' => 'Domain', 'path' => '/domain/', 'kata' => [
            'domain', 'beli domain', 'domain murah', 'cek domain', 'harga domain', 'daftar domain', 'domain com',
            'domain com murah', 'domain id', 'domain co id', 'beli domain murah', 'transfer domain', 'domain indonesia',
        ]],
        'app' => ['nama' => 'Hosting App 1-Klik', 'path' => '/app/', 'kata' => [
            'hosting n8n', 'n8n self hosted', 'vps n8n', 'hosting wordpress', 'hosting waha', 'waha whatsapp api',
            'whatsapp gateway', 'hosting ai agent', 'ai agent self hosting', 'hermes agent', 'hosting mysql',
            'hosting database', 'vps untuk n8n', 'vps untuk ai agent',
        ]],
    ];

    private const PERSAINGAN = ['LOW' => 'rendah', 'MEDIUM' => 'sedang', 'HIGH' => 'tinggi'];

    private const BULAN = ['JANUARY' => 1, 'FEBRUARY' => 2, 'MARCH' => 3, 'APRIL' => 4, 'MAY' => 5, 'JUNE' => 6, 'JULY' => 7, 'AUGUST' => 8, 'SEPTEMBER' => 9, 'OCTOBER' => 10, 'NOVEMBER' => 11, 'DECEMBER' => 12];

    public function siap(): bool
    {
        $c = config('cloud.keyword_planner');

        return collect(['client_id', 'client_secret', 'refresh_token', 'developer_token', 'customer_id'])->every(fn ($k) => filled($c[$k] ?? null));
    }

    /**
     * Disimpan 24 jam per kombinasi isian (data Google bulanan, kuota API terbatas).
     *
     * @param  string[]  $kata  kata kunci dasar (maks 20)
     * @return array<int, array{kata: string, volume: ?int, persaingan: ?string, indeks: ?int, cpc_rendah: ?int, cpc_tinggi: ?int, tren: array}>
     */
    public function ide(array $kata, ?string $url, string $lokasi = '2360', string $bahasa = '1025'): array
    {
        if (! $this->siap()) {
            throw new RuntimeException('Kredensial Keyword Planner (KP_*) belum diisi di .env.');
        }
        $kunci = 'seo:kp:'.md5(json_encode([$kata, $url, $lokasi, $bahasa]));

        return Cache::remember($kunci, 86400, fn () => $this->ambil($kata, $url, $lokasi, $bahasa));
    }

    private function ambil(array $kata, ?string $url, string $lokasi, string $bahasa): array
    {
        $c = config('cloud.keyword_planner');
        $seed = match (true) {
            $kata && $url => ['keywordAndUrlSeed' => ['keywords' => $kata, 'url' => $url]],
            (bool) $url => ['urlSeed' => ['url' => $url]],
            default => ['keywordSeed' => ['keywords' => $kata]],
        };
        $pelanggan = preg_replace('/\D/', '', $c['customer_id']);
        $res = Http::withToken($this->token())->withHeaders([
            'developer-token' => $c['developer_token'],
            'login-customer-id' => preg_replace('/\D/', '', $c['login_customer_id'] ?: $c['customer_id']),
        ])->timeout(60)->post(rtrim($c['api'], '/')."/customers/{$pelanggan}:generateKeywordIdeas", [
            'language' => "languageConstants/{$bahasa}",
            'geoTargetConstants' => ["geoTargetConstants/{$lokasi}"],
            'keywordPlanNetwork' => 'GOOGLE_SEARCH',
            'includeAdultKeywords' => false,
            'pageSize' => 500,
            ...$seed,
        ]);
        if ($res->failed()) {
            $galat = $res->json('error.details.0.errors.0.message') ?? $res->json('error.message') ?? 'HTTP '.$res->status();
            throw new RuntimeException('Google Ads: '.mb_substr($galat, 0, 300));
        }

        return collect($res->json('results') ?? [])->map(function ($r) {
            $m = $r['keywordIdeaMetrics'] ?? [];
            $rp = fn ($mikro) => isset($mikro) ? (int) round($mikro / 1e6) : null;

            return [
                'kata' => $r['text'],
                'volume' => isset($m['avgMonthlySearches']) ? (int) $m['avgMonthlySearches'] : null,
                'persaingan' => self::PERSAINGAN[$m['competition'] ?? ''] ?? null,
                'indeks' => isset($m['competitionIndex']) ? (int) $m['competitionIndex'] : null,
                'cpc_rendah' => $rp($m['lowTopOfPageBidMicros'] ?? null),
                'cpc_tinggi' => $rp($m['highTopOfPageBidMicros'] ?? null),
                // 12 bulan terakhir, urut waktu: [ "2026-01", volume ]
                'tren' => collect($m['monthlySearchVolumes'] ?? [])->map(fn ($b) => [
                    sprintf('%04d-%02d', $b['year'], self::BULAN[$b['month']] ?? 0), (int) ($b['monthlySearches'] ?? 0),
                ])->values()->all(),
            ];
        })->sortByDesc('volume')->values()->all();
    }

    private function token(): string
    {
        $c = config('cloud.keyword_planner');

        return Cache::remember('seo:kp:token', 50 * 60, function () use ($c) {
            $res = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'refresh_token', 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'], 'refresh_token' => $c['refresh_token'],
            ]);
            $token = $res->json('access_token');
            if (! $token) {
                throw new RuntimeException('Token Google gagal: '.($res->json('error_description') ?? $res->json('error') ?? 'HTTP '.$res->status()));
            }

            return $token;
        });
    }
}
