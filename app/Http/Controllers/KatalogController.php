<?php

namespace App\Http\Controllers;

use App\Actions\Order\BuatPesanan;
use App\Enums\PlanKategori;
use App\Http\Presenters\Tampil;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class KatalogController extends Controller
{
    /** Segmen URL /katalog/{kategori} → nilai PlanKategori (dipakai juga sub-menu sidebar). */
    public const KATEGORI_URL = ['app' => 'app', 'cloud' => 'vps', 'managed-cloud' => 'managed', 'ai' => 'ai'];

    public function index(?string $kategori = null): Response
    {
        return Inertia::render('katalog/Index', [
            // Varian tambahan (mis. WordPress Plus) dipilih di halaman pesan app induk, tidak tampil sebagai baris sendiri.
            'paket' => Plan::aktif()->orderBy('kategori')->orderBy('urutan')->get()->reject(fn (Plan $p) => $p->varianTambahan())->values()->map(Tampil::plan(...)),
            'kategori' => self::KATEGORI_URL[$kategori ?? 'app'],
        ]);
    }

    public function show(Plan $plan): Response
    {
        abort_unless($plan->aktif, 404);

        // Paket habis: tawarkan paket sekategori yang lebih murah dan masih bisa dipesan (terdekat dulu).
        $alternatif = $plan->sisaStok() === 0 && $plan->harga !== null
            ? Plan::aktif()->where('kategori', $plan->kategori)->where('harga', '<', $plan->harga)->orderByDesc('harga')->get()
                ->filter(fn (Plan $p) => $p->bisaDipesan())->take(2)->values()->map(Tampil::plan(...))
            : [];

        return Inertia::render('katalog/Pesan', [
            'paket' => Tampil::plan($plan),
            'varian' => $plan->keluargaVarian()->map(fn (Plan $p) => [...Tampil::plan($p), 'nama_varian' => $p->varian()['nama']]),
            'alternatif' => $alternatif,
            'domain_app' => config('cloud.domain_app'),
            'beli_maks_juta' => config('cloud.token_ai.beli_maks_juta'),
            'endpoint_ai' => config('cloud.token_ai.endpoint'),
            'pilihan_os' => $plan->vpsOtomatis() ? collect(config('cloud.vps.os'))->map(fn ($o) => $o['nama']) : null,
        ]);
    }

    public function store(Request $request, Plan $plan, BuatPesanan $buat): RedirectResponse
    {
        abort_unless($plan->bisaDipesan(), 422, $plan->sisaStok() === 0 ? 'Stok paket ini habis.' : 'Paket ini belum bisa dipesan.');

        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:60'], // field dihapus dari form 13 Nov; kosong = nama paket
            'token_juta' => [Rule::requiredIf($plan->kategori === PlanKategori::Ai), 'nullable', 'integer', 'min:1', 'max:'.config('cloud.token_ai.beli_maks_juta')],
            'os' => [Rule::requiredIf($plan->vpsOtomatis()), 'nullable', Rule::in(array_keys(config('cloud.vps.os')))],
        ]);

        $invoice = $buat->handle($request->user(), $plan, $data['label'] ?? $plan->nama, $plan->kategori === PlanKategori::Ai ? (int) $data['token_juta'] : null, $plan->vpsOtomatis() ? ['os' => $data['os']] : []);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pesanan dibuat. Silakan selesaikan pembayaran.']);

        return to_route('tagihan.show', $invoice);
    }
}
