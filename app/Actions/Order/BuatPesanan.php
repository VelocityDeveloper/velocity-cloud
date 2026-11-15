<?php

namespace App\Actions\Order;

use App\Enums\InvoiceStatus;
use App\Enums\PlanKategori;
use App\Enums\ServiceStatus;
use App\Models\Invoice;
use App\Models\Node;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BuatPesanan
{
    /**
     * Pesanan baru = layanan "menunggu pembayaran" + tagihan pertama.
     * Bulanan: tagihan 1 bulan. Token AI: tagihan $tokenJuta × harga per juta token, tanpa jatuh tempo layanan.
     */
    public function handle(User $user, Plan $plan, string $label, ?int $tokenJuta = null, array $detail = []): Invoice
    {
        if (! $plan->bisaDipesan()) {
            throw new InvalidArgumentException('Paket ini belum bisa dipesan.');
        }
        $ai = $plan->kategori === PlanKategori::Ai;
        if ($ai && ($tokenJuta === null || $tokenJuta < 1)) {
            throw new InvalidArgumentException('Jumlah token wajib diisi.');
        }

        return DB::transaction(function () use ($user, $plan, $label, $ai, $tokenJuta, $detail) {
            if ($plan->fresh()->sisaStok() === 0) { // cek ulang di dalam transaksi
                throw new InvalidArgumentException('Stok paket ini habis.');
            }
            if ($plan->kategori === PlanKategori::App && (Node::sisaRamPod(segar: true) ?? PHP_INT_MAX) < $plan->ramPodMb()) {
                throw new InvalidArgumentException('Kapasitas server untuk paket ini sedang penuh.');
            }

            $service = Service::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'label' => $label,
                'subdomain' => $plan->appSlug() ? Service::subdomainAcak() : null,
                'status' => ServiceStatus::MenungguPembayaran,
                'harga' => $plan->harga,
                'detail' => $detail ?: null,
            ]);

            return $ai ? $this->tagihanToken($service, $tokenJuta) : Invoice::create([
                'user_id' => $user->id,
                'service_id' => $service->id,
                'nomor' => Invoice::nomorBaru(),
                'jumlah' => $plan->harga,
                'status' => InvoiceStatus::BelumDibayar,
                'periode_mulai' => today(),
                'periode_selesai' => today()->addMonthNoOverflow(),
                'jatuh_tempo' => today()->addDays(config('cloud.tagihan.jatuh_tempo_hari')),
            ]);
        });
    }

    /** Tagihan beli/top-up token AI; harga per juta token mengikuti harga paket saat ini. */
    public function tagihanToken(Service $service, int $tokenJuta): Invoice
    {
        return Invoice::create([
            'user_id' => $service->user_id,
            'service_id' => $service->id,
            'nomor' => Invoice::nomorBaru(),
            'jumlah' => $service->plan->harga * $tokenJuta,
            'token_juta' => $tokenJuta,
            'status' => InvoiceStatus::BelumDibayar,
            'periode_mulai' => today(),
            'periode_selesai' => today(),
            'jatuh_tempo' => today()->addDays(config('cloud.tagihan.jatuh_tempo_hari')),
        ]);
    }
}
