<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\KirimWebhookCheckout;
use App\Models\CheckoutKlien;
use App\Models\CheckoutWebhook;
use Illuminate\Bus\UniqueLock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Admin › Pembayaran › VDC Pay: daftar web klien, API key, dan kirim ulang webhook yang gagal. */
class CheckoutKlienController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $klien = CheckoutKlien::create($this->valid($request, null));
        $this->tampilkanKunci($klien);

        return back();
    }

    public function update(Request $request, CheckoutKlien $klien): RedirectResponse
    {
        $klien->update($this->valid($request, $klien));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Klien VDC Pay diperbarui.']);

        return back();
    }

    /** API key & rahasia webhook baru; yang lama langsung tidak berlaku. */
    public function kunci(CheckoutKlien $klien): RedirectResponse
    {
        $this->tampilkanKunci($klien);

        return back();
    }

    public function kirimUlang(CheckoutWebhook $webhook): RedirectResponse
    {
        // Hanya yang sudah habis percobaan; yang masih antri sedang punya rantai percobaan sendiri.
        if ($webhook->status !== 'gagal') {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Webhook masih dalam antrean atau sudah terkirim.']);

            return back();
        }
        $job = new KirimWebhookCheckout($webhook);
        (new UniqueLock(Cache::driver()))->release($job); // rantai lama sudah selesai; buang kunci yang mungkin tertinggal
        $webhook->update(['status' => 'antri']);
        dispatch($job);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Webhook dijadwalkan kirim ulang.']);

        return back();
    }

    private function tampilkanKunci(CheckoutKlien $klien): void
    {
        // Hanya sekali di flash; di DB API key disimpan sebagai hash dan rahasia webhook terenkripsi.
        Inertia::flash('kunci_klien', ['klien' => $klien->nama] + $klien->kunciBaru());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Kunci baru dibuat. Salin sekarang, tidak bisa dilihat lagi.']);
    }

    /** @return array<string, mixed> */
    private function valid(Request $request, ?CheckoutKlien $klien): array
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:60'],
            // Kode tidak bisa diubah: dipakai mencocokkan order Midtrans yang sudah dibuat (CS-<kode>-…)
            'kode' => $klien ? ['prohibited'] : ['required', 'string', 'regex:/^[A-Z0-9]{2,6}$/', Rule::unique('checkout_klien', 'kode')],
            'url_webhook' => ['required', 'url:https', 'max:255'],
            'host_kembali' => ['required', 'string', 'max:1000'],
            'aktif' => ['boolean'],
        ], ['kode.regex' => 'Kode 2–6 huruf besar/angka, mis. VCM.']);
        $data['host_kembali'] = array_values(array_unique(array_filter(array_map(
            fn ($h) => strtolower(trim($h)), preg_split('/[\s,]+/', $data['host_kembali']) ?: []))));
        $data['aktif'] = (bool) ($data['aktif'] ?? false);

        return $data;
    }
}
