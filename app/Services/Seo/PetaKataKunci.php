<?php

namespace App\Services\Seo;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Peta kata kunci: target & turunan dari berkas riset Kang SEO + kueri GSC 90 hari (dengan halaman tujuannya).
 * Cabang otomatis: A induk dari B bila kata-kata A bagian dari kata-kata B ("sewa vps" → "sewa vps murah");
 * hanya induk terdekat yang ditautkan, jadi satu kata kunci bisa punya beberapa induk. Kolom Induk di riset
 * ditambahkan ke induk otomatis (untuk alternatif/sinonim yang katanya beda, mis. "cloud server" ← "vps"). Disimpan 3 jam.
 */
class PetaKataKunci
{
    public function __construct(private SearchConsole $gsc) {}

    public function semua(bool $segarkan = false): array
    {
        if ($segarkan) {
            Cache::forget('seo:peta');
        }

        return Cache::remember('seo:peta', 3 * 3600, function () {
            $galat = null;
            $gsc = [];
            if ($this->gsc->terhubung()) {
                try {
                    $sampai = CarbonImmutable::yesterday();
                    $gsc = $this->gsc->kueri($sampai->subDays(89)->toDateString(), $sampai->toDateString(), ['query', 'page'], 1000);
                } catch (Throwable $e) {
                    Log::warning('GSC peta gagal', ['galat' => $e->getMessage()]);
                    $galat = mb_substr($e->getMessage(), 0, 300);
                }
            }

            return ['diambil' => now()->toIso8601String(), 'galat' => $galat, ...$this->susun($this->riset(), $gsc, $this->negatif())];
        });
    }

    /** Baris tabel markdown riset: | Tanggal | Kata kunci | Volume | KD | Niat | Sumber | Status | Induk | Nilai jual | (2 kolom akhir opsional) */
    public function riset(?string $isi = null): array
    {
        $berkas = config('cloud.gsc.riset_kata_kunci');
        $isi ??= is_readable($berkas) ? file_get_contents($berkas) : '';
        $hasil = [];
        foreach (preg_split('/\R/', $isi) as $baris) {
            $k = array_map('trim', explode('|', trim($baris, " \t|")));
            if (count($k) < 7 || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $k[0])) {
                continue;
            }
            $status = mb_strtolower($k[6]);
            preg_match('#→\s*(/\S*)#u', $k[6], $url);
            $nilai = trim($k[8] ?? '', ' -');
            $hasil[] = [
                'kata' => $k[1], 'volume' => is_numeric($k[2]) ? (int) $k[2] : null, 'kd' => is_numeric($k[3]) ? (int) $k[3] : null,
                'niat' => $k[4], 'tanggal' => $k[0], 'catatan' => $k[6].($nilai !== '' ? " · nilai jual $nilai" : ''),
                'induk' => array_values(array_filter(array_map(fn ($x) => self::normal($x), explode(',', $k[7] ?? '')), fn ($x) => $x !== '' && $x !== '-')),
                'status' => isset($url[1]) && ! str_contains($status, 'turunan') ? 'target' : (str_contains($status, 'cadangan') ? 'cadangan' : 'turunan'),
                'url' => $url[1] ?? null,
            ];
        }

