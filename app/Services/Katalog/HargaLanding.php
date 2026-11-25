<?php

namespace App\Services\Katalog;

use App\Enums\PlanKategori;
use App\Models\Plan;
use Illuminate\Support\Str;
use RuntimeException;

/** Menulis harga, status on/off & spek paket ke JSON landing (sumber katalog:sync + limit pod di config/cloud.php) agar situs = dashboard. */
class HargaLanding
{
    /** @param  array|null  $spek  app: [cpu, ram_mb, disk_gb?]; vps/managed: [cpu, ram, disk, bandwidth?]; ai: spek lengkap paket (harga_input/harga_cache); null = spek tidak diubah */
    public function simpan(Plan $plan, ?int $harga, bool $aktif = true, ?array $spek = null): void
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
                    if ($spek) {
                        $this->spekPod($a['pod'], $spek);
                    }
                    $ketemu = true;
                }
                // varian: app-<slug>-<slug nama varian>, sama dengan katalog:sync. "aktif": false di varian hanya penanda;
                // on/off varian berlaku dari kolom aktif di database (landing & config varian tidak membacanya).
                foreach ($a['varian'] ?? [] as $k => $v) {
                    if ('app-'.$a['slug'].'-'.Str::slug($v['nama']) === $plan->slug) {
                        $a['varian'][$k]['harga'] = $harga;
                        $this->aktif($a['varian'][$k], $aktif);
                        if ($spek) {
                            $this->spekPod($a['varian'][$k], $spek);
                        }
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
                    if ($plan->kategori === PlanKategori::Ai) {
                        $this->hargaAi($p, $spek ?? $plan->spek);
                    }
                    foreach ($plan->kategori === PlanKategori::Ai ? [] : ($spek ?? []) as $kunci => $nilai) {
                        if (filled($nilai)) {
                            $p[$kunci] = $nilai;
                        } else {
                            unset($p[$kunci]); // bandwidth kosong
                        }
                    }
                    $ketemu = true;
                }
            }
        }
        unset($a, $p);

        // Paket AI yang dibuat di dashboard belum ada di landing: ditambahkan saat pertama diubah/dinyalakan.
        if (! $ketemu && $plan->kategori === PlanKategori::Ai) {
            $p = ['slug' => $plan->slug, 'nama' => $plan->nama, 'model' => $plan->spek['model'] ?? null, 'harga' => $harga];
            $this->hargaAi($p, $spek ?? $plan->spek);
            $this->aktif($p, $aktif);
            $data['ai'][] = $p;
            $ketemu = true;
        }

        if (! $ketemu) {
            throw new RuntimeException("Paket {$plan->slug} tidak ada di ".basename($berkas).'.');
        }

        $this->tulis($berkas, $asli, $data);
    }

    private function tulis(string $berkas, string $asli, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        // Landing memakai indentasi 2 spasi; PHP 4 spasi.
        $json = preg_replace_callback('/^( {4})+/m', fn ($m) => str_repeat(' ', strlen($m[0]) / 2), $json);
        file_put_contents($berkas, $json.(str_ends_with($asli, "\n") ? "\n" : ''), LOCK_EX); // pertahankan baris baru akhir
    }

    /** cpu & ram_mb wajib; disk_gb kosong = kunci dihapus. */
    private function spekPod(array &$item, array $spek): void
    {
        $item['cpu'] = $spek['cpu'];
        $item['ram_mb'] = $spek['ram_mb'];
        if (filled($spek['disk_gb'] ?? null)) {
            $item['disk_gb'] = $spek['disk_gb'];
        } else {
            unset($item['disk_gb']);
        }
    }

    /** Buang paket AI dari paket.json landing. @return bool true jika ada yang dibuang */
    public function hapus(Plan $plan): bool
    {
        $berkas = rtrim(config('cloud.katalog_dir'), '/').'/paket.json';
        $asli = file_get_contents($berkas);
        $data = json_decode($asli, true, flags: JSON_THROW_ON_ERROR);
        $sisa = array_values(array_filter($data['ai'] ?? [], fn ($p) => ($p['slug'] ?? null) !== $plan->slug));
        if (count($sisa) === count($data['ai'] ?? [])) {
            return false;
        }
        $data['ai'] = $sisa;
        $this->tulis($berkas, $asli, $data);

        return true;
    }

    /** Harga input & cache per 1 jt token (harga = output) + konteks (token); kosong = kunci dihapus. */
    private function hargaAi(array &$item, ?array $spek): void
    {
        foreach (['harga_input', 'harga_cache', 'konteks'] as $k) {
            if (isset($spek[$k])) {
                $item[$k] = $spek[$k];
            } else {
                unset($item[$k]);
            }
        }
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
