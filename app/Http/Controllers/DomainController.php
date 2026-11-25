<?php

namespace App\Http\Controllers;

use App\Actions\Order\PesanDomain;
use App\Models\Pengaturan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/** Pesan domain (register/transfer): harga & ketersediaan dari CRM, bayar di sini, lunas → KirimDomainCrm. */
class DomainController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('domain/Index', [
            'tld' => Pengaturan::tldDomain(),
            'kontak' => PesanDomain::kontakTerakhir($request->user()),
            'q' => mb_substr((string) $request->query('q'), 0, 253), // dari form cari di landing /domain/
        ]);
    }

    public function cek(Request $request, PesanDomain $pesan): JsonResponse
    {
        $domain = $this->domain($request);
        try {
            $info = $pesan->info($domain);
        } catch (RuntimeException $e) {
            report($e);

            return response()->json(['message' => 'Cek domain sedang bermasalah, coba lagi beberapa saat.'], 503);
        }

        return $info ? response()->json($info) : response()->json(['domain' => $domain, 'message' => 'Ekstensi domain ini belum kami jual.'], 404);
    }

    public function store(Request $request, PesanDomain $pesan): RedirectResponse
    {
        $domain = $this->domain($request);
        $data = $request->validate([
            'jenis' => ['required', Rule::in(['Register', 'Transfer'])],
            'periode' => ['required', 'integer', 'min:1', 'max:10'],
            'epp' => ['nullable', 'required_if:jenis,Transfer', 'string', 'max:255'],
            'kontak.nama' => ['required', 'string', 'max:255'],
            'kontak.email' => ['required', 'email', 'max:255'],
            'kontak.hp' => ['required', 'string', 'max:30', 'regex:/^[0-9 +().-]{8,30}$/'],
            'kontak.perusahaan' => ['nullable', 'string', 'max:255'],
            'kontak.alamat' => ['required', 'string', 'max:255'],
            'kontak.kota' => ['required', 'string', 'max:100'],
            'kontak.provinsi' => ['required', 'string', 'max:100'],
            'kontak.kode_pos' => ['required', 'string', 'regex:/^\d{5}$/'],
        ], ['epp.required_if' => 'Kode EPP wajib diisi untuk transfer.', 'kontak.kode_pos.regex' => 'Kode pos 5 angka.']);

        $invoice = $pesan->handle($request->user(), $domain, $data['jenis'], (int) $data['periode'], $data['epp'] ?? null, $data['kontak']);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pesanan domain dibuat. Silakan selesaikan pembayaran.']);

        return to_route('tagihan.show', $invoice);
    }

    private function domain(Request $request): string
    {
        $request->merge(['domain' => PesanDomain::normalisasi((string) $request->input('domain'))]);

        return $request->validate(['domain' => ['required', 'string', 'max:253', 'regex:'.PesanDomain::POLA]], ['domain.regex' => 'Format domain tidak valid, contoh: tokoanda.com.'])['domain'];
    }
}
