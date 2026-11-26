<?php

namespace App\Http\Middleware;

use App\Models\Tiket;
use App\Services\Pengingat\DaftarPengingat;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'whatsapp' => config('cloud.whatsapp'),
            // badge menu admin Tiket: jumlah tiket menunggu balasan tim
            'tiketTerbuka' => fn () => $request->user()?->is_admin ? Tiket::where('status', 'terbuka')->count() : 0,
            // pengingat pelanggan: jatuh tempo H-7, tagihan tertunda, saldo token menipis (hanya baca)
            // lonceng header: 10 terbaru + jumlah belum dibaca
            'notifikasi' => fn () => $request->user() ? [
                'belum' => $request->user()->unreadNotifications()->count(),
                'daftar' => $request->user()->notifications()->latest()->limit(10)->get()
                    ->map(fn ($n) => ['id' => $n->id, ...$n->data, 'dibaca' => $n->read_at !== null, 'waktu' => $n->created_at->toIso8601String()]),
            ] : null,
            'pengingat' => fn () => $request->user() ? app(DaftarPengingat::class)->untuk($request->user()) : [],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
