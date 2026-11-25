<?php

namespace App\Services\Agen;

use App\Services\Seo\SearchConsole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Angka satu halaman landing dalam N hari terakhir (sampai kemarin): analitik (tanpa trafik kantor) + Search Console.
 * Dipakai sebagai metrik awal tindakan dan untuk mengevaluasinya; jendela sama panjang agar sebanding.
 */
class MetrikHalaman
{

    public function __construct(private SearchConsole $gsc) {}

    public function ambil(string $path, int $hari = 7, ?CarbonImmutable $kini = null): array
    {
        $sampai = ($kini ?? CarbonImmutable::now())->startOfDay(); // eksklusif: hari ini belum utuh
        $dari = $sampai->subDays($hari);
        $hit = DB::table('analitik_hits')->where('internal', false)->where('path', $path)
            ->where('waktu', '>=', $dari->toDateTimeString())->where('waktu', '<', $sampai->toDateTimeString());

        return [
            'path' => $path,
            'dari' => $dari->toDateString(),
            'sampai' => $sampai->subDay()->toDateString(),
            'tampilan' => (clone $hit)->where('jenis', 'pv')->count(),
            'pengguna' => (clone $hit)->where('jenis', 'pv')->distinct()->count('vid'),
            'event' => (clone $hit)->where('jenis', 'ev')->selectRaw('event, count(*) n')->groupBy('event')->pluck('n', 'event')->map(fn ($n) => (int) $n)->all(),
            'gsc' => $this->gsc($path, $dari, $sampai->subDay()),
        ];
    }

    /** null bila GSC belum terhubung/gagal; nol bila halaman belum tampil di Google. */
    private function gsc(string $path, CarbonImmutable $dari, CarbonImmutable $sampai): ?array
    {
        if (! $this->gsc->terhubung()) {
            return null;
        }
        try {
            $url = 'https://'.Str::after(config('cloud.gsc.situs'), 'sc-domain:').$path; // properti Domain GSC = host landing
            $baris = collect($this->gsc->kueri($dari->toDateString(), $sampai->toDateString(), ['page'], 1000))
                ->first(fn ($r) => rtrim($r['keys'][0], '/') === rtrim($url, '/'));

            return $baris
                ? ['klik' => (int) $baris['clicks'], 'tayangan' => (int) $baris['impressions'], 'ctr' => round((float) $baris['ctr'], 4), 'posisi' => round((float) $baris['position'], 1)]
                : ['klik' => 0, 'tayangan' => 0, 'ctr' => 0, 'posisi' => null];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
