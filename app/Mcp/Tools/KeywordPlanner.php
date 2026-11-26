<?php

namespace App\Mcp\Tools;

use App\Services\Seo\KeywordPlanner as Planner;
use App\Services\Seo\PetaKataKunci;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use RuntimeException;

#[IsReadOnly]
#[Description('Google Ads Keyword Planner (data asli Google, Indonesia): ide kata kunci dari kata dasar dan/atau URL halaman, dengan volume pencarian bulanan, persaingan iklan, CPC atas halaman (Rupiah), dan perubahan 3 bulan terakhir. Kata kunci negatif (Keyword Mapping) sudah dibuang; "di_mapping" = sudah ada di riset-kata-kunci.md. Hasil disimpan 24 jam (sama dengan Admin › SEO › Keyword Planner), jadi panggil seperlunya.')]
class KeywordPlanner extends Tool
{
    public function handle(Request $request, Planner $kp, PetaKataKunci $peta): Response
    {
        $v = $request->validate([
            'kata' => ['nullable', 'array', 'max:20'],
            'kata.*' => ['string', 'max:80'],
            'url' => ['nullable', 'url', 'max:500'],
            'starter' => ['nullable', 'in:'.implode(',', array_keys(Planner::STARTER))],
            'lokasi' => ['nullable', 'in:'.implode(',', array_keys(Planner::LOKASI))],
            'batas' => ['nullable', 'integer', 'min:1', 'max:200'],
            'volume_min' => ['nullable', 'integer', 'min:0'],
        ]);
        $kata = collect($v['kata'] ?? [])->map(fn ($k) => mb_strtolower(trim($k)))->filter()->unique()->values()->all();
        $url = $v['url'] ?? null;
        if ($s = Planner::STARTER[$v['starter'] ?? ''] ?? null) {
            $kata = $kata ?: $s['kata'];
            $url ??= 'https://'.str_replace('sc-domain:', '', config('cloud.gsc.situs')).$s['path'];
        }
        if (! $kata && ! $url) {
            return Response::error('Isi kata (maks 20), url, atau starter ('.implode(', ', array_keys(Planner::STARTER)).').');
        }

        try {
            $hasil = $kp->ide($kata, $url, $v['lokasi'] ?? '2360');
        } catch (RuntimeException $e) {
            return Response::error($e->getMessage());
        }
        $negatif = $peta->negatif();
        $dipetakan = collect($peta->riset())->map(fn ($r) => PetaKataKunci::normal($r['kata']))->flip();
        // negatif = semua kata dari satu baris negatif muncul (urutan bebas), sama dengan PANDUAN-RISET.md
        $kenaNegatif = fn (string $k) => collect($negatif)->contains(fn ($n) => collect(explode(' ', $n))->every(fn ($w) => in_array($w, explode(' ', $k), true)));
        $ide = collect($hasil)->reject(fn ($r) => $kenaNegatif($r['kata']))
            ->filter(fn ($r) => ($r['volume'] ?? 0) >= ($v['volume_min'] ?? 0))
            ->take($v['batas'] ?? 50)
            ->map(fn ($r) => [
                'kata' => $r['kata'],
                'volume' => $r['volume'],
                'persaingan' => $r['persaingan'],
                'cpc' => $r['cpc_tinggi'] === null ? null : 'Rp'.number_format($r['cpc_rendah'] ?? 0, 0, ',', '.').'–'.number_format($r['cpc_tinggi'], 0, ',', '.'),
                'tren_3bln' => self::tren($r['tren']),
                'di_mapping' => $dipetakan->has(PetaKataKunci::normal($r['kata'])),
            ])->values();

        return Response::json([
            'seed' => ['kata' => $kata, 'url' => $url, 'lokasi' => Planner::LOKASI[$v['lokasi'] ?? '2360']],
            'jumlah' => $ide->count(),
            'catatan' => 'Volume = rata-rata bulanan 12 bulan (dibulatkan Google). Persaingan = persaingan pengiklan, bukan KD organik.',
            'ide' => $ide,
        ]);
    }

    /** 3 bulan terakhir vs 3 bulan sebelumnya, mis. "+25%"; null bila data kurang. */
    private static function tren(array $bulan): ?string
    {
        $n = array_column($bulan, 1);
        if (count($n) < 6) {
            return null;
        }
        $baru = array_sum(array_slice($n, -3));
        $lama = array_sum(array_slice($n, -6, 3));

        return $lama === 0 ? null : sprintf('%+d%%', round(($baru - $lama) / $lama * 100));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'kata' => $schema->array()->items($schema->string())->description('Kata kunci dasar, maks 20.'),
            'url' => $schema->string()->description('URL halaman sebagai seed (mis. https://velocitydatacenter.com/cloud-server/).'),
            'starter' => $schema->string()->enum(array_keys(Planner::STARTER))->description('Seed siap pakai per halaman landing (kata + URL).'),
            'lokasi' => $schema->string()->enum(array_keys(Planner::LOKASI))->description('2360 Indonesia (bawaan), 1007700 DKI Jakarta, 1007703 Jabar, 1007706 Jatim, 1007705 Jateng, 1007710 DIY.'),
            'batas' => $schema->integer()->description('Jumlah ide, bawaan 50, maks 200 (urut volume).'),
            'volume_min' => $schema->integer()->description('Buang ide di bawah volume ini.'),
        ];
    }
}
