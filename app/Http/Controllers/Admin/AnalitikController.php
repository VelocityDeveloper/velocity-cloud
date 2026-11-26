<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Analitik\Laporan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalitikController extends Controller
{
    /** Web dilayani `artisan serve` 4 worker: tiap koneksi SSE menahan 1 worker, jadi dibatasi sekian koneksi sekaligus. */
    private const SLOT_SSE = 2;

    /** Umur satu koneksi SSE (detik); sesudahnya ditutup dan EventSource menyambung ulang membawa Last-Event-ID. */
    private const UMUR_SSE = 24;

    public function index(Request $request): Response
    {
        $filter = $request->validate([
            'periode' => ['nullable', 'in:'.implode(',', array_keys(Laporan::PERIODE))],
            'internal' => ['nullable', 'boolean'],
        ]);
        $periode = $filter['periode'] ?? '7h';
        $internal = (bool) ($filter['internal'] ?? false);

        return Inertia::render('admin/Analitik', [
            'filter' => ['periode' => $periode, 'internal' => $internal],
            'pilihanPeriode' => Laporan::PERIODE,
            'laporan' => (new Laporan(tanpaInternal: ! $internal))->semua($periode),
        ]);
    }

    /** SSE: kirim event `berubah` saat ada hit, tagihan, atau user baru; halaman lalu memuat ulang prop laporan. */
    public function stream(Request $request): StreamedResponse
    {
        $slot = null;
        for ($i = 0; $i < self::SLOT_SSE && ! $slot; $i++) {
            $lock = Cache::lock("analitik-sse-$i", self::UMUR_SSE + 15); // TTL membebaskan slot bila proses mati di tengah jalan
            $slot = $lock->get() ? $lock : null;
        }
        $terakhir = (string) $request->header('Last-Event-ID', '');

        return response()->stream(function () use ($slot, $terakhir) {
            $kirim = function (string $teks) {
                echo $teks;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };
            if (! $slot) {
                $kirim("retry: 30000\n: slot penuh\n\n"); // halaman tetap punya poll 60 dtk

                return;
            }
            try {
                set_time_limit(self::UMUR_SSE + 10);
                $sidik = self::sidik();
                $kirim("retry: 1000\nid: $sidik\n".($terakhir !== '' && $terakhir !== $sidik ? "event: berubah\n" : '')."data: $sidik\n\n");
                for ($t = 0; $t < self::UMUR_SSE / 2 && ! connection_aborted(); $t++) {
                    sleep(2);
                    $baru = self::sidik();
                    $kirim($baru === $sidik ? ": ping\n\n" : "id: $baru\nevent: berubah\ndata: $baru\n\n");
                    $sidik = $baru;
                }
            } finally {
                $slot->release();
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-transform', 'X-Accel-Buffering' => 'no']);
    }

    /** Sidik murah (indeks PK/kolom kecil) dari semua sumber data laporan. */
    public static function sidik(): string
    {
        return implode('.', [
            (int) DB::table('analitik_hits')->max('id'),
            (int) DB::table('users')->max('id'),
            (int) DB::table('invoices')->max('id'),
            strtotime((string) DB::table('invoices')->max('updated_at')) ?: 0,
        ]);
    }
}
