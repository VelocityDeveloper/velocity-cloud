<?php

namespace App\Models;

use App\Enums\PlanKategori;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Node extends Model
{
    public const JENIS = ['pod' => 'Pod (agent Podman)', 'incus' => 'VPS (Incus KVM)', 'directadmin' => 'DirectAdmin (akun hosting PHP)'];

    protected $guarded = ['id'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'aktif' => 'boolean',
            'ram_pod_mb' => 'integer',
        ];
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * Sisa RAM (MB) terbesar untuk satu pod baru di node pod aktif, dicek saat pesan agar pesanan tidak gagal setelah lunas (#16).
     * Per node: ram_pod_mb dikurangi limit RAM layanan app di node itu; lalu dikurangi jatah pesanan yang belum punya node
     * (belum bayar/disiapkan). Null = ada node pod aktif tanpa ram_pod_mb (tanpa batas RAM). $segar = lewati memo per permintaan.
     */
    public static function sisaRamPod(bool $segar = false): ?int
    {
        $hitung = function (): ?int {
            $node = self::where('aktif', true)->where('jenis', 'pod')->get(['id', 'ram_pod_mb']);
            if ($node->isEmpty() || $node->contains(fn (Node $n) => $n->ram_pod_mb === null)) {
                return null;
            }
            $ram = Service::with('plan')->whereIn('status', Plan::STATUS_PAKAI_STOK)
                ->whereHas('plan', fn ($q) => $q->where('kategori', PlanKategori::App))
                ->where(fn ($q) => $q->whereNull('node_id')->orWhereIn('node_id', $node->pluck('id')))
                ->get()->groupBy(fn (Service $s) => $s->node_id ?? 0)->map(fn ($g) => $g->sum(fn (Service $s) => $s->plan->ramPodMb()));

            return $node->max(fn (Node $n) => $n->ram_pod_mb - ($ram[$n->id] ?? 0)) - ($ram[0] ?? 0);
        };

        return $segar ? $hitung() : once($hitung);
    }
}
