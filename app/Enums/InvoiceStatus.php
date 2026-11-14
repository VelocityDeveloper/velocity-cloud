<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case BelumDibayar = 'belum_dibayar';
    case Lunas = 'lunas';
    case Dibatalkan = 'dibatalkan';

    public function label(): string
    {
        return match ($this) {
            self::BelumDibayar => 'Belum dibayar',
            self::Lunas => 'Lunas',
            self::Dibatalkan => 'Dibatalkan',
        };
    }
}
