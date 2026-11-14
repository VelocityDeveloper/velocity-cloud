<?php

namespace App\Console\Commands;

use App\Services\Analitik\Pengurai;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Impor log beacon nginx ke analitik_hits secara bertahap (posisi baca disimpan; tahan logrotate). */
class ImporAnalitik extends Command
{
    protected $signature = 'analitik:impor';

    protected $description = 'Impor kunjungan landing dari log nginx ke database analitik';

    private const POSISI = 'analitik-impor.json';

    public function handle(): int
    {
        $log = config('cloud.analitik.log');
        if (! is_readable($log)) {
            $this->warn("Log $log tidak terbaca.");

            return self::SUCCESS;
        }
        $pengurai = new Pengurai(config('cloud.analitik.ip_internal'));
        $posisi = json_decode(Storage::get(self::POSISI) ?? '{}', true) ?: [];
        $inode = fileinode($log);
        $total = 0;

        // log sudah dirotasi: habiskan sisa berkas lama (.1) dulu, lalu mulai berkas baru dari awal
        if (($posisi['inode'] ?? null) !== null && $posisi['inode'] !== $inode) {
            if (is_readable("$log.1") && fileinode("$log.1") === $posisi['inode']) {
                $total += $this->baca("$log.1", $posisi['offset'], $pengurai);
            }
            $posisi['offset'] = 0;
        }
        $offset = $posisi['offset'] ?? 0;
        if ($offset > filesize($log)) {
            $offset = 0; // berkas dipotong (copytruncate)
        }
        $total += $this->baca($log, $offset, $pengurai, $akhir);
        Storage::put(self::POSISI, json_encode(['inode' => $inode, 'offset' => $akhir]));

        // simpan maksimal N hari
        DB::table('analitik_hits')->where('waktu', '<', now()->subDays(config('cloud.analitik.simpan_hari'))->toDateTimeString())->delete();
        $this->info("$total hit diimpor.");

        return self::SUCCESS;
    }

    private function baca(string $berkas, int $offset, Pengurai $pengurai, ?int &$akhir = null): int
    {
        $f = fopen($berkas, 'r');
        fseek($f, $offset);
        $batch = [];
        $total = 0;
        $akhir = $offset;
        while (($baris = fgets($f)) !== false) {
            if (! str_ends_with($baris, "\n")) {
                break; // baris belum selesai ditulis nginx, baca lagi menit berikutnya
            }
            $akhir = ftell($f);
            if ($hit = $pengurai->urai(trim($baris))) {
                $batch[] = $hit;
            }
            if (count($batch) >= 500) {
                DB::table('analitik_hits')->insert($batch);
                $total += count($batch);
                $batch = [];
            }
        }
        fclose($f);
        if ($batch) {
            DB::table('analitik_hits')->insert($batch);
            $total += count($batch);
        }

        return $total;
    }
}
