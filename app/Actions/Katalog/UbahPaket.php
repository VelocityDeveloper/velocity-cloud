<?php

namespace App\Actions\Katalog;

use App\Enums\PlanKategori;
use App\Jobs\DeployLanding;
use App\Models\Plan;
use App\Services\Katalog\HargaLanding;

/** Ubah harga/aktif/stok paket; harga & on/off ikut ditulis ke landing lalu situs dibangun ulang. Dipakai Admin › Paket & MCP. */
class UbahPaket
{
    public function __construct(private HargaLanding $landing) {}

    /** @return bool true jika situs ikut diperbarui */
    public function handle(Plan $plan, ?int $harga, bool $aktif, ?int $stok): bool
    {
        // Token AI tidak tampil di landing: cukup DB.
        $situsBerubah = $plan->kategori !== PlanKategori::Ai && ($plan->harga !== $harga || $plan->aktif !== $aktif);
        if ($situsBerubah) {
            $this->landing->simpan($plan, $harga, $aktif); // tulis JSON dulu: gagal = DB tidak berubah
        }
        $plan->update(['harga' => $harga, 'aktif' => $aktif, 'stok' => $stok]);

        if ($situsBerubah) {
            DeployLanding::dispatch();
        }

        return $situsBerubah;
    }
}
