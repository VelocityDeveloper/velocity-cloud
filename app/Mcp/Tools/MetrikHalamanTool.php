<?php

namespace App\Mcp\Tools;

use App\Services\Agen\MetrikHalaman;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('metrik-halaman')]
#[Description('Angka satu halaman landing (path, mis. /app/n8n/) untuk N hari terakhir sampai kemarin: tampilan, pengguna, event klik, dan Search Console (klik, tayangan, CTR, posisi). Pakai untuk memilih halaman yang perlu diperbaiki.')]
class MetrikHalamanTool extends Tool
{
    public function handle(Request $request, MetrikHalaman $metrik): Response
    {
        $d = $request->validate(['path' => ['required', 'string', 'regex:#^/[a-z0-9/_-]*$#', 'max:200'], 'hari' => ['nullable', 'integer', 'min:1', 'max:90']]);

        return Response::json($metrik->ambil($d['path'], $d['hari'] ?? 7));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('Path halaman landing dengan garis miring akhir, mis. /cloud-server/.')->required(),
            'hari' => $schema->integer()->description('Panjang jendela, bawaan 7.'),
        ];
    }
}
