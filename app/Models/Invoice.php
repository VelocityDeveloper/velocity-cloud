<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'periode_mulai' => 'date',
            'periode_selesai' => 'date',
            'jatuh_tempo' => 'date',
            'dibayar_pada' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    protected static function booted(): void
    {
        static::creating(function (Invoice $i) {
            if ($i->status === InvoiceStatus::BelumDibayar && ! $i->kode_unik) {
                $i->kode_unik = static::kodeUnikBaru($i->jumlah);
            }
        });
    }

    /** Yang harus dibayar pelanggan. */
    public function total(): int
    {
        return $this->jumlah + $this->kode_unik;
    }

    /** Kode 1–999 agar total tidak kembar dengan tagihan terbuka lain, sehingga transfer mudah dicocokkan. */
    public static function kodeUnikBaru(int $jumlah): int
    {
        $terpakai = static::where('status', InvoiceStatus::BelumDibayar)->where('kode_unik', '>', 0)
            ->whereBetween('jumlah', [$jumlah - 999, $jumlah + 999])->get(['jumlah', 'kode_unik'])
            ->map(fn ($i) => $i->jumlah + $i->kode_unik)->flip();
        $bebas = array_values(array_filter(range(1, 999), fn ($k) => ! $terpakai->has($jumlah + $k)));

        return $bebas ? $bebas[array_rand($bebas)] : random_int(1, 999); // semua terpakai (≥999 tagihan terbuka) → boleh kembar
    }

    public static function nomorBaru(): string
    {
        $prefix = 'INV-'.now()->format('Ym').'-';
        $terakhir = static::where('nomor', 'like', $prefix.'%')->max('nomor');

        return $prefix.str_pad((string) ((int) substr((string) $terakhir, -4) + 1), 4, '0', STR_PAD_LEFT);
    }
}
