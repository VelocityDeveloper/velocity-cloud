<?php

namespace App\Jobs;

use App\Models\Service;
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
        $node->jenis === 'incus'
            ? (new Incus($node))->aksi($this->service->podName(), $this->aksi)
            : (new NodeAgent($node))->aksi($this->service->podName(), $this->aksi);
    }
}
