<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PlanKategori;
use App\Http\Controllers\Controller;
use App\Models\Promo;
use App\Models\PromoKlaim;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PromoController extends Controller
{
    private const FORMAT = 'Y-m-d\TH:i'; // nilai input datetime-local (zona aplikasi = WIB)

    public function index(Request $request): Response
    {
        $pilih = $request->integer('promo') ?: null;

        $promo = Promo::withCount('klaim')
            ->withCount(['klaim as pelanggan' => fn ($q) => $q->select(DB::raw('count(distinct user_id)'))])
            ->withSum('klaim as token_total', 'token_juta')->withSum('klaim as omzet', 'nominal')
            ->latest('id')->get()->map(fn (Promo $p) => [
                ...$p->only('id', 'nama', 'bonus_token_juta', 'min_pembelian', 'kategori', 'aktif', 'klaim_count', 'pelanggan'),
                'mulai' => $p->mulai?->format(self::FORMAT),
                'selesai' => $p->selesai?->format(self::FORMAT),
                'status' => $this->status($p),
                'token_total' => (int) $p->token_total,
                'omzet' => (int) $p->omzet,
            ]);

        $klaim = PromoKlaim::with(['promo:id,nama', 'user:id,name,email', 'invoice.service.plan:id,nama', 'tokenService.tokenAi'])
            ->when($pilih, fn ($q) => $q->where('promo_id', $pilih))
            ->latest('id')->limit(300)->get()->map(fn (PromoKlaim $k) => [
                'id' => $k->id,
                'promo' => $k->promo?->nama,
                'pelanggan' => $k->user?->name,
                'email' => $k->user?->email,
                'invoice' => $k->invoice?->nomor,
                'paket' => $k->invoice?->service?->plan?->nama,
                'nominal' => $k->nominal,
                'token_juta' => $k->token_juta,
                'saldo_token' => $k->tokenService?->tokenAi?->saldo,
                'terpakai_token' => $k->tokenService?->tokenAi?->terpakai,
                'waktu' => $k->created_at->format('d M Y H:i'),
            ]);

        return Inertia::render('admin/Promo', ['promo' => $promo, 'klaim' => $klaim, 'pilih' => $pilih]);
    }

    public function store(Request $request): RedirectResponse
    {
        Promo::create($this->valid($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Promo ditambahkan.']);

        return back();
    }

    public function update(Request $request, Promo $promo): RedirectResponse
    {
        $promo->update($this->valid($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Promo disimpan.']);

        return back();
    }

    /** Promo yang sudah dipakai tidak dihapus agar riwayat klaim tetap ada; nonaktifkan saja. */
    public function destroy(Promo $promo): RedirectResponse
    {
        abort_if($promo->klaim()->exists(), 422, 'Promo sudah dipakai pelanggan. Nonaktifkan saja.');
        $promo->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Promo dihapus.']);

        return back();
    }

    private function valid(Request $request): array
    {
        return $request->validate([
            'nama' => ['required', 'string', 'max:100'],
            'bonus_token_juta' => ['required', 'integer', 'min:1', 'max:1000'],
            'min_pembelian' => ['required', 'integer', 'min:0', 'max:100000000'],
            'kategori' => ['required', 'array', 'min:1'],
            'kategori.*' => [Rule::enum(PlanKategori::class)],
            'mulai' => ['nullable', 'date'],
            'selesai' => ['nullable', 'date', 'after_or_equal:mulai'],
            'aktif' => ['boolean'],
        ], ['selesai.after_or_equal' => 'Tanggal selesai harus sesudah tanggal mulai.']);
    }

    private function status(Promo $p): string
    {
        return match (true) {
            ! $p->aktif => 'nonaktif',
            $p->selesai && $p->selesai->isPast() => 'berakhir',
            $p->mulai && $p->mulai->isFuture() => 'terjadwal',
            default => 'berlaku',
        };
    }
}
