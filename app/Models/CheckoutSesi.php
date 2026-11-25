<?php

namespace App\Models;

use App\Enums\CheckoutStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CheckoutSesi extends Model
{
    protected $table = 'checkout_sesi';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CheckoutStatus::class,
            'metadata' => 'array',
            'midtrans' => 'array',
            'berlaku_sampai' => 'datetime',
            'dibayar_pada' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (CheckoutSesi $s) => $s->uuid ??= 'cs_'.Str::lower(Str::random(32)));
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return BelongsTo<CheckoutKlien, $this> */
    public function klien(): BelongsTo
    {
        return $this->belongsTo(CheckoutKlien::class, 'checkout_klien_id');
    }

    /** @return HasMany<CheckoutWebhook, $this> */
    public function webhook(): HasMany
    {
        return $this->hasMany(CheckoutWebhook::class);
    }

    /** Batas mulai bayar lewat, dan belum ada transaksi Midtrans yang masih berjalan. */
    public function lewatBatas(): bool
    {
        return now()->gte($this->berlaku_sampai)
            && ! (isset($this->midtrans['sampai']) && now()->lt($this->midtrans['sampai']));
    }

    /**
     * Bentuk yang dikirim ke klien (respons API & isi webhook).
     *
     * @return array<string, mixed>
     */
    public function untukKlien(): array
    {
        return [
            'id' => $this->uuid,
            'ref' => $this->ref,
            'status' => $this->status->value,
            'jumlah' => $this->jumlah,
            'deskripsi' => $this->deskripsi,
            'email' => $this->email,
            'metadata' => $this->metadata,
            'url' => route('checkout.show', $this),
            'berlaku_sampai' => $this->berlaku_sampai->toIso8601String(),
            'dibayar_pada' => $this->dibayar_pada?->toIso8601String(),
            'metode' => $this->referensi ? Str::before($this->referensi, ' ') : null,
        ];
    }
}
