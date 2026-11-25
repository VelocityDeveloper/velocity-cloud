<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Presenters\Tampil;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PenggunaController extends Controller
{
    public function index(Request $request): Response
    {
        $pengguna = User::select(['id', 'name', 'email', 'whatsapp', 'is_admin', 'created_at', 'analitik_vid'])
            ->selectRaw("case when google_id is null then 'Direct' else 'Google' end as via")
            ->withCount('services')->withSum(['invoices as total_lunas' => fn ($q) => $q->where('status', 'lunas')], 'jumlah')
            ->latest()->limit(200)->get();

        // Sumber = tampilan halaman pertama pengunjung landing (first touch) yang tertaut ke akun.
        $pertama = DB::table('analitik_hits')->where('jenis', 'pv')
            ->whereIn('vid', $pengguna->pluck('analitik_vid')->filter()->unique()->values())
            ->orderBy('waktu')->get(['vid', 'sumber', 'medium', 'kampanye'])
            ->unique('vid')->keyBy('vid');
        $pengguna->each(fn (User $u) => $u->setAttribute('sumber', $pertama[$u->analitik_vid] ?? null));

        return Inertia::render('admin/Pengguna', [
            'pengguna' => $pengguna,
            'kunjungan' => Inertia::optional(fn () => $this->kunjungan((int) $request->query('u'))),
        ]);
    }

    /** Detail satu pengguna: profil, layanan (+ saldo token AI), tagihan, tiket. */
    public function show(User $user): Response
    {
        $user->load(['services' => fn ($q) => $q->with('plan', 'tokenAi')->latest('id'),
            'invoices' => fn ($q) => $q->with('service')->latest('id'),
            'tiket' => fn ($q) => $q->latest('updated_at')]);

        return Inertia::render('admin/PenggunaShow', [
            'pengguna' => [...$user->only('id', 'name', 'email', 'whatsapp', 'is_admin', 'saldo'),
                'via' => $user->google_id ? 'Google' : 'Direct',
                'daftar' => $user->created_at->toDateTimeString(),
                'total_lunas' => $user->invoices->where('status.value', 'lunas')->sum('jumlah')],
            'layanan' => $user->services->map(fn ($s) => [...Tampil::layanan($s), 'saldo_token' => $s->tokenAi?->saldo]),
            'tagihan' => $user->invoices->map(fn ($i) => Tampil::tagihan($i)),
            'tiket' => $user->tiket->map->tampil(),
        ]);
    }

    /** Riwayat kunjungan landing satu pengguna untuk modal detail. */
    private function kunjungan(int $id): array
    {
        $vid = User::whereKey($id)->value('analitik_vid');
        $hits = $vid ? DB::table('analitik_hits')->where('vid', $vid)->orderBy('waktu')->limit(500)
            ->get(['waktu', 'jenis', 'path', 'judul', 'referrer', 'sumber', 'medium', 'kampanye', 'negara', 'kota',
                'perangkat', 'browser', 'os', 'layar', 'bahasa', 'event', 'label', 'durasi_ms', 'scroll', 'awal_sesi'])
            : collect();

        return ['user_id' => $id, 'vid' => $vid, 'hits' => $hits];
    }
}
