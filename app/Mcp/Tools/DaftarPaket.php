<?php

namespace App\Mcp\Tools;

use App\Models\Plan;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Semua paket Cloud Server (vps), Managed Server (managed), dan App: slug, harga/bulan, aktif (dijual), stok (null = tanpa batas), terpakai, bisa dipesan, spek, fitur.')]
class DaftarPaket extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::json(Plan::withCount(['services as terpakai' => fn ($q) => $q->whereIn('status', Plan::STATUS_PAKAI_STOK)])
            ->orderBy('kategori')->orderBy('urutan')->get()
            ->map(fn (Plan $p) => [
                'slug' => $p->slug, 'kategori' => $p->kategori->value, 'nama' => $p->nama, 'harga' => $p->harga,
                'aktif' => $p->aktif, 'stok' => $p->stok, 'terpakai' => $p->terpakai, 'bisa_dipesan' => $p->bisaDipesan(),
                'spek' => $p->spek, 'fitur' => $p->fitur,
            ]));
    }
}
