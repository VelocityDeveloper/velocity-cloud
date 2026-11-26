<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\Pemberitahuan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** Siaran notifikasi lonceng dari admin: ke semua pengguna atau satu email. */
class NotifikasiController extends Controller
{
    public function index(): Response
    {
        // Siaran admin digabung per kiriman; notifikasi perorangan (bonus promo/manual) tampil per baris dengan id-nya sendiri.
        $riwayat = DatabaseNotification::query()
            ->selectRaw("coalesce(json_extract(data, '$.kiriman'), id) as kiriman, min(data) as data, min(created_at) as waktu, count(*) as penerima, count(read_at) as dibaca, min(notifiable_id) as user_id")
            ->groupBy('kiriman')->orderByDesc('waktu')->limit(100)->get();
        $nama = User::whereIn('id', $riwayat->where('penerima', 1)->pluck('user_id'))->pluck('name', 'id');
        $riwayat = $riwayat->map(fn ($r) => [...$r->data, 'kiriman' => $r->kiriman, 'waktu' => $r->waktu, 'penerima' => (int) $r->penerima, 'dibaca' => (int) $r->dibaca,
            'ke' => $r->penerima == 1 ? ($nama[$r->user_id] ?? null) : null]);

        return Inertia::render('admin/Notifikasi', ['riwayat' => $riwayat, 'jumlah_pengguna' => User::count()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tujuan' => ['required', 'in:semua,email'],
            'email' => ['required_if:tujuan,email', 'nullable', 'email', 'exists:users,email'],
            'judul' => ['required', 'string', 'max:120'],
            'isi' => ['required', 'string', 'max:500'],
            'url' => ['nullable', 'string', 'max:255', 'regex:#^/(?!/)#'], // path lokal; lonceng tidak mengikuti URL luar
        ], [
            'email.exists' => 'Email ini belum terdaftar.',
            'url.regex' => 'Isi path halaman di aplikasi, mis. /katalog/ai.',
        ]);

        $penerima = $data['tujuan'] === 'semua' ? User::all() : User::where('email', $data['email'])->get();
        Notification::send($penerima, new Pemberitahuan('info', $data['judul'], $data['isi'], $data['url'] ?? null, (string) Str::uuid()));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Notifikasi terkirim ke '.$penerima->count().' pengguna.']);

        return back();
    }

    /** Tarik kembali satu siaran dari lonceng semua penerimanya. */
    public function destroy(string $kiriman): RedirectResponse
    {
        $n = DatabaseNotification::where('data->kiriman', $kiriman)->orWhere('id', $kiriman)->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => "Notifikasi ditarik dari $n pengguna."]);

        return back();
    }
}
