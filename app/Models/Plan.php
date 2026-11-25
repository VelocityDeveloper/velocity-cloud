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
            'tersembunyi' => 'boolean',
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

    /** Spek tampilan paket app dari limit pod (sama untuk katalog:sync & Admin › Paket). */
    /** Disk app bila apps.json tidak menulis disk_gb (app baru). */
    public const DISK_DEFAULT_GB = 5;

    /** Fitur paket token AI (katalog:sync & Tambah paket AI). */
    public const FITUR_AI = ['API kompatibel OpenAI', 'Saldo prabayar, tanpa masa berlaku', 'Harga input, cache & output terpisah'];

    /** disk_gb hanya informasi (belum dibatasi per pod: XFS root noquota); null = kunci disk tidak ada. */
    public static function spekPod(?string $kategoriApp, float|int $cpu, int $ramMb, ?int $diskGb = null): array
    {
        return array_filter([
            'kategori_app' => $kategoriApp,
            'cpu' => str_replace('.', ',', (string) $cpu).' vCPU',
            'ram' => $ramMb >= 1024 ? str_replace('.', ',', (string) ($ramMb / 1024)).' GB' : $ramMb.' MB',
            'disk' => $diskGb ? $diskGb.' GB' : null,
        ], fn ($v) => $v !== null);
    }

    /** Limit pod mentah [cpu, ram_mb, disk_gb] untuk form Admin › Paket; null bila bukan app atau tanpa pod. */
    public function limitPod(): ?array
    {
        $disk = isset($this->spek['disk']) ? (int) $this->spek['disk'] : null; // "10 GB" → 10
        if ($v = $this->varian()) {
            return ['cpu' => $v['cpu'], 'ram_mb' => $v['ram_mb'], 'disk_gb' => $disk];
        }
        $l = $this->kategori === PlanKategori::App ? config('cloud.pods.'.$this->appSlug().'.limit') : null;

        return $l ? ['cpu' => $l['cpu'], 'ram_mb' => (int) $l['memori'], 'disk_gb' => $disk] : null;
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

    /** Harga promo token AI (spek harga_promo, periode promo_mulai–promo_selesai WIB; kosong = tanpa batas) bila sedang berlaku. */
    public function hargaPromo(): ?int
    {
        $s = $this->spek ?? [];
        $promo = isset($s['harga_promo']) ? (int) $s['harga_promo'] : null;
        $berlaku = $promo !== null && $this->harga !== null && $promo < $this->harga
            && (empty($s['promo_mulai']) || now()->gte($s['promo_mulai']))
            && (empty($s['promo_selesai']) || now()->lte(\Illuminate\Support\Carbon::parse($s['promo_selesai'])->endOfMinute())); // sampai 23:59 = sampai 23:59:59

        return $berlaku ? $promo : null;
    }

    /** Harga beli per 1 jt token saat ini (promo bila berlaku). Potong saldo & biaya pemakaian tetap memakai harga normal. */
    public function hargaBeli(): ?int
    {
        return $this->hargaPromo() ?? $this->harga;
    }

    public function bisaDipesan(): bool
    {
        return $this->aktif && $this->harga !== null && ($this->kategori !== PlanKategori::App || $this->pod() !== null) && $this->sisaStok() !== 0
            && ($this->kategori !== PlanKategori::App || (Node::sisaRamPod() ?? PHP_INT_MAX) >= $this->ramPodMb());
    }
}
