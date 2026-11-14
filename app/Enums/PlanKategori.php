<?php

namespace App\Enums;

enum PlanKategori: string
{
    case Vps = 'vps';
    case Managed = 'managed';
    case App = 'app';
    case Ai = 'ai';

    public function label(): string
    {
        return match ($this) {
            self::Vps => 'Cloud Server',
            self::Managed => 'Managed Server',
            self::App => 'App 1-Klik',
            self::Ai => 'Token AI',
        };
    }
}
