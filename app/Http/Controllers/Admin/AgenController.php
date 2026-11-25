<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgenCatatan;
use App\Models\AgenPelajaran;
use App\Services\Agen\TargetAgen;
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
            'jenis' => ['nullable', 'in:laporan,tindakan,review,masalah,pelajaran'],
            'bulan' => ['nullable', 'date_format:Y-m'],
            'agen' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', 'string', 'max:30'],
            'putusan' => ['nullable', 'in:berhasil,netral,gagal,belum'],
            'q' => ['nullable', 'string', 'max:100'],
            'fokus' => ['nullable', 'integer', 'min:1'],
        ]);

        $jenis = $data['jenis'] ?? 'laporan';
        $menunggu = AgenCatatan::where('jenis', 'tindakan')->where('status', 'usulan')->count();
        $terbuka = AgenCatatan::where('jenis', 'masalah')->where('status', 'terbuka')->count();
        $reviewTerbuka = AgenCatatan::where('jenis', 'review')->where('status', 'terbuka')->count();

        $dasar = [
            'jenis' => $jenis,
            'bulan' => null,
            'menunggu' => $menunggu,
            'terbuka' => $terbuka,
            'review_terbuka' => $reviewTerbuka,
            'progres' => TargetAgen::progres(),
            'target' => TargetAgen::target(),
            'arahan' => TargetAgen::arahan(),
            'siklus' => TargetAgen::ringkasanSiklus(),
            'daftar_agen' => AgenCatatan::distinct()->orderBy('agen')->pluck('agen')->values()->all(),
            'filter' => [
                'agen' => $data['agen'] ?? '',
                'status' => $data['status'] ?? '',
                'putusan' => $data['putusan'] ?? '',
                'q' => trim((string) ($data['q'] ?? '')),
            ],
            'fokus' => isset($data['fokus']) ? (int) $data['fokus'] : null,
        ];

        if ($jenis === 'pelajaran') {
            return Inertia::render('admin/Agen', [
                ...$dasar,
                'catatan' => [],
                'pelajaran' => AgenPelajaran::orderByRaw("status = 'usang'")->orderByDesc('skor')->latest('updated_at')->limit(200)->get(),
            ]);
        }

        $catatan = AgenCatatan::where('jenis', $jenis)->latest('id');

        $bulan = null;
        if ($jenis === 'laporan') {
            $mulai = CarbonImmutable::createFromFormat('Y-m-d H:i:s', ($data['bulan'] ?? now(self::ZONA)->format('Y-m')).'-01 00:00:00', self::ZONA);
            $bulan = $mulai->format('Y-m');
            $catatan->whereBetween('created_at', [$mulai->utc(), $mulai->addMonth()->utc()->subSecond()]);
        } else {
            if (! empty($data['agen'])) {
                $catatan->where('agen', $data['agen']);
            }
            if (! empty($data['status'])) {
                $catatan->where('status', $data['status']);
            }
            if ($jenis === 'tindakan' && ! empty($data['putusan'])) {
                if ($data['putusan'] === 'belum') {
                    $catatan->whereNull('dievaluasi_pada');
                } else {
                    $catatan->whereNotNull('dievaluasi_pada')->where('hasil->putusan', $data['putusan']);
                }
            }
            if ($dasar['filter']['q'] !== '') {
                $cari = '%'.$dasar['filter']['q'].'%';
                $catatan->where(fn ($w) => $w->where('judul', 'like', $cari)->orWhere('isi', 'like', $cari)->orWhere('target', 'like', $cari));
            }
        }

        $daftar = $catatan->limit($jenis === 'laporan' ? 500 : 200)->get();

        // Pastikan catatan yang dituju lewat ?fokus=<id> selalu ikut tampil meski di luar batas 200
        if ($dasar['fokus'] && ! $daftar->contains('id', $dasar['fokus'])) {
            if ($item = AgenCatatan::where('jenis', $jenis)->find($dasar['fokus'])) {
                $daftar->prepend($item);
            }
        }

        return Inertia::render('admin/Agen', [
            ...$dasar,
            'bulan' => $bulan,
            'catatan' => $daftar,
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

    /** Admin menutup masalah atau review harian yang masih terbuka langsung dari dashboard. */
    public function selesai(Request $request, AgenCatatan $catatan): RedirectResponse
    {
        abort_unless(in_array($catatan->jenis, ['masalah', 'review'], true) && $catatan->status === 'terbuka', 422, 'Hanya masalah atau review terbuka yang bisa ditutup.');
        $d = $request->validate(['ringkasan' => ['nullable', 'string', 'max:2000']]);
        $ringkasan = trim((string) ($d['ringkasan'] ?? ''));

        $catatan->update([
            'status' => 'fix',
            'ringkasan' => $ringkasan !== '' ? $ringkasan : 'Ditutup oleh admin dari dashboard.',
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Ditandai selesai.']);

        return back();
    }

    /** Admin memperbarui arahan prioritas yang dibaca agen pada setiap awal siklus (MCP mulai-siklus). */
    public function simpanArahan(Request $request): RedirectResponse
    {
        $d = $request->validate(['teks' => ['nullable', 'string', 'max:2000']]);
        TargetAgen::simpanArahan((string) ($d['teks'] ?? ''));
        Inertia::flash('toast', ['type' => 'success', 'message' => trim((string) ($d['teks'] ?? '')) !== '' ? 'Arahan pemilik disimpan. Agen akan membacanya di siklus berikutnya.' : 'Arahan pemilik dikosongkan.']);

        return back();
    }

    /** Admin mengubah angka target mingguan dan bulanan agen. */
    public function simpanTarget(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'mingguan.sesi_asli' => ['required', 'integer', 'min:1', 'max:1000000'],
            'mingguan.klik_pesan' => ['required', 'integer', 'min:1', 'max:1000000'],
            'mingguan.klik_daftar' => ['required', 'integer', 'min:1', 'max:1000000'],
            'mingguan.order_lunas' => ['required', 'integer', 'min:1', 'max:1000000'],
            'bulanan.sesi_asli' => ['required', 'integer', 'min:1', 'max:10000000'],
            'bulanan.klik_pesan' => ['required', 'integer', 'min:1', 'max:10000000'],
            'bulanan.klik_daftar' => ['required', 'integer', 'min:1', 'max:10000000'],
            'bulanan.order_lunas' => ['required', 'integer', 'min:1', 'max:10000000'],
            'bulanan.omzet_lunas' => ['required', 'integer', 'min:0', 'max:10000000000'],
        ]);
        TargetAgen::simpanTarget($d);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Target agen diperbarui.']);

        return back();
    }

    /** Admin menandai pelajaran playbook sebagai usang atau mengaktifkannya kembali. */
    public function ubahPelajaran(Request $request, AgenPelajaran $pelajaran): RedirectResponse
    {
        $d = $request->validate(['status' => ['required', 'in:aktif,usang']]);
        $pelajaran->update(['status' => $d['status']]);
        Inertia::flash('toast', ['type' => 'success', 'message' => $d['status'] === 'usang' ? 'Pelajaran ditandai usang.' : 'Pelajaran diaktifkan kembali.']);

        return back();
    }
}
