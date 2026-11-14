<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tiket;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TiketController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->query('status', 'terbuka');

        return Inertia::render('admin/Tiket', [
            'status' => $status,
            'pilihan' => collect(Tiket::STATUS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'tiket' => Tiket::with('service', 'user')
                ->when($status !== 'semua', fn ($q) => $q->where('status', $status))
                ->latest('updated_at')->limit(200)->get()->map->tampil(),
        ]);
    }
}
