<?php

namespace App\Console\Commands;

use App\Enums\PlanKategori;
use App\Models\Plan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Katalog paket bersumber dari data landing (paket.json, apps.json) agar harga situs = harga dashboard. */
class SyncKatalog extends Command
{
    protected $signature = 'katalog:sync';

    protected $description = 'Sinkronkan paket & app dari data landing page ke tabel plans';

    public function handle(): int
    {
        $dir = rtrim(config('cloud.katalog_dir'), '/');
        $paket = json_decode(file_get_contents("$dir/paket.json"), true, flags: JSON_THROW_ON_ERROR);
        $apps = json_decode(file_get_contents("$dir/apps.json"), true, flags: JSON_THROW_ON_ERROR);

        $baris = [];
        foreach (['vps' => PlanKategori::Vps, 'managed' => PlanKategori::Managed, 'hosting' => PlanKategori::Hosting] as $kunci => $kategori) {
            foreach ($paket[$kunci] ?? [] as $i => $p) { // hosting tanpa cpu/ram
                $baris[] = [
                    'kategori' => $kategori, 'slug' => $kunci.'-'.Str::slug($p['nama']), 'nama' => $p['nama'], 'harga' => $p['harga'],
                    'spek' => array_filter(['cpu' => $p['cpu'] ?? null, 'ram' => $p['ram'] ?? null, 'disk' => $p['disk'], 'bandwidth' => $p['bandwidth'] ?? null]),
                    'fitur' => $p['fitur'], 'ringkas' => null, 'urutan' => $i,
                ];
            }
        }
        $varianBaru = []; // slug varian tambahan: dibuat dengan stok 0 sampai kapasitas node dicek (#16)
        foreach ($apps as $i => $a) {
            $spek = fn ($cpu, $ramMb, $diskGb = null) => Plan::spekPod($a['kategori'], $cpu, $ramMb, $diskGb);
            $baris[] = [
                'kategori' => PlanKategori::App, 'slug' => 'app-'.$a['slug'], 'nama' => $a['nama'], 'harga' => $a['harga'],
                'spek' => isset($a['pod']) ? $spek($a['pod']['cpu'], $a['pod']['ram_mb'], $a['pod']['disk_gb'] ?? Plan::DISK_DEFAULT_GB) : ['kategori_app' => $a['kategori']],
                'fitur' => $a['fitur'], 'ringkas' => $a['ringkas'], 'urutan' => $i,
            ];
            foreach ($a['varian'] ?? [] as $v) {
                $baris[] = $varianBaru[] = [
                    'kategori' => PlanKategori::App, 'slug' => 'app-'.$a['slug'].'-'.Str::slug($v['nama']), 'nama' => $a['nama'].' '.$v['nama'],
                    'harga' => $v['harga'], 'spek' => $spek($v['cpu'], $v['ram_mb'], $v['disk_gb'] ?? Plan::DISK_DEFAULT_GB), 'fitur' => $a['fitur'], 'ringkas' => $a['ringkas'], 'urutan' => $i,
                ];
            }
        }

        DB::transaction(function () use ($baris, $varianBaru) {
            $varianBaru = array_column($varianBaru, 'slug');
            foreach ($baris as $b) {
                // Aktif & stok diatur di Admin › Paket; paket baru langsung aktif, varian baru stok 0.
                $plan = Plan::firstOrNew(['slug' => $b['slug']])->fill($b);
                if (! $plan->exists) {
                    $plan->aktif = true;
                    in_array($b['slug'], $varianBaru, true) && $plan->stok = 0;
                }
                $plan->save();
            }
            // Paket yang hilang dari landing dinonaktifkan, bukan dihapus (layanan lama tetap merujuk). Paket tersembunyi memang tak ada di landing.
            Plan::where('tersembunyi', false)->whereIn('kategori', [PlanKategori::Vps, PlanKategori::Managed, PlanKategori::Hosting, PlanKategori::App])
                ->whereNotIn('slug', array_column($baris, 'slug'))->update(['aktif' => false]);

            // Token AI tidak ada di landing: dibuat sekali dari config, sesudahnya diatur di Admin › Paket.
            foreach (config('cloud.token_ai.paket') as $i => $p) {
                Plan::firstOrCreate(['slug' => $p['slug']], [
                    'kategori' => PlanKategori::Ai, 'nama' => $p['nama'], 'harga' => $p['harga'], 'urutan' => $i,
                    'spek' => ['model' => $p['model'], 'satuan' => '1 juta token'],
                    'fitur' => Plan::FITUR_AI,
                    'ringkas' => 'Token AI prabayar untuk model '.$p['nama'].' lewat API kompatibel OpenAI.',
                ]);
            }
        });

        $this->info(count($baris).' paket disinkronkan.');

        return self::SUCCESS;
    }
}
