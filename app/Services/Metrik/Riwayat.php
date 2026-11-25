<?php

namespace App\Services\Metrik;

use Illuminate\Database\Eloquent\Builder;

/** Titik grafik dari tabel sampel per menit (service_metrik, node_metrik). */
class Riwayat
{
    public const RENTANG = ['1j' => 3600, '6j' => 21600, '24j' => 86400, '7h' => 604800];

    /**
     * Titik ≤120 (rata-rata per jendela). $nilai = kolom yang dirata-rata apa adanya;
     * $laju = penghitung kumulatif → per detik dari selisih baris berurutan (reset penghitung = null).
     */
    public static function titik(Builder $q, string $rentang, array $nilai, array $laju = []): array
    {
        $detik = self::RENTANG[$rentang] ?? 3600;
        $baris = $q->where('waktu', '>=', time() - $detik)->orderBy('waktu')->get(['waktu', ...$nilai, ...$laju]);
        $jendela = max(60, intdiv($detik, 120));
        $grup = [];
        $lalu = null;
        foreach ($baris as $b) {
            $t = [];
            foreach ($nilai as $k) {
                $t[$k] = $b->$k;
            }
            foreach ($laju as $k) {
                $t[$k] = $lalu && $lalu->$k && $b->$k !== null && $b->$k >= $lalu->$k && $b->waktu > $lalu->waktu
                    ? ($b->$k - $lalu->$k) / ($b->waktu - $lalu->waktu) : null;
            }
            $grup[intdiv($b->waktu, $jendela)][] = $t;
            $lalu = $b;
        }
        $rata = fn (array $g, string $k) => ($v = array_filter(array_column($g, $k), fn ($x) => $x !== null)) ? array_sum($v) / count($v) : null;

        return [
            'rentang' => $rentang,
            'titik' => array_values(array_map(function ($g, $i) use ($rata, $jendela, $nilai, $laju) {
                $t = ['t' => $i * $jendela];
                foreach ([...$nilai, ...$laju] as $k) {
                    $t[$k] = $rata($g, $k);
                }

                return $t;
            }, $grup, array_keys($grup))),
        ];
    }
}
