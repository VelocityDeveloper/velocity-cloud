<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgenCatatan;
use App\Models\AgenPelajaran;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AgenController extends Controller
{
    private const ZONA = 'Asia/Jakarta';

    public function index(Request $request): Response
    {
        $data = $request->validate([
            'jenis' => ['nullable', 'in:laporan,tindakan,pelajaran,masalah'],
            'bulan' => ['nullable', 'date_format:Y-m'],
        ]);
        $jenis = $data['jenis'] ?? 'laporan';
        $catatan = AgenCatatan::where('jenis', $jenis)->latest();
        $menunggu = AgenCatatan::where('jenis', 'tindakan')->where('status', 'usulan')->count();
        $terbuka = AgenCatatan::where('jenis', 'masalah')->where('status', 'terbuka')->count();

        if ($jenis === 'pelajaran') { // playbook siklus belajar: aktif berskor tertinggi dulu
            return Inertia::render('admin/Agen', [
                'jenis' => $jenis, 'bulan' => null, 'menunggu' => $menunggu, 'terbuka' => $terbuka, 'catatan' => [],
                'pelajaran' => AgenPelajaran::orderByRaw("status = 'usang'")->orderByDesc('skor')->latest('updated_at')->limit(200)->get(),
            ]);
        }

        // Laporan tampil sebagai kalender per bulan (WIB, zona admin); tindakan tetap daftar terbaru.
        $bulan = null;
        if ($jenis === 'laporan') {
            $mulai = CarbonImmutable::createFromFormat('Y-m-d H:i:s', ($data['bulan'] ?? now(self::ZONA)->format('Y-m')).'-01 00:00:00', self::ZONA);
            $bulan = $mulai->format('Y-m');
            $catatan->whereBetween('created_at', [$mulai->utc(), $mulai->addMonth()->utc()->subSecond()]);
        }

        return Inertia::render('admin/Agen', [
            'jenis' => $jenis,
            'bulan' => $bulan,
            'menunggu' => $menunggu,
            'terbuka' => $terbuka,
            'catatan' => $catatan->limit($jenis === 'laporan' ? 500 : 100)->get(),
        ]);
    }

    /** Admin menyetujui/menolak usulan agen; agen mengambil yang disetujui lewat MCP daftar-tindakan. */
    public function putuskan(Request $request, AgenCatatan $catatan): RedirectResponse
    {
        abort_unless($catatan->jenis === 'tindakan' && $catatan->status === 'usulan', 422, 'Hanya usulan yang bisa diputuskan.');
        $catatan->update($request->validate(['status' => ['required', 'in:disetujui,ditolak']]));
        Inertia::flash('toast', ['type' => 'success', 'message' => $catatan->status === 'disetujui' ? 'Usulan disetujui. Agen menerapkannya di putaran berikutnya.' : 'Usulan ditolak.']);

        return back();
    }
}
