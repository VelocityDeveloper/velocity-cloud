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
use Illuminate\Support\Facades\Cache;
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

        // Pilih node + catat node_id di bawah kunci, agar worker lain yang memilih node ikut menghitung pod ini (Disiapkan + node_id).
        $node = $service->node ?? Cache::lock('pilih-node-pod', 60)->block(30, function () use ($service) {
            $node = $this->pilihNode($service->plan->ramPodMb());
            $node && $service->update(['node_id' => $node->id]);

            return $node;
        });
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
        // node_id dilepas agar coba ulang memilih node lagi (seperti sebelum node dicatat lebih awal)
        $service->update(['status' => ServiceStatus::Gagal, 'node_id' => null, 'pesan_gagal' => mb_substr($pesan, 0, 1000)]);
    }

    /**
     * Node aktif dengan pod paling sedikit yang masih di bawah kapasitas. Bila node punya ram_pod_mb,
     * jumlah limit RAM pod yang ada + pod baru juga tidak boleh melebihinya (varian 2–4 GB, #16).
     */
    private function pilihNode(int $ramBaru): ?Node
    {
        $dipakai = [ServiceStatus::Aktif, ServiceStatus::Ditangguhkan];

        return Node::where('aktif', true)->where('jenis', 'pod')
            ->withCount(['services' => fn ($q) => $q->whereIn('status', $dipakai)])
            ->get()
            ->filter(fn (Node $n) => $n->services_count < $n->kapasitas_pod)
            ->filter(fn (Node $n) => $n->ram_pod_mb === null
                // pod yang sedang dibuat di node ini (Disiapkan + node_id) ikut dihitung agar dua worker tidak melampaui RAM
                || $n->services()->whereIn('status', [...$dipakai, ServiceStatus::Disiapkan])->with('plan')->get()->sum(fn ($s) => $s->plan->ramPodMb()) + $ramBaru <= $n->ram_pod_mb)
            ->sortBy('services_count')
            ->first();
    }
}
