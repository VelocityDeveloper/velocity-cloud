<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Invoice;
use App\Notifications\PengingatJatuhTempo;
use App\Services\Pengingat\DaftarPengingat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** Email H-7/H-3/H-1 untuk tagihan perpanjangan belum dibayar. Tidak mengirim apa pun selama pengingat.email.aktif = false. */
class PengingatEmailCommand extends Command
{
    protected $signature = 'pengingat:email {--uji : tampilkan calon penerima tanpa mengirim}';

    protected $description = 'Kirim email pengingat jatuh tempo H-7/H-3/H-1 (nonaktif sampai PENGINGAT_EMAIL_AKTIF=true)';

    public function handle(): int
    {
        $uji = (bool) $this->option('uji');
        if (! $uji && ! config('pengingat.email.aktif')) {
            $this->warn('Pengingat email nonaktif (PENGINGAT_EMAIL_AKTIF=false). Tidak ada yang dikirim.');

            return self::SUCCESS;
        }

        $hari = config('pengingat.email.hari');
        $kirim = 0;
        Invoice::with(['service.plan', 'user'])->where('status', InvoiceStatus::BelumDibayar)->whereNull('token_juta') // top-up token bukan perpanjangan
            ->whereHas('service', fn ($q) => $q->where('status', ServiceStatus::Aktif))
            ->whereDate('jatuh_tempo', '>=', today())
            ->whereDate('jatuh_tempo', '<=', today()->addDays(max($hari)))
            ->each(function (Invoice $inv) use ($hari, $uji, &$kirim) {
                $sisa = DaftarPengingat::selisihHari($inv->jatuh_tempo);
                // hanya tagihan perpanjangan (periode mulai = jatuh tempo layanan), bukan pesanan baru/top-up
                if (! in_array($sisa, $hari, true) || ! $inv->service->jatuh_tempo || ! $inv->periode_mulai?->isSameDay($inv->service->jatuh_tempo)) {
                    return;
                }
                if ($uji) {
                    $this->line("{$inv->nomor} H-$sisa user#{$inv->user_id}");

                    return;
                }
                // satu email per tagihan per H-n, walau perintah dijalankan ulang
                if (Cache::add("pengingat-email:{$inv->id}:$sisa", 1, now()->addDays(10))) {
                    $inv->user->notify(new PengingatJatuhTempo($inv, $sisa));
                    $kirim++;
                }
            });

        $this->info($uji ? 'Mode uji: tidak ada email dikirim.' : "$kirim email pengingat dikirim.");

        return self::SUCCESS;
    }
}
