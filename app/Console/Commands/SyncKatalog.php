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
        foreach (['vps' => PlanKategori::Vps, 'managed' => PlanKategori::Managed] as $kunci => $kategori) {
            foreach ($paket[$kunci] as $i => $p) {
                $baris[] = [
                    'kategori' => $kategori, 'slug' => $kunci.'-'.Str::slug($p['nama']), 'nama' => $p['nama'], 'harga' => $p['harga'],
                    'spek' => array_filter(['cpu' => $p['cpu'], 'ram' => $p['ram'], 'disk' => $p['disk'], 'bandwidth' => $p['bandwidth'] ?? null]),
                    'fitur' => $p['fitur'], 'ringkas' => null, 'urutan' => $i,
                ];
            }
        }
        foreach ($apps as $i => $a) {
            $baris[] = [
                'kategori' => PlanKategori::App, 'slug' => 'app-'.$a['slug'], 'nama' => $a['nama'], 'harga' => $a['harga'],
                'spek' => ['kategori_app' => $a['kategori']], 'fitur' => $a['fitur'], 'ringkas' => $a['ringkas'], 'urutan' => $i,
            ];
        }

        DB::transaction(function () use ($baris) {
            foreach ($baris as $b) {
                // Aktif & stok diatur di Admin › Paket; paket baru langsung aktif.
                $plan = Plan::firstOrNew(['slug' => $b['slug']])->fill($b);
                $plan->exists || $plan->aktif = true;
                $plan->save();
            }
            // Paket yang hilang dari landing dinonaktifkan, bukan dihapus (layanan lama tetap merujuk).
            Plan::whereIn('kategori', [PlanKategori::Vps, PlanKategori::Managed, PlanKategori::App])
                ->whereNotIn('slug', array_column($baris, 'slug'))->update(['aktif' => false]);

            // Token AI tidak ada di landing: dibuat sekali dari config, sesudahnya diatur di Admin › Paket.
            foreach (config('cloud.token_ai.paket') as $i => $p) {
                Plan::firstOrCreate(['slug' => $p['slug']], [
                    'kategori' => PlanKategori::Ai, 'nama' => $p['nama'], 'harga' => $p['harga'], 'urutan' => $i,
                    'spek' => ['model' => $p['model'], 'satuan' => '1 juta token'],
                    'fitur' => ['API kompatibel OpenAI', 'Saldo prabayar, tanpa masa berlaku', 'Input & output harga sama'],
                    'ringkas' => 'Token AI prabayar untuk model '.$p['nama'].' lewat API kompatibel OpenAI.',
                ]);
            }
        });

        $this->info(count($baris).' paket disinkronkan.');

        return self::SUCCESS;
    }
}
