<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class PenggunaController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Pengguna', [
            'pengguna' => User::withCount('services')->withSum(['invoices as total_lunas' => fn ($q) => $q->where('status', 'lunas')], 'jumlah')
                ->latest()->limit(200)->get(['id', 'name', 'email', 'whatsapp', 'is_admin', 'created_at']),
        ]);
    }
}
