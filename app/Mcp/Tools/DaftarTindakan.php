<?php

namespace App\Mcp\Tools;

use App\Models\AgenCatatan;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Daftar tindakan & laporan tercatat (terbaru dulu, maks 50). Pakai status "disetujui" untuk mengambil usulan yang sudah disetujui admin lalu terapkan, atau tanpa filter untuk mengevaluasi tindakan kemarin.')]
class DaftarTindakan extends Tool
{
    public function handle(Request $request): Response
    {
        $d = $request->validate([
            'jenis' => ['nullable', 'in:laporan,tindakan'],
            'status' => ['nullable', 'in:'.implode(',', AgenCatatan::STATUS)],
            'hari' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);

        return Response::json(AgenCatatan::query()
            ->where('jenis', $d['jenis'] ?? 'tindakan')
            ->when($d['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->where('created_at', '>=', now()->subDays($d['hari'] ?? 14))
            ->latest()->limit(50)->get(['id', 'jenis', 'judul', 'isi', 'status', 'target', 'agen', 'created_at', 'updated_at']));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'jenis' => $schema->string()->enum(['laporan', 'tindakan'])->description('Bawaan tindakan.'),
            'status' => $schema->string()->enum(AgenCatatan::STATUS),
            'hari' => $schema->integer()->description('Rentang hari ke belakang, bawaan 14.'),
        ];
    }
}