        return $hasil;
    }

    /** Kata kunci negatif dari berkas (satu per baris, # = komentar), sudah dinormalkan. */
    public function negatif(): array
    {
        $berkas = config('cloud.gsc.kata_negatif');
        $baris = is_readable($berkas) ? file($berkas, FILE_IGNORE_NEW_LINES) : [];

        return array_values(array_unique(array_filter(array_map(fn ($b) => self::normal($b), $baris), fn ($b) => $b !== '' && ! str_starts_with($b, '#'))));
    }

    public function ubahNegatif(string $kata, bool $tambah): void
    {
        $kata = self::normal($kata);
        $daftar = array_values(array_diff($this->negatif(), [$kata]));
        if ($tambah && $kata !== '') {
            $daftar[] = $kata;
        }
        file_put_contents(config('cloud.gsc.kata_negatif'), "# Kata kunci negatif (Admin › SEO › Keyword Mapping). Kata kunci yang memuat SEMUA kata di satu baris tidak dipakai.\n"
            .implode("\n", $daftar)."\n", LOCK_EX);
        Cache::forget('seo:peta');
    }

    /** "vps windows" mengenai "vps murah windows" (semua katanya termuat, urutan bebas). */
    public static function kenaNegatif(string $kata, array $negatif): bool
    {
        $k = explode(' ', $kata);
        foreach ($negatif as $n) {
            if (! array_diff(explode(' ', $n), $k)) {
                return true;
            }
        }

        return false;
    }

    /** "vps murah (induk)" → "vps murah"; "vps murah forex / untuk trading" → "vps murah forex". */
    public static function normal(string $kata): string
    {
        $k = mb_strtolower(trim(explode(' / ', preg_replace('/\s*\(.*?\)/u', '', $kata))[0]));

        return preg_replace('/\s+/u', ' ', $k);
    }

    public function susun(array $riset, array $gscRows, array $negatif = []): array
    {
        $PERINGKAT = ['target' => 3, 'turunan' => 2, 'cadangan' => 1];
        $simpul = [];
        $sembunyi = [];
        foreach ($riset as $r) {
            $id = self::normal($r['kata']);
            if (self::kenaNegatif($id, $negatif)) {
                $sembunyi[$id] = true;

                continue;
            }
            $lama = $simpul[$id] ?? null;
            if ($lama && $PERINGKAT[$lama['status']] >= $PERINGKAT[$r['status']]) {
                $simpul[$id]['volume'] = max($lama['volume'], $r['volume']);
                $simpul[$id]['indukRiset'] = array_unique([...$lama['indukRiset'], ...$r['induk']]);

                continue;
            }
            $simpul[$id] = ['id' => $id, 'jenis' => 'kata', 'kata' => $id, 'sumber' => 'riset', 'status' => $r['status'], 'url' => $r['url'] ?? $lama['url'] ?? null,
                'volume' => max($lama['volume'] ?? null, $r['volume']), 'kd' => $r['kd'], 'niat' => $r['niat'], 'catatan' => $r['catatan'],
                'indukRiset' => array_unique([...$lama['indukRiset'] ?? [], ...$r['induk']]), 'klik' => 0, 'tayangan' => 0, 'posisi' => null];
        }

        // GSC: satu kueri bisa tampil di beberapa halaman → jumlahkan, halaman tujuan = tayangan terbanyak
        $kueri = [];
        foreach ($gscRows as $r) {
            $id = self::normal($r['keys'][0]);
            if (self::kenaNegatif($id, $negatif)) {
                $sembunyi[$id] = true;

                continue;
            }
            $q = $kueri[$id] ??= ['klik' => 0, 'tayangan' => 0, 'bobot' => 0, 'url' => null, 'maks' => -1];
            $q['klik'] += (int) $r['clicks'];
            $q['tayangan'] += (int) $r['impressions'];
            $q['bobot'] += $r['position'] * $r['impressions'];
            if ($r['impressions'] > $q['maks']) {
                [$q['maks'], $q['url']] = [$r['impressions'], parse_url($r['keys'][1], PHP_URL_PATH) ?: '/'];
            }
            $kueri[$id] = $q;
        }
        uasort($kueri, fn ($a, $b) => $b['tayangan'] <=> $a['tayangan']);
        foreach (array_slice($kueri, 0, 300, true) as $id => $q) {
            $gsc = ['klik' => $q['klik'], 'tayangan' => $q['tayangan'], 'posisi' => $q['tayangan'] ? round($q['bobot'] / $q['tayangan'], 1) : null];
            $simpul[$id] = isset($simpul[$id])
                ? [...$simpul[$id], ...$gsc, 'sumber' => 'keduanya', 'url' => $simpul[$id]['url'] ?? $q['url']]
                : ['id' => $id, 'jenis' => 'kata', 'kata' => $id, 'sumber' => 'gsc', 'status' => 'gsc', 'url' => $q['url'],
                    'volume' => null, 'kd' => null, 'niat' => null, 'catatan' => null, ...$gsc];
        }

        // cabang: induk terdekat = himpunan kata terbesar yang termuat
        $kataDari = array_map(fn ($s) => array_unique(explode(' ', $s['kata'])), $simpul);
        $tautan = [];
        foreach ($simpul as $id => $s) {
            $calon = array_filter(array_keys($simpul), fn ($a) => $a !== $id
                && count($kataDari[$a]) < count($kataDari[$id]) && ! array_diff($kataDari[$a], $kataDari[$id]));
            $terdekat = array_filter($calon, fn ($a) => ! array_filter($calon, fn ($b) => $b !== $a
                && count($kataDari[$a]) < count($kataDari[$b]) && ! array_diff($kataDari[$a], $kataDari[$b])));
            // induk dari kolom riset: hanya yang ada di peta (baris riset/kueri GSC)
            $terdekat = array_unique([...$terdekat, ...array_filter($s['indukRiset'] ?? [], fn ($a) => $a !== $id && isset($simpul[$a]))]);
            foreach ($terdekat as $a) {
                $tautan[] = ['dari' => $a, 'ke' => $id, 'jenis' => 'cabang'];
            }
            $simpul[$id]['induk'] = array_values($terdekat);
        }

        // halaman sebagai simpul: kata kunci → halaman tujuannya; kata kunci tanpa induk & tanpa halaman → akar
        $akar = ['id' => '#akar', 'jenis' => 'akar', 'kata' => str_replace('sc-domain:', '', (string) config('cloud.gsc.situs')) ?: 'situs'];
        $halaman = [];
        foreach ($simpul as $id => $s) {
            if ($s['url']) {
                $halaman[$s['url']] = true;
                $tautan[] = ['dari' => $id, 'ke' => 'hal:'.$s['url'], 'jenis' => 'halaman'];
            } elseif (! $s['induk']) {
                $tautan[] = ['dari' => '#akar', 'ke' => $id, 'jenis' => 'akar'];
            }
        }
        foreach (array_keys($halaman) as $u) {
            $tautan[] = ['dari' => '#akar', 'ke' => "hal:$u", 'jenis' => 'akar'];
        }

        return [
            'simpul' => [$akar, ...array_values($simpul), ...array_map(fn ($u) => ['id' => "hal:$u", 'jenis' => 'halaman', 'kata' => $u], array_keys($halaman))],
            'tautan' => $tautan,
            'negatif' => $negatif,
            'disembunyikan' => count($sembunyi),
        ];
    }
}
