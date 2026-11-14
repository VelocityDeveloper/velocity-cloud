<?php

namespace App\Models;

use App\Enums\PlanKategori;
use App\Enums\ServiceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['aktif' => true];

    protected $hidden = ['sumber']; // berisi API key penyedia AI

    protected function casts(): array
    {
        return [
            'kategori' => PlanKategori::class,
            'spek' => 'array',
            'fitur' => 'array',
            'aktif' => 'boolean',
            'stok' => 'integer',
            'sumber' => 'encrypted:array',
        ];
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function scopeAktif(Builder $query): void
    {
        $query->where('aktif', true);
    }

    /** Slug app tanpa awalan "app-", kunci ke config('cloud.pods'). */
    public function appSlug(): ?string
    {
        return $this->kategori === PlanKategori::App ? substr($this->slug, 4) : null;
    }

    /** Spesifikasi pod dari config, null jika bukan app atau image belum ditentukan. */
    public function pod(): ?array
    {
        $pod = config('cloud.pods.'.$this->appSlug());

        return $pod && collect($pod['containers'])->every(fn ($c) => filled($c['image'])) ? $pod : null;
    }

    /** Status layanan yang memakai jatah stok; gagal/dibatalkan mengembalikan jatah. */
    public const STATUS_PAKAI_STOK = [ServiceStatus::MenungguPembayaran, ServiceStatus::Disiapkan, ServiceStatus::Aktif, ServiceStatus::Ditangguhkan];

    public function terpakai(): int
    {
        return $this->services()->whereIn('status', self::STATUS_PAKAI_STOK)->count();
    }

    /** Sisa stok, null = tanpa batas. */
    public function sisaStok(): ?int
    {
        return $this->stok === null ? null : max(0, $this->stok - $this->terpakai());
    }

    /** Cloud Server yang dibuat otomatis di node Incus (config cloud.vps.paket). */
    public function vpsOtomatis(): bool
    {
        return $this->kategori === PlanKategori::Vps && in_array($this->slug, config('cloud.vps.paket'), true);
    }

    public function bisaDipesan(): bool
    {
        return $this->aktif && $this->harga !== null && ($this->kategori !== PlanKategori::App || $this->pod() !== null) && $this->sisaStok() !== 0;
    }
}
