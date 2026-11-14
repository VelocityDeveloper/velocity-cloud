<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MetodeBayar;
use App\Models\Pengaturan;
use App\Services\Pembayaran\Midtrans;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PembayaranController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Pembayaran', [
            'metode' => MetodeBayar::orderBy('urutan')->orderBy('id')->get()->map->tampil(),
            'jenis' => MetodeBayar::JENIS,
            'midtrans' => [
                'aktif' => (bool) Pengaturan::ambil('midtrans.aktif', false),
                'produksi' => Midtrans::produksi(),
                // hanya 4 karakter akhir yang dikirim ke browser
                'kunci_akhir' => ($k = Midtrans::kunci()) === '' ? null : substr($k, -4),
                'url_notifikasi' => rtrim(config('app.url'), '/').route('midtrans.notifikasi', absolute: false),
            ],
        ]);
    }

    /** Server key kosong = tetap pakai yang lama. */
    public function midtrans(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'server_key' => ['nullable', 'string', 'max:100'],
            'produksi' => ['boolean'],
            'aktif' => ['boolean'],
        ]);
        $kunci = trim($data['server_key'] ?? '') ?: Midtrans::kunci();
        $produksi = (bool) ($data['produksi'] ?? false);
        if ($kunci !== '' && ! Midtrans::kunciCocokMode($kunci, $produksi)) {
            throw ValidationException::withMessages(['server_key' => $produksi
                ? 'Mode produksi butuh Server Key produksi (berawalan Mid-server-).'
                : 'Mode sandbox butuh Server Key sandbox (berawalan SB-Mid-server-).']);
        }
        if (! empty($data['aktif']) && $kunci === '') {
            throw ValidationException::withMessages(['server_key' => 'Isi Server Key sebelum mengaktifkan Midtrans.']);
        }

        if (filled($data['server_key'] ?? null)) {
            Pengaturan::simpan('midtrans.server_key', Crypt::encryptString($kunci));
        }
        Pengaturan::simpan('midtrans.produksi', $produksi);
        Pengaturan::simpan('midtrans.aktif', (bool) ($data['aktif'] ?? false));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pengaturan Midtrans disimpan.']);

        return back();
    }

    public function store(Request $request): RedirectResponse
    {
        MetodeBayar::create($this->simpanGambar($request, $this->valid($request, null), null));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Metode pembayaran ditambahkan.']);

        return back();
    }

    // POST, bukan PUT: unggah berkas lewat multipart.
    public function update(Request $request, MetodeBayar $metode): RedirectResponse
    {
        $metode->update($this->simpanGambar($request, $this->valid($request, $metode), $metode->gambar));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Metode pembayaran diperbarui.']);

        return back();
    }

    public function destroy(MetodeBayar $metode): RedirectResponse
    {
        if ($metode->gambar) {
            Storage::disk('public')->delete($metode->gambar);
        }
        $metode->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Metode pembayaran dihapus.']);

        return back();
    }

    private function valid(Request $request, ?MetodeBayar $metode): array
    {
        return $request->validate([
            'jenis' => ['required', Rule::in(array_keys(MetodeBayar::JENIS))],
            'nama' => ['required', 'string', 'max:60'],
            'nomor' => ['nullable', 'string', 'max:60'],
            'atas_nama' => ['nullable', 'string', 'max:100'],
            'instruksi' => ['nullable', 'string', 'max:1000'],
            'urutan' => ['nullable', 'integer', 'min:0', 'max:999'],
            'aktif' => ['boolean'],
            // QRIS wajib punya gambar: saat tambah, atau saat jenis diubah ke QRIS dari metode tanpa gambar.
            'gambar' => [Rule::requiredIf($request->input('jenis') === 'qris' && ! $metode?->gambar), 'nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);
    }

    private function simpanGambar(Request $request, array $data, ?string $lama): array
    {
        unset($data['gambar']);
        $data['urutan'] ??= 0;
        if ($request->hasFile('gambar')) {
            $data['gambar'] = $request->file('gambar')->store('metode-bayar', 'public');
            if ($lama) {
                Storage::disk('public')->delete($lama);
            }
        }

        return $data;
    }
}
