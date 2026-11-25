<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Pengaturan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingVpsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/SettingVps', [
            'os' => collect(config('cloud.vps.os'))->map(fn ($o, $k) => ['kunci' => $k, 'nama' => $o['nama'], 'aktif' => array_key_exists($k, Pengaturan::osVps())])->values(),
        ]);
    }

    /** OS yang boleh dipilih pelanggan di halaman pesan VPS. Layanan yang sudah jalan tidak terpengaruh. */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'os' => ['required', 'array', 'min:1'],
            'os.*' => ['string', 'in:'.implode(',', array_keys(config('cloud.vps.os')))],
        ], ['os.required' => 'Aktifkan minimal satu OS.', 'os.min' => 'Aktifkan minimal satu OS.']);
        Pengaturan::simpan('vps.os_aktif', array_values(array_unique($data['os'])));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pilihan OS VPS disimpan.']);

        return back();
    }
}
