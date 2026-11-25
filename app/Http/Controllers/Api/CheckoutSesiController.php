<?php

namespace App\Http\Controllers\Api;

use App\Enums\CheckoutStatus;
use App\Http\Controllers\Controller;
use App\Models\CheckoutKlien;
use App\Models\CheckoutSesi;
use App\Services\Pembayaran\Checkout;
use App\Services\Pembayaran\Midtrans;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/** API VDC Pay untuk web klien: buat sesi bayar & cek status. Dokumentasi: docs/vdc-pay.md. */
class CheckoutSesiController extends Controller
{
    public const MENIT_BAWAAN = 60;

    public function store(Request $request): JsonResponse
    {
        /** @var CheckoutKlien $klien */
        $klien = $request->attributes->get('klien');
        $data = $request->validate([
            'ref' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:\-]+$/'],
            'jumlah' => ['required', 'integer', 'min:1000', 'max:100000000'],
            'deskripsi' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'nama' => ['nullable', 'string', 'max:60'],
            'url_sukses' => ['required', 'url', 'max:500'],
            'url_batal' => ['nullable', 'url', 'max:500'],
            'berlaku_menit' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'metadata' => ['nullable', 'array', 'max:20'],
            'metadata.*' => ['nullable', 'string', 'max:255'],
        ]);
        foreach (['url_sukses', 'url_batal'] as $k) {
            if (isset($data[$k]) && ! $klien->urlBoleh($data[$k])) {
                return response()->json(['galat' => "$k harus https dan host-nya terdaftar untuk klien ini.", 'kolom' => $k], 422);
            }
        }
        if (! Midtrans::aktif()) {
            return response()->json(['galat' => 'Pembayaran online sedang tidak tersedia.'], 503);
        }

        // Idempoten per ref: kiriman ulang dengan isi sama mendapat sesi yang sama; ref yang sama dengan isi berbeda ditolak.
        $data['jumlah'] = (int) $data['jumlah']; // "149000" (string) lolos validasi integer
        if ($lama = $klien->sesi()->firstWhere('ref', $data['ref'])) {
            return $this->sesiLama($lama, $data);
        }

        try {
            $sesi = $this->buatSesi($klien, $data);
        } catch (UniqueConstraintViolationException) { // dua permintaan ref sama datang bersamaan
            return $this->sesiLama($klien->sesi()->where('ref', $data['ref'])->firstOrFail(), $data);
        }

        return response()->json($sesi->untukKlien(), 201);
    }

    /** @param array<string, mixed> $data */
    private function sesiLama(CheckoutSesi $lama, array $data): JsonResponse
    {
        $sama = $lama->jumlah === $data['jumlah'] && $lama->email === $data['email'] && $lama->deskripsi === $data['deskripsi'];

        return $sama
            ? response()->json($lama->untukKlien())
            : response()->json(['galat' => 'ref sudah dipakai untuk sesi lain. Pakai ref baru.', 'id' => $lama->uuid], 409);
    }

    /** @param array<string, mixed> $data */
    private function buatSesi(CheckoutKlien $klien, array $data): CheckoutSesi
    {
        return $klien->sesi()->create([
            'ref' => $data['ref'],
            'jumlah' => $data['jumlah'],
            'deskripsi' => $data['deskripsi'],
            'email' => $data['email'],
            'nama' => $data['nama'] ?? null,
            'url_sukses' => $data['url_sukses'],
            'url_batal' => $data['url_batal'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'status' => CheckoutStatus::Dibuat,
            'berlaku_sampai' => now()->addMinutes($data['berlaku_menit'] ?? self::MENIT_BAWAAN),
        ]);
    }

    /** Cadangan webhook: status terkini, disinkronkan dulu ke Midtrans bila masih menunggu dana. */
    public function show(Request $request, string $id, Checkout $checkout): JsonResponse
    {
        $sesi = CheckoutSesi::where('uuid', $id)->where('checkout_klien_id', $request->attributes->get('klien')->id)->firstOrFail();
        if ($sesi->status === CheckoutStatus::Menunggu && isset($sesi->midtrans['order'])) {
            try {
                $checkout->proses($sesi->midtrans['order']);
                $sesi->refresh();
            } catch (Throwable $e) {
                report($e);
            }
        }

        return response()->json($sesi->untukKlien());
    }
}
