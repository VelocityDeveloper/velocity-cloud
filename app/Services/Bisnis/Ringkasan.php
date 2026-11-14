<?php

namespace App\Services\Bisnis;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;

/** Angka bisnis saat ini; dipakai dashboard admin dan alat MCP ringkasan-bisnis. */
class Ringkasan
{
    public static function data(): array
    {
        $omzet = fn ($dari, $sampai) => (int) Invoice::where('status', InvoiceStatus::Lunas)->whereBetween('dibayar_pada', [$dari, $sampai])->sum('jumlah');

        return [
            'pelanggan' => User::where('is_admin', false)->count(),
            'pelanggan_baru_7_hari' => User::where('is_admin', false)->where('created_at', '>=', now()->subDays(7))->count(),
            'layanan_per_status' => Service::selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status'),
            'layanan_per_paket' => Service::join('plans', 'plans.id', '=', 'services.plan_id')->selectRaw('plans.slug, count(*) n')->groupBy('plans.slug')->pluck('n', 'slug'),
            'tagihan_belum_dibayar' => ['jumlah' => Invoice::where('status', InvoiceStatus::BelumDibayar)->count(), 'nilai' => (int) Invoice::where('status', InvoiceStatus::BelumDibayar)->sum('jumlah')],
            'omzet_lunas_bulan_ini' => $omzet(now()->startOfMonth(), now()),
            'omzet_lunas_bulan_lalu' => $omzet(now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()),
            'paket_dijual' => Plan::aktif()->count().' dari '.Plan::count(),
        ];
    }
}
