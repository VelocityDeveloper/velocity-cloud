<?php

namespace App\Models;

use App\Enums\PlanKategori;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Promo extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['kategori' => 'array', 'mulai' => 'datetime', 'selesai' => 'datetime', 'aktif' => 'boolean'];
    }

    public function klaim(): HasMany
    {
        return $this->hasMany(PromoKlaim::class);
    }

    public function scopeBerlaku(Builder $q): Builder
    {
        return $q->where('aktif', true)
            ->where(fn ($w) => $w->whereNull('mulai')->orWhere('mulai', '<=', now()))
            ->where(fn ($w) => $w->whereNull('selesai')->orWhere('selesai', '>=', now()));
    }

    public function cocok(PlanKategori $kategori, int $nominal): bool
    {
        return in_array($kategori->value, $this->kategori, true) && $nominal >= $this->min_pembelian;
    }
}
