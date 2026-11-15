<?php

namespace App\Services\Katalog;

use App\Enums\PlanKategori;
use App\Models\Plan;
use Illuminate\Support\Str;
use RuntimeException;

/** Menulis harga & status on/off paket ke JSON landing (sumber katalog:sync) agar situs = dashboard. */
class HargaLanding
{
    public function simpan(Plan $plan, ?int $harga, bool $aktif = true): void
    {
        $dir = rtrim(config('cloud.katalog_dir'), '/');
        $berkas = $plan->kategori === PlanKategori::App ? "$dir/apps.json" : "$dir/paket.json";
        $asli = file_get_contents($berkas);
        $data = json_decode($asli, true, flags: JSON_THROW_ON_ERROR);

        $ketemu = false;
        if ($plan->kategori === PlanKategori::App) {
            foreach ($data as &$a) {
                if ('app-'.$a['slug'] === $plan->slug) {
                    $a['harga'] = $harga;
                    $this->aktif($a, $aktif);
                    $ketemu = true;
                }
                // varian: app-<slug>-<slug nama varian>, sama dengan katalog:sync. "aktif": false di varian hanya penanda;
                // on/off varian berlaku dari kolom aktif di database (landing & config varian tidak membacanya).
                foreach ($a['varian'] ?? [] as $k => $v) {
                    if ('app-'.$a['slug'].'-'.Str::slug($v['nama']) === $plan->slug) {
                        $a['varian'][$k]['harga'] = $harga;
                        $this->aktif($a['varian'][$k], $aktif);
                        $ketemu = true;
                    }
                }
            }
        } else {
            $kunci = $plan->kategori->value; // vps | managed | ai
            $data[$kunci] ??= []; // tanpa ini foreach & mengubah salinan, bukan $data
            foreach ($data[$kunci] as &$p) {
                // ai menulis slug sendiri (Str::slug membuang titik: "V4.1" ≠ slug config "v4-1")
                if (($p['slug'] ?? $kunci.'-'.Str::slug($p['nama'])) === $plan->slug) {
                    $p['harga'] = $harga;
                    $this->aktif($p, $aktif);
                    $ketemu = true;
                }
            }
        }
        unset($a, $p);

        if (! $ketemu) {
            throw new RuntimeException("Paket {$plan->slug} tidak ada di ".basename($berkas).'.');
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        // Landing memakai indentasi 2 spasi; PHP 4 spasi.
        $json = preg_replace_callback('/^( {4})+/m', fn ($m) => str_repeat(' ', strlen($m[0]) / 2), $json);
        file_put_contents($berkas, $json.(str_ends_with($asli, "\n") ? "\n" : ''), LOCK_EX); // pertahankan baris baru akhir
    }

    /** Off = "aktif": false; on = kunci dihapus agar JSON tetap ringkas. */
    private function aktif(array &$item, bool $aktif): void
    {
        if ($aktif) {
            unset($item['aktif']);
        } else {
            $item['aktif'] = false;
        }
    }
}
