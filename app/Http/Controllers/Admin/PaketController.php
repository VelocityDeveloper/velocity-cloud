<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Katalog\UbahPaket;
use App\Enums\PlanKategori;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\TokenAi\LaporanToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PaketController extends Controller
{
    /** Satu halaman per kategori (sub menu Paket di sidebar): /admin/paket/{vps|managed|app|ai}. */
    public function index(Request $request, PlanKategori $kategori, LaporanToken $laporan): Response
    {
        $periode = $request->validate(['periode' => ['nullable', 'in:'.implode(',', array_keys(LaporanToken::PERIODE))]])['periode'] ?? '30h';

        return Inertia::render('admin/Paket', [
            'kategori' => ['value' => $kategori->value, 'label' => $kategori->label()],
            'paket' => Plan::where('kategori', $kategori)->withCount(['services as terpakai' => fn ($q) => $q->whereIn('status', Plan::STATUS_PAKAI_STOK)])
                ->orderBy('urutan')->get()
                ->map(fn (Plan $p) => [
                    'id' => $p->id,
                    'kategori' => $p->kategori->value,
                    'kategori_label' => $p->kategori->label(),
                    'nama' => $p->nama,
                    'harga' => $p->harga,
                    'aktif' => $p->aktif,
                    'stok' => $p->stok,
                    'terpakai' => $p->terpakai,
                    'bisa_dipesan' => $p->bisaDipesan(),
                    'spek' => $p->spek,
                    // API key tak pernah dikirim ke browser
                    'sumber' => $p->kategori === PlanKategori::Ai ? [
                        'url' => $p->sumber['url'] ?? null,
                        'model' => $p->sumber['model'] ?? null,
                        'ada_key' => filled($p->sumber['key'] ?? null),
                        'model_publik' => $p->spek['model'] ?? null,
                    ] : null,
                ]),
            'analitik' => $kategori === PlanKategori::Ai ? [...$laporan->semua($periode), 'periode' => $periode, 'pilihan' => LaporanToken::PERIODE] : null,
        ]);
    }

    public function update(Request $request, Plan $plan, UbahPaket $ubah): RedirectResponse
    {
        $data = $request->validate([
            'harga' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'aktif' => ['required', 'boolean'],
            'stok' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $situsBerubah = $ubah->handle(
            $plan,
            isset($data['harga']) ? (int) $data['harga'] : null,
            (bool) $data['aktif'],
            isset($data['stok']) ? (int) $data['stok'] : null,
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => $situsBerubah
            ? 'Paket disimpan. Situs diperbarui dalam ±1 menit.'
            : 'Paket disimpan.']);

        return back();
    }

    /** Paket/model token AI baru. Wajib bersumber sendiri (model baru tak ada di peta MODELS env proxy); dibuat nonaktif sampai admin menguji lalu menyalakannya. */
    public function storeAi(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'harga' => ['required', 'integer', 'min:0', 'max:100000000'],
            'ringkas' => ['nullable', 'string', 'max:300'],
            'sumber_url' => ['required', 'url:https,http', 'max:300'],
            'sumber_model' => ['required', 'string', 'max:200'],
            'sumber_key' => ['required', 'string', 'max:1000'],
        ], ['model.regex' => 'Huruf kecil, angka, titik, minus, atau garis bawah, tanpa spasi.']);

        $slug = 'ai-'.str($data['model'])->replace(['.', '_'], '-')->slug(); // deepseek-v4.1-flash → ai-deepseek-v4-1-flash (sama dengan config)
        $kembar = Plan::where('slug', $slug)->exists()
            || Plan::where('kategori', PlanKategori::Ai)->where('spek->model', $data['model'])->exists();
        if ($kembar) {
            throw ValidationException::withMessages(['model' => 'Model ini sudah ada.']);
        }

        Plan::create([
            'kategori' => PlanKategori::Ai,
            'slug' => $slug,
            'nama' => $data['nama'],
            'harga' => $data['harga'],
            'aktif' => false,
            'urutan' => (int) Plan::where('kategori', PlanKategori::Ai)->max('urutan') + 1,
            // bentuk sama dengan paket dari katalog:sync
            'spek' => ['model' => $data['model'], 'satuan' => '1 juta token'],
            'fitur' => ['API kompatibel OpenAI', 'Saldo prabayar, tanpa masa berlaku', 'Input & output harga sama'],
            'ringkas' => ($data['ringkas'] ?? null) ?: 'Token AI prabayar untuk model '.$data['nama'].' lewat API kompatibel OpenAI.',
            'sumber' => ['url' => rtrim($data['sumber_url'], '/'), 'model' => trim($data['sumber_model']), 'key' => trim($data['sumber_key'])],
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Paket AI dibuat (Off). Klik Uji, lalu nyalakan agar tampil di katalog.']);

        return back();
    }

    /** Sumber/tempat kulakan token AI per paket. URL kosong = proxy memakai sumber bawaan (env). */
    public function sumber(Request $request, Plan $plan): RedirectResponse
    {
        abort_unless($plan->kategori === PlanKategori::Ai, 404);
        $data = $request->validate([
            'url' => ['nullable', 'url:https,http', 'max:300'],
            'model' => ['required_with:url', 'nullable', 'string', 'max:200'],
            'key' => [filled($plan->sumber['key'] ?? null) ? 'nullable' : 'required_with:url', 'nullable', 'string', 'max:1000'],
        ]);

        $plan->sumber = blank($data['url'] ?? null) ? null : [
            'url' => rtrim($data['url'], '/'),
            'model' => trim($data['model']),
            'key' => filled($data['key'] ?? null) ? trim($data['key']) : $plan->sumber['key'],
        ];
        $plan->save();
        Inertia::flash('toast', ['type' => 'success', 'message' => $plan->sumber ? 'Sumber AI disimpan. Berlaku untuk request berikutnya.' : 'Kembali ke sumber bawaan proxy.']);

        return back();
    }

    /** Uji sumber tersimpan dengan 1 request kecil (max_tokens 1) agar kunci & nama model ikut terbukti. */
    public function ujiSumber(Plan $plan): RedirectResponse
    {
        abort_unless($plan->kategori === PlanKategori::Ai && $plan->sumber, 404);
        try {
            $res = Http::withToken($plan->sumber['key'])->timeout(30)->post($plan->sumber['url'].'/chat/completions', [
                'model' => $plan->sumber['model'],
                'messages' => [['role' => 'user', 'content' => 'ping']],
                'max_tokens' => 1,
            ]);
            $ok = $res->successful();
            $pesan = $ok ? 'Sumber AI berfungsi.' : 'Sumber AI menolak (HTTP '.$res->status().'): '.str($res->json('error.message') ?? $res->body())->limit(200);
        } catch (\Throwable $e) {
            $ok = false;
            $pesan = 'Sumber AI tak terjangkau: '.str($e->getMessage())->limit(200);
        }
        Inertia::flash('toast', ['type' => $ok ? 'success' : 'error', 'message' => $pesan]);

        return back();
    }
}
