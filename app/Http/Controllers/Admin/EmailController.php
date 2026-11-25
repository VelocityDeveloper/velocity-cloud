<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Admin › Support › Email: riwayat email keluar (verifikasi, reset sandi, balasan tiket, pengingat, dll). */
class EmailController extends Controller
{
    public function index(Request $request): Response
    {
        $cari = trim((string) $request->query('cari', ''));

        return Inertia::render('admin/Email', [
            'cari' => $cari,
            'email' => EmailLog::with('user:id,name')
                ->when($cari !== '', fn ($q) => $q->where(fn ($w) => $w->where('ke', 'like', "%$cari%")->orWhere('subjek', 'like', "%$cari%")))
                ->latest('id')->limit(300)->get()
                ->map(fn (EmailLog $e) => [
                    'id' => $e->id,
                    'waktu' => $e->created_at->toDateTimeString(),
                    'ke' => $e->ke,
                    'pengguna' => $e->user ? ['id' => $e->user->id, 'nama' => $e->user->name] : null,
                    'subjek' => $e->subjek,
                    'jenis' => $e->jenisLabel(),
                    'status' => $e->status,
                ]),
        ]);
    }
}
