<?php

namespace App\Mcp\Tools;

use App\Models\AgenCatatan;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Simpan laporan (mis. laporan harian, audit halaman/blog/layanan, evaluasi tindakan kemarin) agar tampil di Admin › Agent dashboard. Isi berformat Markdown.')]
class CatatLaporan extends Tool
{
    public function handle(Request $request): Response
    {
        $d = $request->validate([
            'judul' => ['required', 'string', 'max:200'],
            'isi' => ['required', 'string', 'max:60000'],
            'agen' => ['nullable', 'string', 'max:60'],
        ]);
        $c = AgenCatatan::create(['jenis' => 'laporan', 'judul' => $d['judul'], 'isi' => $d['isi'], 'agen' => $d['agen'] ?? 'Dudung']);

        return Response::text("Laporan #{$c->id} tersimpan.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'judul' => $schema->string()->description('Mis. "Laporan harian 12 Nov 2026".')->required(),
            'isi' => $schema->string()->description('Markdown.')->required(),
            'agen' => $schema->string()->description('Nama agen, bawaan Dudung.'),
        ];
    }
}
