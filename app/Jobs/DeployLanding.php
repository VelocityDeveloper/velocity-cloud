<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/** Build ulang landing setelah harga/spek diubah di Admin › Paket. Beberapa simpan beruntun = satu build. */
class DeployLanding implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function handle(): void
    {
        $root = dirname(rtrim(config('cloud.katalog_dir'), '/'), 2); // .../src/data → root project landing
        $hasil = Process::path($root)->timeout(540)
            ->env(['PATH' => '/root/.bun/bin:/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin'])
            ->run(['/root/.bun/bin/bun', 'run', 'deploy']);

        if ($hasil->failed()) {
            Log::error('DeployLanding gagal', ['keluaran' => mb_substr($hasil->errorOutput().$hasil->output(), -3000)]);
            throw new RuntimeException('Build landing gagal, lihat storage/logs/laravel.log.');
        }

        // Limit CPU/RAM pod dibaca config/cloud.php dari apps.json: cache config dibangun ulang & worker antrean dimuat ulang
        // agar ProvisionPod berikutnya memakai spek baru.
        if (app()->configurationIsCached()) {
            Artisan::call('config:cache');
            Artisan::call('queue:restart');
        }
    }
}
