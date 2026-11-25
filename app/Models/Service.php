<?php

namespace App\Models;

use App\Enums\PlanKategori;
use App\Enums\ServiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Service extends Model
{
    protected $guarded = ['id'];

    /** Validasi custom domain (halaman layanan & pesan hosting); input sudah di-lowercase + trim. */
    public static function aturanDomain(?int $abaikanId = null): array
    {
        return [
            'regex:/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/',
            \Illuminate\Validation\Rule::unique('services', 'custom_domain')->ignore($abaikanId),
            fn ($a, $v, $fail) => str_ends_with($v, '.'.config('cloud.domain_app')) || $v === config('cloud.domain_app')
                ? $fail('Gunakan domain milik Anda sendiri, bukan subdomain Velocity.') : null,
        ];
    }

    public const PESAN_DOMAIN = ['domain.regex' => 'Format domain tidak valid, contoh: toko.contoh.com.', 'domain.unique' => 'Domain ini sudah dipakai layanan lain.'];

    protected function casts(): array
    {
        return [
            'status' => ServiceStatus::class,
            'jatuh_tempo' => 'date',
            'detail' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function tokenAi(): HasOne
    {
        return $this->hasOne(TokenAi::class);
    }

    /** Ditangguhkan/aktif lagi ikut menghentikan/menyalakan resource: pod, VM, atau akun DA hosting. */
    public function ikutDitangguhkan(): bool
    {
        return $this->bisaKontrol() || ($this->node_id && $this->isHosting());
    }

    public function isHosting(): bool
    {
        return $this->plan->kategori === PlanKategori::Hosting;
    }

    public function isDomain(): bool
    {
        return $this->plan->kategori === PlanKategori::Domain;
    }

    public function isAi(): bool
    {
        return $this->plan->kategori === PlanKategori::Ai;
    }

    /** Bisa dinyalakan/dimatikan dari dashboard: pod app atau VM di node Incus. */
    public function bisaKontrol(): bool
    {
        return $this->node_id && ($this->isApp() || $this->node?->jenis === 'incus');
    }

    public function isApp(): bool
    {
        return $this->plan->kategori === PlanKategori::App;
    }

    /**
     * Custom domain disambung otomatis lewat relay HTTPS: app → router PC, VPS/managed → VM port 80
     * (VM di belakang relay Sumopod lewat relay itu; VM di node ber-IP publik lewat container vc-edge di node).
     */
    public function domainLewatRelay(): bool
    {
        return $this->ipRelay() !== null;
    }

    /** IP tujuan A record custom domain; null = belum bisa disambung otomatis. */
    public function ipRelay(): ?string
    {
        if ($this->isApp()) {
            return config('cloud.relay_ip');
        }
        $host = $this->detail['host'] ?? null;
        if (! in_array($this->plan->kategori, [PlanKategori::Vps, PlanKategori::Managed], true) || ! $host || empty($this->detail['ip'])) {
            return null;
        }
        // shortcut: anggap relay VPS (vps.relay_host) = mesin relay HTTPS (relay_ip, Sumopod); pisahkan bila relay dipecah.
        if ($host === config('cloud.vps.relay_host')) {
            return config('cloud.relay_ip');
        }
        // shortcut: tiap node ber-IP publik dianggap punya vc-edge di 80/443 (deploy/node-vps/pasang-edge.sh); beri penanda per node bila ada node tanpa edge.
        if ($host === $this->node?->vps_host) {
            return Cache::remember("ip-host:$host", 3600, fn () => filter_var(gethostbyname($host), FILTER_VALIDATE_IP) ?: null);
        }

        return null;
    }

    /** Nama pod di node, stabil dan unik per layanan. */
    public function podName(): string
    {
        return 'svc-'.$this->id;
    }

    /** Subdomain acak 10 karakter (huruf + angka, diawali huruf) yang belum dipakai. */
    public static function subdomainAcak(): string
    {
        do {
            $s = chr(random_int(97, 122)).strtolower(\Illuminate\Support\Str::random(9));
        } while (in_array($s, config('cloud.subdomain_cadangan'), true) || static::where('subdomain', $s)->exists());

        return $s;
    }

    public function url(): ?string
    {
        return $this->subdomain ? 'https://'.$this->subdomain.'.'.config('cloud.domain_app') : null;
    }
}
