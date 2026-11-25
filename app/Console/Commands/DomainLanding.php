<?php

namespace App\Console\Commands;

use App\Jobs\DeployLanding;
use App\Models\Pengaturan;
use App\Services\Crm\DomainCrm;
use Illuminate\Console\Command;

/** Harga TLD yang dijual (Admin › Paket › Domain, harga dari CRM) → src/data/domain.json landing; berubah = build ulang landing. */
class DomainLanding extends Command
{
    protected $signature = 'domain:landing';

    protected $description = 'Tulis harga domain yang dijual ke data landing, build ulang bila berubah';

    public function handle(DomainCrm $crm): int
    {
        $berubah = self::tulis($crm->daftarTld());
        $this->info($berubah ? 'domain.json diperbarui, build landing diantrekan.' : 'domain.json sudah sesuai.');

        return self::SUCCESS;
    }

    /** @param array $daftar hasil DomainCrm::daftarTld() */
    public static function tulis(array $daftar): bool
    {
        $aktif = Pengaturan::tldDomain();
        $baris = collect($daftar)->filter(fn ($t) => in_array($t['tld'], $aktif, true))->map(function ($t) {
            $satu = collect($t['harga'])->sortBy('tahun')->first(); // periode terpendek (umumnya 1 tahun)

            return ['tld' => $t['tld'], 'tahun' => $satu['tahun'] ?? 1, 'register' => $satu['register'] ?? null, 'transfer' => $satu['transfer'] ?? null];
        })->filter(fn ($t) => $t['register'] !== null || $t['transfer'] !== null)
            ->sortBy(fn ($t) => [$t['register'] ?? PHP_INT_MAX, $t['tld']])->values()->all();

        $berkas = rtrim(config('cloud.katalog_dir'), '/').'/domain.json';
        $json = json_encode($baris, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        if (is_file($berkas) && file_get_contents($berkas) === $json) {
            return false;
        }
        file_put_contents($berkas, $json, LOCK_EX);
        DeployLanding::dispatch();

        return true;
    }
}
