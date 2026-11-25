<?php

namespace App\Jobs;

use App\Models\Service;
use App\Services\Provisioning\DirectAdmin;
use App\Services\Provisioning\Incus;
use App\Services\Provisioning\NodeAgent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PodAction implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public Service $service, public string $aksi) {}

    public function handle(): void
    {
        $node = $this->service->node;
        if (! $node) {
            return;
        }
        match ($node->jenis) {
            // hosting: stop = suspend akun DA (ditangguhkan), start = unsuspend (lunas lagi)
            'directadmin' => in_array($this->aksi, ['stop', 'start'], true) && isset($this->service->detail['da_user'])
                ? (new DirectAdmin($node))->suspend($this->service->detail['da_user'], $this->aksi === 'stop') : null,
            'incus' => (new Incus($node))->aksi($this->service->podName(), $this->aksi),
            default => (new NodeAgent($node))->aksi($this->service->podName(), $this->aksi),
        };
    }
}
