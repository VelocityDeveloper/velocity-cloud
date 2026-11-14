<?php

namespace App\Mcp\Tools;

use App\Enums\ServiceStatus;
use App\Models\Service;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Layanan pelanggan terbaru (maks 100): paket, status, harga, tanggal pesan, jatuh tempo, pesan gagal. Tanpa email/kredensial pelanggan.')]
class DaftarLayanan extends Tool
{
    public function handle(Request $request): Response
    {
        $status = $request->validate(['status' => ['nullable', 'in:'.implode(',', array_column(ServiceStatus::cases(), 'value'))]])['status'] ?? null;

        return Response::json(Service::with('plan:id,slug')->when($status, fn ($q) => $q->where('status', $status))
            ->latest()->limit(100)->get()
            ->map(fn (Service $s) => [
                'id' => $s->id, 'paket' => $s->plan->slug, 'status' => $s->status->value, 'harga' => $s->harga,
                'dipesan' => $s->created_at->toDateString(), 'jatuh_tempo' => $s->jatuh_tempo?->toDateString(), 'pesan_gagal' => $s->pesan_gagal,
            ]));
    }

    public function schema(JsonSchema $schema): array
    {
        return ['status' => $schema->string()->enum(array_column(ServiceStatus::cases(), 'value'))->description('Saring per status (opsional).')];
    }
}
