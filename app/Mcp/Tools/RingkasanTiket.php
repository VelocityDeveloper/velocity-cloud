<?php

namespace App\Mcp\Tools;

use App\Models\Tiket;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Ringkasan tiket support/billing pelanggan terbaru (tanpa nama/email/PII): judul, jenis, status, paket terkait, dan potongan pesan pertama. Pakai untuk memahami hambatan atau pertanyaan nyata pelanggan sebagai bahan perbaikan landing/FAQ.')]
class RingkasanTiket extends Tool
{
    public function handle(Request $request): Response
    {
        $hari = (int) ($request->validate(['hari' => ['nullable', 'integer', 'min:1', 'max:90']])['hari'] ?? 30);

        $tiket = Tiket::with(['service.plan:id,slug', 'pesan' => fn ($q) => $q->limit(1)])
            ->where('created_at', '>=', now()->subDays($hari))
            ->latest('id')->limit(30)->get()
            ->map(fn (Tiket $t) => [
                'id' => $t->id,
                'jenis' => $t->jenis,
                'status' => $t->status,
                'judul' => $t->judul,
                'paket' => $t->service?->plan?->slug,
                'ringkas_awal' => mb_substr((string) ($t->pesan->first()?->isi ?? ''), 0, 280),
                'dibuat' => $t->created_at->toDateString(),
            ]);

        return Response::json([
            'rentang_hari' => $hari,
            'jumlah' => $tiket->count(),
            'per_status' => $tiket->countBy('status')->all(),
            'per_jenis' => $tiket->countBy('jenis')->all(),
            'tiket' => $tiket->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'hari' => $schema->integer()->description('Rentang hari ke belakang, bawaan 30.'),
        ];
    }
}
