<?php

namespace App\Services\Seo;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Ringkasan, tren, kata kunci, halaman, perangkat dari GSC. Disimpan 3 jam per periode (data GSC terlambat 2-3 hari). */
class LaporanSeo
{
    public const PERIODE = ['7h' => '7 hari terakhir', '28h' => '28 hari terakhir', '90h' => '90 hari terakhir', '16b' => '16 bulan terakhir'];

    private const HARI = ['7h' => 7, '28h' => 28, '90h' => 90, '16b' => 486];

    public function __construct(private SearchConsole $gsc) {}

    public function semua(string $periode, bool $segarkan = false): array
    {
        if (! $this->gsc->terhubung()) {
            return ['status' => 'belum_terhubung'];
        }
        $kunci = "gsc:laporan:$periode";
        if ($segarkan) {
            Cache::forget($kunci);
        }

        try {
            return Cache::remember($kunci, 3 * 3600, fn () => ['status' => 'ok', 'diambil' => now()->toIso8601String(), ...$this->hitung($periode)]);
        } catch (Throwable $e) {
            Log::warning('GSC gagal', ['galat' => $e->getMessage()]);

            return ['status' => 'galat', 'galat' => mb_substr($e->getMessage(), 0, 300)];
        }
    }

    private function hitung(string $periode): array
    {
        $n = self::HARI[$periode];
        $sampai = CarbonImmutable::yesterday();
        $dari = $sampai->subDays($n - 1);
        [$dariLalu, $sampaiLalu] = [$dari->subDays($n), $dari->subDay()];
        $f = fn (CarbonImmutable $d) => $d->toDateString();

        $tren = $this->harian($this->gsc->kueri($f($dari), $f($sampai), ['date']), $dari, $n);
        $trenLalu = $this->harian($this->gsc->kueri($f($dariLalu), $f($sampaiLalu), ['date']), $dariLalu, $n);
        $baris = fn (array $dim, int $batas) => array_map(fn ($r) => [
            'nama' => $dim === ['page'] ? $this->path($r['keys'][0]) : $r['keys'][0],
            ...$this->metrik($r),
        ], $this->gsc->kueri($f($dari), $f($sampai), $dim, $batas));

        return [
            'dari' => $f($dari), 'sampai' => $f($sampai),
            'ringkasan' => $this->ringkas($tren), 'ringkasan_lalu' => $this->ringkas($trenLalu),
            'tren' => $tren, 'tren_lalu' => $trenLalu,
            'kueri' => $baris(['query'], 100),
            'halaman' => $baris(['page'], 100),
            'perangkat' => $baris(['device'], 10),
            'negara' => $baris(['country'], 20),
        ];
    }

    private function metrik(array $r): array
    {
        return [
            'klik' => (int) $r['clicks'], 'tayangan' => (int) $r['impressions'],
            'ctr' => (float) $r['ctr'], 'posisi' => round((float) $r['position'], 1),
        ];
    }

    /** Baris per tanggal, hari tanpa data = 0 agar grafik utuh. */
    public function harian(array $rows, CarbonImmutable $dari, int $n): array
    {
        $ada = collect($rows)->keyBy(fn ($r) => $r['keys'][0]);

        return collect(range(0, $n - 1))->map(function ($i) use ($dari, $ada) {
            $tgl = $dari->addDays($i)->toDateString();
            $r = $ada[$tgl] ?? null;

            return ['label' => $dari->addDays($i)->format('d/m'), ...($r ? $this->metrik($r) : ['klik' => 0, 'tayangan' => 0, 'ctr' => 0.0, 'posisi' => null])];
        })->all();
    }

    /** Posisi rata-rata ditimbang tayangan, seperti angka di GSC. */
    public function ringkas(array $tren): array
    {
        $klik = array_sum(array_column($tren, 'klik'));
        $tayangan = array_sum(array_column($tren, 'tayangan'));
        $bobot = array_sum(array_map(fn ($t) => ($t['posisi'] ?? 0) * $t['tayangan'], $tren));

        return [
            'klik' => $klik, 'tayangan' => $tayangan,
            'ctr' => $tayangan ? $klik / $tayangan : 0.0,
            'posisi' => $tayangan ? round($bobot / $tayangan, 1) : 0.0,
        ];
    }

    private function path(string $url): string
    {
        return (parse_url($url, PHP_URL_PATH) ?: '/').(($q = parse_url($url, PHP_URL_QUERY)) ? "?$q" : '');
    }
}
