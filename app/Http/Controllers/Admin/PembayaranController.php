<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MetodeBayar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PembayaranController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Pembayaran', [
            'metode' => MetodeBayar::orderBy('urutan')->orderBy('id')->get()->map->tampil(),
            'jenis' => MetodeBayar::JENIS,
        ]);
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
