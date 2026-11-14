<?php

namespace App\Jobs;

use App\Enums\ServiceStatus;
use App\Models\Node;
use App\Models\Service;
use App\Services\Provisioning\NodeAgent;
use App\Services\Provisioning\PodSpec;
use App\Services\Provisioning\SiapkanApp;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class ProvisionPod implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public int $timeout = 1000; // tarik image bisa lama; DB_QUEUE_RETRY_AFTER harus lebih besar

    public function __construct(public Service $service) {}

    public function handle(): void
    {
        $service = $this->service->fresh(['plan']);
        if ($service->status !== ServiceStatus::Disiapkan) {
            return;
        }

        $node = $service->node ?? $this->pilihNode();
        if (! $node) {
            $this->gagal($service, 'Belum ada node aktif dengan kapasitas tersisa.');

            return;
        }

        [$spec, $kredensial, $siapkan] = PodSpec::untuk($service);
        $hasil = (new NodeAgent($node))->buat($spec);
        if ($siapkan) {
            SiapkanApp::jalankan('https://'.$spec['host'], $siapkan);
        }

        $service->update([
            'node_id' => $node->id,
            'status' => ServiceStatus::Aktif,
            'pesan_gagal' => null,
            'detail' => [
                ...($service->detail ?? []),
                'url' => $spec['tcp'] ? null : $service->url(),
                'host' => $spec['host'],
                'host_port' => $hasil['host_port'] ?? null,
                'kredensial' => $kredensial ? Crypt::encryptString(json_encode($kredensial)) : null,
            ],
        ]);
    }

    public function failed(Throwable $e): void
    {
        $this->gagal($this->service->fresh(), $e->getMessage());
    }

    private function gagal(Service $service, string $pesan): void
    {
        $service->update(['status' => ServiceStatus::Gagal, 'pesan_gagal' => mb_substr($pesan, 0, 1000)]);
    }

    /** Node aktif dengan pod paling sedikit yang masih di bawah kapasitas. */
    private function pilihNode(): ?Node
    {
        return Node::where('aktif', true)->where('jenis', 'pod')
            ->withCount(['services' => fn ($q) => $q->whereIn('status', [ServiceStatus::Aktif, ServiceStatus::Ditangguhkan])])
            ->get()
            ->filter(fn (Node $n) => $n->services_count < $n->kapasitas_pod)
            ->sortBy('services_count')
            ->first();
    }
}
