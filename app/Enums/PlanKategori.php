<?php

namespace App\Enums;

enum PlanKategori: string
{
    case Vps = 'vps';
    case Managed = 'managed';
    case App = 'app';
    case Ai = 'ai';
    case Hosting = 'hosting';
    case Domain = 'domain'; // satu paket tersembunyi; harga per pesanan dari CRM

    public function label(): string
    {
        return match ($this) {
            self::Vps => 'Cloud Server',
            self::Managed => 'Managed Server',
            self::App => 'App 1-Klik',
            self::Ai => 'Token AI',
            self::Hosting => 'Shared Hosting',
            self::Domain => 'Domain',
        };
    }

    /** Lama satu periode tagihan: hosting tahunan (harga = CRM velocitydeveloper.net), lainnya bulanan. */
    public function bulanSiklus(): int
    {
        return $this === self::Hosting ? 12 : 1;
    }
}
