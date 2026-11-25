<?php

namespace App\Actions\Katalog;

use App\Enums\PlanKategori;
use App\Jobs\DeployLanding;
use App\Models\Plan;
use App\Services\Katalog\HargaLanding;

/** Ubah harga/aktif/stok/spek paket; harga, on/off & spek ikut ditulis ke landing lalu situs dibangun ulang. Dipakai Admin › Paket & MCP. */
class UbahPaket
{
    public function __construct(private HargaLanding $landing) {}

    /**
     * @param  array|null  $spek  app: [cpu, ram_mb, disk_gb?] (limit pod baru, berlaku untuk pesanan berikutnya); vps/managed: [cpu, ram, disk, bandwidth?]; ai: [harga_input, harga_cache] (null = kunci dihapus); null = tetap
     * @return bool true jika situs ikut diperbarui
     */
    public function handle(Plan $plan, ?int $harga, bool $aktif, ?int $stok, ?array $spek = null): bool
    {
        $spekBaru = match (true) {
            $spek === null => $plan->spek,
            $plan->kategori === PlanKategori::Ai => array_filter([...$plan->spek ?? [], ...$spek], fn ($v) => $v !== null),
            // disk dibuang dulu: disk_gb kosong = kunci disk hilang
            $plan->kategori === PlanKategori::App => [...array_diff_key($plan->spek ?? [], ['disk' => 1]), ...Plan::spekPod($plan->spek['kategori_app'] ?? null, $spek['cpu'], $spek['ram_mb'], $spek['disk_gb'] ?? null)],
            default => array_filter($spek, 'filled'),
        };
        $spekBerubah = $spekBaru != $plan->spek;
        // paket tersembunyi tidak ada di landing
        $situsBerubah = ! $plan->tersembunyi && ($plan->harga !== $harga || $plan->aktif !== $aktif || $spekBerubah);
        if ($situsBerubah) {
            $this->landing->simpan($plan, $harga, $aktif, $spekBerubah ? ($plan->kategori === PlanKategori::Ai ? $spekBaru : $spek) : null); // tulis JSON dulu: gagal = DB tidak berubah
        }
        $plan->update(['harga' => $harga, 'aktif' => $aktif, 'stok' => $stok, 'spek' => $spekBaru]);

        if ($situsBerubah) {
            DeployLanding::dispatch();
        }

        return $situsBerubah;
    }
}
