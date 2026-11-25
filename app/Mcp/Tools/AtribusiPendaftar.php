<?php

namespace App\Mcp\Tools;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Atribusi pendaftar baru non-admin (tanpa email/HP/PII): dari sumber/medium dan halaman landing mana pendaftar datang, layanan yang dipesan, serta tagihan belum dibayar. Pakai untuk mengetahui halaman & sumber yang paling menghasilkan pelanggan nyata.')]
class AtribusiPendaftar extends Tool
{
    public function handle(Request $request): Response
    {
        $hari = (int) ($request->validate(['hari' => ['nullable', 'integer', 'min:1', 'max:90']])['hari'] ?? 30);
        $dari = now()->subDays($hari);

        $users = User::where('is_admin', false)
            ->where('created_at', '>=', $dari)
            ->with(['services.plan:id,slug,nama', 'invoices:id,user_id,status,jumlah,created_at'])
            ->latest('id')
            ->limit(30)
            ->get(['id', 'google_id', 'analitik_vid', 'created_at']);

        $vids = $users->pluck('analitik_vid')->filter()->unique()->values();
        $hits = $vids->isEmpty()
            ? collect()
            : DB::table('analitik_hits')
                ->whereIn('vid', $vids)
                ->orderBy('waktu')
                ->get(['vid', 'jenis', 'path', 'sumber', 'medium', 'kampanye', 'event', 'label'])
                ->groupBy('vid');

        $daftar = $users->map(function (User $u) use ($hits) {
            $jejak = $u->analitik_vid ? ($hits[$u->analitik_vid] ?? collect()) : collect();
            $pv = $jejak->where('jenis', 'pv');
            $pertama = $pv->first();
            $lunas = $u->invoices->where('status', InvoiceStatus::Lunas);
            $pending = $u->invoices->where('status', InvoiceStatus::BelumDibayar);

            return [
                'user_id' => $u->id,
                'via' => $u->google_id ? 'Google' : 'Direct',
                'terdaftar' => $u->created_at->toDateString(),
                'sumber_awal' => $pertama ? ($pertama->sumber.' / '.$pertama->medium) : 'tidak terlacak',
                'kampanye' => $pertama->kampanye ?? null,
                'halaman_landing' => $pertama->path ?? null,
                'halaman_dilihat' => $pv->pluck('path')->unique()->take(6)->values()->all(),
                'event_klik' => $jejak->where('jenis', 'ev')->map(fn ($e) => $e->event.($e->label ? ':'.$e->label : ''))->take(5)->values()->all(),
                'paket_dipesan' => $u->services->map(fn ($s) => $s->plan?->slug)->filter()->values()->all(),
                'tagihan_lunas' => $lunas->count(),
                'omzet_lunas' => (int) $lunas->sum('jumlah'),
                'tagihan_pending' => $pending->count(),
                'nilai_pending' => (int) $pending->sum('jumlah'),
            ];
        });

        $ringkasSumber = $daftar->countBy('sumber_awal')->sortDesc()->all();
        $tagihanPending = Invoice::where('status', InvoiceStatus::BelumDibayar)
            ->with('service.plan:id,slug,nama')
            ->whereHas('service.user', fn ($q) => $q->where('is_admin', false))
            ->latest('id')->limit(15)->get()
            ->map(fn (Invoice $i) => [
                'invoice_id' => $i->id,
                'paket' => $i->service?->plan?->slug,
                'jumlah' => (int) $i->jumlah,
                'dibuat' => $i->created_at->toDateString(),
            ]);

        return Response::json([
            'rentang_hari' => $hari,
            'total_pendaftar' => $daftar->count(),
            'pendaftar_per_sumber' => $ringkasSumber,
            'pendaftar' => $daftar->values()->all(),
            'tagihan_belum_dibayar' => $tagihanPending->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'hari' => $schema->integer()->description('Rentang hari ke belakang, bawaan 30 (maks 90).'),
        ];
    }
}
