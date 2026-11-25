<?php

namespace App\Mcp\Tools;

use App\Models\Promo;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Daftar semua promo bonus token AI di dashboard: id, nama, bonus juta token, minimal pembelian, kategori, masa berlaku, aktif, jumlah klaim, dan omzet yang dihasilkan.')]
class DaftarPromo extends Tool
{
    public function handle(Request $request): Response
    {
        $promo = Promo::withCount('klaim')
            ->withCount(['klaim as pelanggan' => fn ($q) => $q->select(DB::raw('count(distinct user_id)'))])
            ->withSum('klaim as token_total', 'token_juta')
            ->withSum('klaim as omzet', 'nominal')
            ->latest('id')->get()
            ->map(fn (Promo $p) => [
                'id' => $p->id,
                'nama' => $p->nama,
                'bonus_token_juta' => $p->bonus_token_juta,
                'min_pembelian' => $p->min_pembelian,
                'kategori' => $p->kategori,
                'aktif' => $p->aktif,
                'mulai' => $p->mulai?->toDateTimeString(),
                'selesai' => $p->selesai?->toDateTimeString(),
                'klaim' => (int) $p->klaim_count,
                'pelanggan' => (int) $p->pelanggan,
                'token_diberikan_juta' => (int) $p->token_total,
                'omzet' => (int) $p->omzet,
            ]);

        return Response::json($promo->all());
    }
}
