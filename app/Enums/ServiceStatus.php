<?php

namespace App\Enums;

enum ServiceStatus: string
{
    case MenungguPembayaran = 'menunggu_pembayaran';
    case Disiapkan = 'disiapkan';
    case Aktif = 'aktif';
    case Ditangguhkan = 'ditangguhkan';
    case Gagal = 'gagal';
    case Dibatalkan = 'dibatalkan';

    public function label(): string
    {
        return match ($this) {
            self::MenungguPembayaran => 'Menunggu pembayaran',
            self::Disiapkan => 'Sedang disiapkan',
            self::Aktif => 'Aktif',
            self::Ditangguhkan => 'Ditangguhkan',
            self::Gagal => 'Gagal disiapkan',
            self::Dibatalkan => 'Dibatalkan',
        };
    }
}
