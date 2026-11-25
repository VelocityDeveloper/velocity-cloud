<?php

namespace App\Enums;

/** Status sesi bayar VDC Pay. Nilai ini juga dikirim apa adanya ke klien (API & webhook). */
enum CheckoutStatus: string
{
    case Dibuat = 'dibuat'; // belum memilih metode bayar
    case Menunggu = 'menunggu'; // transaksi Midtrans dibuat, menunggu dana (mis. VA belum ditransfer)
    case Lunas = 'lunas';
    case Kedaluwarsa = 'kedaluwarsa';
    case Batal = 'batal';
    case Ditolak = 'ditolak'; // deny bank / fraud
    case Refund = 'refund';

    public function label(): string
    {
        return match ($this) {
            self::Dibuat => 'Belum dibayar',
            self::Menunggu => 'Menunggu pembayaran',
            self::Lunas => 'Lunas',
            self::Kedaluwarsa => 'Kedaluwarsa',
            self::Batal => 'Dibatalkan',
            self::Ditolak => 'Ditolak',
            self::Refund => 'Dana dikembalikan',
        };
    }

    /** Masih bisa dibayar dari halaman checkout. */
    public function terbuka(): bool
    {
        return $this === self::Dibuat || $this === self::Menunggu;
    }

    /** Jenis event webhook untuk status ini; null = tidak dikirim (status antara). */
    public function event(): ?string
    {
        return match ($this) {
            self::Dibuat, self::Menunggu => null,
            default => 'checkout.'.$this->value,
        };
    }
}
