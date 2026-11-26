<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotifikasiController extends Controller
{
    /** Klik notifikasi: tandai dibaca lalu buka tujuannya. */
    public function baca(Request $request, string $id): RedirectResponse
    {
        $n = $request->user()->notifications()->findOrFail($id);
        $n->markAsRead();
        $url = $n->data['url'] ?? null;

        // hanya path lokal, bukan URL luar
        return is_string($url) && str_starts_with($url, '/') && ! str_starts_with($url, '//') ? redirect($url) : back();
    }

    public function bacaSemua(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
