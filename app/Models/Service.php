<?php

namespace App\Models;

use App\Enums\PlanKategori;
use App\Enums\ServiceStatus;
use Illuminate\Database\Eloquent\Model;
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
     * Custom domain disambung otomatis lewat relay HTTPS: app → router PC, VPS/managed di belakang relay VPS → VM port 80.
     * shortcut: anggap relay VPS (vps.relay_host) = mesin relay HTTPS (relay_ip, Sumopod); pisahkan bila relay dipecah.
     */
    public function domainLewatRelay(): bool
    {
        if ($this->isApp()) {
            return true;
        }
        $relay = config('cloud.vps.relay_host');

        return in_array($this->plan->kategori, [PlanKategori::Vps, PlanKategori::Managed], true)
            && $relay && ($this->detail['host'] ?? null) === $relay && ! empty($this->detail['ip']);
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
