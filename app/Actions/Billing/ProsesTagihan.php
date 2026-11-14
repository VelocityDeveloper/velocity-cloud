<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Jobs\PodAction;
use App\Models\Invoice;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

class ProsesTagihan
{
    /** Dijalankan harian. Mengembalikan [jumlah tagihan terbit, jumlah layanan ditangguhkan]. */
    public function handle(): array
    {
        $cfg = config('cloud.tagihan');

        $terbit = 0;
        Service::where('status', ServiceStatus::Aktif)
            ->whereDate('jatuh_tempo', '<=', today()->addDays($cfg['terbit_hari_sebelum']))
            ->whereDoesntHave('invoices', fn ($q) => $q->where('status', InvoiceStatus::BelumDibayar))
            ->each(function (Service $s) use (&$terbit) {
                DB::transaction(fn () => Invoice::create([
                    'user_id' => $s->user_id,
                    'service_id' => $s->id,
                    'nomor' => Invoice::nomorBaru(),
                    'jumlah' => $s->harga,
                    'status' => InvoiceStatus::BelumDibayar,
                    'periode_mulai' => $s->jatuh_tempo,
                    'periode_selesai' => $s->jatuh_tempo->copy()->addMonthNoOverflow(),
                    'jatuh_tempo' => $s->jatuh_tempo,
                ]));
                $terbit++;
            });

        $ditangguhkan = 0;
        Service::where('status', ServiceStatus::Aktif)->whereNotNull('jatuh_tempo')
            ->whereHas('invoices', fn ($q) => $q->where('status', InvoiceStatus::BelumDibayar)
                ->whereDate('jatuh_tempo', '<', today()->subDays($cfg['tangguhkan_hari_sesudah'])))
            ->each(function (Service $s) use (&$ditangguhkan) {
                $s->update(['status' => ServiceStatus::Ditangguhkan]);
                if ($s->bisaKontrol()) {
                    PodAction::dispatch($s, 'stop');
                }
                $ditangguhkan++;
            });

        return [$terbit, $ditangguhkan];
    }
}
