<?php

namespace App\Models;

use App\Enums\PlanKategori;
use App\Enums\ServiceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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

    /** Slug app tanpa awalan "app-", kunci ke config('cloud.pods'); varian (app-wordpress-plus) → app induk (wordpress). */
    public function appSlug(): ?string
    {
        return $this->kategori === PlanKategori::App ? ($this->varian()['app'] ?? substr($this->slug, 4)) : null;
    }

    /** Data varian dari config('cloud.varian') bila app ini punya beberapa pilihan paket: [app, nama, cpu, ram_mb]. */
    public function varian(): ?array
    {
        return $this->kategori === PlanKategori::App ? config('cloud.varian.'.substr($this->slug, 4)) : null;
    }

    /** Varian selain paket dasar; disembunyikan dari daftar katalog dan dipilih di halaman pesan app induk. */
    public function varianTambahan(): bool
    {
        return ($v = $this->varian()) !== null && 'app-'.$v['app'] !== $this->slug;
    }

    /** Semua paket aktif satu app (dasar + varian), urut harga; kosong bila app tanpa varian. */
    public function keluargaVarian(): Collection
    {
        if (! $v = $this->varian()) {
            return new Collection;
        }
        $slug = collect(config('cloud.varian'))->filter(fn ($x) => $x['app'] === $v['app'])->keys()->map(fn ($k) => 'app-'.$k);

        return self::aktif()->whereIn('slug', $slug)->orderBy('harga')->get();
    }

    /** Spesifikasi pod dari config, null jika bukan app atau image belum ditentukan. Varian menimpa limit CPU/RAM. */
    public function pod(): ?array
    {
        $pod = config('cloud.pods.'.$this->appSlug());
        if ($pod && $v = $this->varian()) {
            $pod['limit'] = ['cpu' => $v['cpu'], 'memori' => $v['ram_mb'].'m'];
        }

        return $pod && collect($pod['containers'])->every(fn ($c) => filled($c['image'])) ? $pod : null;
    }

    /** Batas RAM pod dalam MB (limit memori "1024m"), 0 bila bukan app. */
    public function ramPodMb(): int
    {
        return (int) ($this->pod()['limit']['memori'] ?? 0);
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
        return $this->aktif && $this->harga !== null && ($this->kategori !== PlanKategori::App || $this->pod() !== null) && $this->sisaStok() !== 0
            && ($this->kategori !== PlanKategori::App || (Node::sisaRamPod() ?? PHP_INT_MAX) >= $this->ramPodMb());
    }
}
