<?php

namespace App\Console\Commands;

use App\Actions\Billing\ProsesTagihan;
use Illuminate\Console\Command;

class ProsesTagihanCommand extends Command
{
    protected $signature = 'tagihan:proses';

    protected $description = 'Terbitkan tagihan perpanjangan dan tangguhkan layanan yang telat bayar';

    public function handle(ProsesTagihan $proses): int
    {
        [$terbit, $ditangguhkan] = $proses->handle();
        $this->info("$terbit tagihan terbit, $ditangguhkan layanan ditangguhkan.");

        return self::SUCCESS;
    }
}
