<?php

namespace App\Console\Commands;

use App\Jobs\KirimTagihanCrm;
use App\Models\Invoice;
use App\Services\Crm\TagihanCrm;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** Backfill: tagihan lunas Midtrans yang belum tercatat di CRM (billing + bank midtrans_vd). Dikirim satu per satu (sinkron). */
class KirimTagihanCrmCommand extends Command
{
    protected $signature = 'crm:kirim-tagihan
        {--sejak= : tanggal bayar mulai (YYYY-MM-DD); kosong = sejak awal}
        {--sampai= : tanggal bayar sampai (YYYY-MM-DD), inklusif}
        {--uji : tampilkan isi kiriman tanpa mengirim}';

    protected $description = 'Kirim tagihan lunas Midtrans yang belum tercatat ke billing + bank midtrans_vd CRM';

    public function handle(): int
    {
        $tagihan = TagihanCrm::lingkup()->whereNull('crm_bank_id')->with(['service.plan', 'user'])
            ->when($this->option('sejak'), fn ($q, $t) => $q->where('dibayar_pada', '>=', Carbon::parse($t)->startOfDay()))
            ->when($this->option('sampai'), fn ($q, $t) => $q->where('dibayar_pada', '<=', Carbon::parse($t)->endOfDay()))
            ->orderBy('dibayar_pada')->get()
            ->filter(fn (Invoice $i) => TagihanCrm::perluDikirim($i))->values(); // tagihan sandbox tidak ikut

        $this->table(['Nomor', 'Dibayar', 'Pelanggan', 'Kategori', 'Jenis', 'Paket', 'Nama web', 'Total'],
            $tagihan->map(function (Invoice $i) {
                $d = TagihanCrm::data($i);

                return [$d['referensi'], $d['dibayar_pada'], $d['customer']['email'], $d['kategori'], $d['jenis'], $d['paket'], $d['nama_web'], number_format($d['total'], 0, ',', '.')];
            }));
        $this->line($tagihan->count().' tagihan, total Rp'.number_format($tagihan->sum(fn (Invoice $i) => $i->total()), 0, ',', '.'));

        if ($this->option('uji') || $tagihan->isEmpty()) {
            return self::SUCCESS;
        }
        if (! $this->option('no-interaction') && ! $this->confirm('Kirim ke CRM sekarang?')) {
            return self::SUCCESS;
        }

        $gagal = 0;
        foreach ($tagihan as $i) {
            try {
                KirimTagihanCrm::dispatchSync($i);
            } catch (\Throwable $e) {
                $i->forceFill(['crm_galat' => mb_substr($e->getMessage(), 0, 500)])->save();
            }
            $i->refresh();
            if ($i->crm_bank_id) {
                $this->info("{$i->nomor}: billing {$i->crm_billing_id}, bank {$i->crm_bank_id}");
            } else {
                $gagal++;
                $this->error("{$i->nomor}: {$i->crm_galat}");
            }
        }

        return $gagal ? self::FAILURE : self::SUCCESS;
    }
}
