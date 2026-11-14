<?php

namespace App\Mcp\Tools;

use App\Services\Bisnis\Ringkasan;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Angka bisnis Velocity Cloud saat ini: pelanggan, layanan per status & kategori, tagihan, omzet lunas bulan ini dan bulan lalu, jumlah paket dijual.')]
class RingkasanBisnis extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::json(Ringkasan::data());
    }
}
