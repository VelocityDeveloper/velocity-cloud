<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Billing\TandaiLunas;
use App\Enums\InvoiceStatus;
use App\Enums\PlanKategori;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Tampil;
use App\Jobs\KirimTagihanCrm;
use App\Models\Invoice;
use App\Services\Crm\TagihanCrm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TagihanController extends Controller
{
    /** Metode pembayaran tagihan lunas: midtrans dari gateway, sisanya dari Tandai lunas admin. */
    private const METODE = ['midtrans' => 'Midtrans', 'transfer' => 'Transfer bank', 'qris' => 'QRIS', 'tunai' => 'Tunai', 'lainnya' => 'Lainnya'];

    public function index(Request $request): Response
    {
        $status = $request->query('status', 'semua');
        $jenis = $request->query('jenis', 'semua');
        $metode = $request->query('metode', 'semua');
        $kategori = PlanKategori::tryFrom($jenis);
        // Filter jenis + metode, dipakai daftar dan kartu ringkasan.
        $saring = fn ($q) => $q
            ->when($kategori, fn ($q, PlanKategori $k) => $q->whereHas('service.plan', fn ($p) => $p->where('kategori', $k)))
            ->when(isset(self::METODE[$metode]), fn ($q) => $q->where('metode', $metode));

        return Inertia::render('admin/Tagihan', [
            'ringkasan' => $this->ringkasan($saring),
            'status' => $status,
            'jenis' => $jenis,
            'metode' => $metode,
            'pilihanJenis' => array_map(fn (PlanKategori $k) => ['value' => $k->value, 'label' => $k->label()], PlanKategori::cases()),
            'pilihanMetode' => array_map(fn ($v, $l) => ['value' => $v, 'label' => $l], array_keys(self::METODE), self::METODE),
            'tagihan' => $saring(Invoice::with('service.plan', 'user'))
                ->when($status !== 'semua', fn ($q) => $q->where('status', $status))
                ->latest()->limit(200)->get()
                ->map(fn (Invoice $i) => [...Tampil::tagihan($i), 'pelanggan' => $i->user->name, 'email' => $i->user->email, 'crm' => $this->crm($i),
                    'midtrans_mode' => $i->metode === 'midtrans' ? TagihanCrm::mode($i) : null]),
        ]);
    }

    /** Kartu ringkasan (ikut filter jenis & metode, tidak ikut filter status). Nominal = jumlah + kode unik, sama dengan kolom Jumlah. */
    private function ringkasan(\Closure $saring): array
    {
        $q = fn () => $saring(Invoice::query()->where('status', '!=', InvoiceStatus::Dibatalkan));
        $hitung = fn ($b) => ['nominal' => (int) (clone $b)->sum(DB::raw('jumlah + coalesce(kode_unik, 0)')), 'jumlah' => $b->count()];
        $lunas = fn () => $q()->where('status', InvoiceStatus::Lunas);
        $bulan = now()->startOfMonth();
        $hari = now()->startOfDay();

        return [
            'tagihan' => $hitung($q()),
            'belum_dibayar' => $hitung($q()->where('status', InvoiceStatus::BelumDibayar)),
            'dibayar' => $hitung($lunas()),
            'dibayar_bulan' => $hitung($lunas()->where('dibayar_pada', '>=', $bulan)),
            'tagihan_bulan' => $hitung($q()->where('created_at', '>=', $bulan)),
            'dibayar_hari' => $hitung($lunas()->where('dibayar_pada', '>=', $hari)),
            'tagihan_hari' => $hitung($q()->where('created_at', '>=', $hari)),
        ];
    }

    /** Status pencatatan di CRM (billing + bank midtrans_vd); null = tagihan ini tidak dikirim ke CRM. */
    private function crm(Invoice $i): ?array
    {
        if (! TagihanCrm::perluDikirim($i)) {
            return null;
        }

        return [
            'terkirim' => (bool) $i->crm_bank_id,
            'billing_id' => $i->crm_billing_id,
            'bank_id' => $i->crm_bank_id,
            'pada' => $i->crm_terkirim_pada?->toDateTimeString(),
            'galat' => $i->crm_galat,
        ];
    }

    /** Kirim (ulang) ke CRM; CRM idempoten lewat nomor tagihan, jadi tidak membuat billing/bank dobel. */
    public function kirimCrm(Invoice $invoice): RedirectResponse
    {
        abort_unless(TagihanCrm::perluDikirim($invoice) && ! $invoice->crm_bank_id, 422);
        $invoice->forceFill(['crm_galat' => null])->save();
        KirimTagihanCrm::dispatch($invoice);
        Inertia::flash('toast', ['type' => 'success', 'message' => $invoice->nomor.' diantrekan ke CRM.']);

        return back();
    }

    public function lunas(Request $request, Invoice $invoice, TandaiLunas $tandai): RedirectResponse
    {
        $data = $request->validate([
            'metode' => ['required', Rule::in(['transfer', 'qris', 'tunai', 'lainnya'])],
            'referensi' => ['nullable', 'string', 'max:100'],
        ]);
        $tandai->handle($invoice, $data['metode'], $data['referensi'] ?? null);
        Inertia::flash('toast', ['type' => 'success', 'message' => $invoice->nomor.' ditandai lunas.']);

        return back();
    }
}
