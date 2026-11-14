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
