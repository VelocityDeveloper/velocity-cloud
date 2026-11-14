<?php

namespace App\Jobs;

use App\Enums\ServiceStatus;
use App\Models\Node;
use App\Models\Service;
use App\Services\Provisioning\Incus;
use App\Services\Provisioning\PodSpec;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/** Buat VM KVM di node Incus untuk Cloud Server otomatis. Tanpa node/relay = dibiarkan "disiapkan" untuk admin (manual). */
class ProvisionVps implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [60];

    public int $timeout = 1000; // unduh image pertama bisa lama; DB_QUEUE_RETRY_AFTER 1100

    public function __construct(public Service $service) {}

    public function handle(): void
    {
        $service = $this->service->fresh(['plan', 'node']);
        $cfg = config('cloud.vps');
        if ($service->status !== ServiceStatus::Disiapkan || ! $cfg['relay_host']) {
            return;
        }

        $node = $service->node?->jenis === 'incus' ? $service->node : $this->pilihNode();
        if (! $node) {
            return; // penuh: admin menyiapkan manual seperti paket pemasok
        }

        // Pesan nomor VM dulu (IP + port relay) agar percobaan ulang memakai nomor yang sama.
        $n = $service->detail['vm'] ?? $this->nomorBebas($node);
        if (! $n) {
            return;
        }
        $service->update(['node_id' => $node->id, 'detail' => [...($service->detail ?? []), 'vm' => $n]]);

        $incus = new Incus($node);
        $nama = $service->podName();
        $sandi = PodSpec::sandi();
        if (! $incus->ada($nama)) {
            $incus->buat(self::instance($service, $nama, $n, $sandi));
        } else {
            $sandi = isset($service->detail['kredensial']) ? json_decode(Crypt::decryptString($service->detail['kredensial']), true)['sandi'] : $sandi;
        }
        if (($incus->state($nama)['status'] ?? '') !== 'Running') {
            $incus->aksi($nama, 'start');
        }

        $ssh = $cfg['port_ssh'] + $n;
        $ekstra = $cfg['port_ekstra'] + $n * 10;
        $service->update([
            'status' => ServiceStatus::Aktif,
            'pesan_gagal' => null,
            'detail' => [
                ...$service->detail,
                'host' => $cfg['relay_host'],
                'host_port' => $ssh,
                'ip' => $cfg['subnet'].'.'.$n,
                'kredensial' => Crypt::encryptString(json_encode(['pengguna' => 'root', 'sandi' => $sandi])),
                'akses' => "SSH: ssh root@{$cfg['relay_host']} -p {$ssh}\n"
                    ."Port tambahan: {$cfg['relay_host']}:{$ekstra}-".($ekstra + 9)." (TCP/UDP) diteruskan ke port yang sama di VPS.\n"
                    .'VPS siap dipakai ±1-3 menit setelah aktif (cloud-init memasang SSH).',
            ],
        ]);
    }

    public function failed(Throwable $e): void
    {
        $this->service->fresh()->update(['status' => ServiceStatus::Gagal, 'pesan_gagal' => mb_substr($e->getMessage(), 0, 1000)]);
    }

    /** Body POST /1.0/instances. Spek dari plans.spek landing ("1 Core", "2 GB", "20 GB NVMe"). */
    public static function instance(Service $service, string $nama, int $n, string $sandi): array
    {
        $cfg = config('cloud.vps');
        $angka = fn (?string $s) => max(1, (int) preg_replace('/\D.*$/', '', trim((string) $s)));
        $spek = $service->plan->spek ?? [];
        $os = $cfg['os'][$service->detail['os'] ?? ''] ?? reset($cfg['os']);

        return [
            'name' => $nama,
            'type' => 'virtual-machine',
            'source' => ['type' => 'image', 'mode' => 'pull', 'protocol' => 'simplestreams', 'server' => $cfg['image_server'], 'alias' => $os['alias']],
            'config' => [
                'limits.cpu' => (string) $angka($spek['cpu'] ?? null),
                'limits.memory' => $angka($spek['ram'] ?? null).'GiB',
                'security.secureboot' => 'false',
                'boot.autostart' => 'true',
                'cloud-init.user-data' => self::cloudInit($nama, $sandi),
                'user.velocity.service' => (string) $service->id,
            ],
            'devices' => [
                'root' => ['type' => 'disk', 'path' => '/', 'pool' => $cfg['pool'], 'size' => $angka($spek['disk'] ?? null).'GiB'],
                'eth0' => ['type' => 'nic', 'name' => 'eth0', 'network' => $cfg['network'], 'ipv4.address' => $cfg['subnet'].'.'.$n],
            ],
        ];
    }

    /** Login root + sandi lewat SSH (sandi hanya A-Z/a-z/0-9, aman di YAML). */
    public static function cloudInit(string $nama, string $sandi): string
    {
        return <<<YAML
        #cloud-config
        hostname: {$nama}
        disable_root: false
        ssh_pwauth: true
        chpasswd:
          expire: false
          users:
            - {name: root, password: {$sandi}, type: text}
        write_files:
          - path: /etc/ssh/sshd_config.d/00-velocity.conf
            content: |
              PermitRootLogin yes
              PasswordAuthentication yes
        packages: [openssh-server]
        runcmd:
          - [systemctl, restart, ssh]
        YAML;
    }

    /** Node Incus aktif dengan VM paling sedikit di bawah kapasitas. */
    private function pilihNode(): ?Node
    {
        return Node::where('aktif', true)->where('jenis', 'incus')
            ->withCount(['services' => fn ($q) => $q->whereIn('status', [ServiceStatus::Disiapkan, ServiceStatus::Aktif, ServiceStatus::Ditangguhkan])])
            ->get()
            ->filter(fn (Node $n) => $n->services_count < $n->kapasitas_pod)
            ->sortBy('services_count')
            ->first();
    }

    /** Nomor 2..254 terkecil yang belum pernah dipakai di node ini (VM layanan dibatalkan belum dihapus otomatis). */
    private function nomorBebas(Node $node): ?int
    {
        $terpakai = Service::where('node_id', $node->id)->get()
            ->pluck('detail.vm')->filter()->all();

        return collect(range(2, 254))->first(fn ($n) => ! in_array($n, $terpakai));
    }
}
