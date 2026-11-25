<?php

namespace App\Http\Controllers\Api;

use App\Enums\PlanKategori;
use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CRM new-vdnet (kotak "Layanan Velocity Cloud" di detail customer): pengguna + layanan per email, hanya baca.
 * Kunci & jalur lokal dicek middleware KunciCrm.
 */
class CrmLayananController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $v = $request->validate(['email' => ['required', 'array', 'max:5'], 'email.*' => ['email', 'max:255']]);
        $email = array_values(array_unique(array_map('mb_strtolower', $v['email'])));

        $pengguna = User::whereIn(DB::raw('lower(email)'), $email)
            ->with(['services' => fn ($q) => $q->with('plan')->where('status', '!=', ServiceStatus::Dibatalkan)->latest('id')])
            ->get();

        return response()->json(['data' => $pengguna->map(fn (User $u) => [
            'id' => $u->id,
            'nama' => $u->name,
            'email' => $u->email,
            'terdaftar' => $u->created_at?->toDateString(),
            'url_admin' => route('admin.pengguna.show', $u),
            'layanan' => $u->services->map(fn (Service $s) => [
                'id' => $s->id,
                'kategori' => $s->plan->kategori->label(),
                'paket' => $s->plan->nama,
                'alamat' => $this->alamat($s),
                'status' => $s->status->label(),
                'aktif' => $s->status === ServiceStatus::Aktif,
                'harga' => (int) $s->harga,
                'jatuh_tempo' => $s->jatuh_tempo?->toDateString(),
            ])->values(),
        ])->values()]);
    }

    /** Alamat yang dikenali CS; kredensial di detail tidak pernah dikirim. */
    private function alamat(Service $s): ?string
    {
        $d = $s->detail ?? [];

        return match (true) {
            filled($s->custom_domain) => $s->custom_domain,
            $s->plan->kategori === PlanKategori::Vps && isset($d['host']) => $d['host'].(isset($d['host_port']) ? ':'.$d['host_port'] : ''),
            filled($d['domain'] ?? null) => $d['domain'],
            default => $s->url(),
        };
    }
}
