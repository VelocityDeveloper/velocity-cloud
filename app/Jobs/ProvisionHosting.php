<?php

namespace App\Jobs;

use App\Enums\ServiceStatus;
use App\Models\Node;
use App\Models\Service;
use App\Services\Provisioning\DirectAdmin;
use App\Services\Provisioning\PodSpec;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Throwable;

/** Shared hosting: buat akun DirectAdmin (paket velocity_<MB>mb) untuk domain pesanan setelah tagihan pertama lunas. */
class ProvisionHosting implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [60];

    public int $timeout = 180;

    /** Nama user sistem/umum yang ditolak DirectAdmin atau berisiko bentrok. */
    private const DICADANGKAN = ['root', 'admin', 'diradmin', 'mysql', 'mail', 'www', 'apache', 'nginx', 'named', 'ftp', 'nobody', 'test', 'user', 'webmaster'];

    public function __construct(public Service $service) {}

    public function handle(): void
    {
        $service = $this->service->fresh(['plan', 'node', 'user']);
        if ($service->status !== ServiceStatus::Disiapkan) {
            return;
        }
        $node = $service->node?->jenis === 'directadmin' ? $service->node : $this->pilihNode();
        if (! $node) {
            throw new RuntimeException('Belum ada node DirectAdmin aktif dengan kapasitas tersisa.');
        }
        $da = new DirectAdmin($node);

        // Username + sandi disimpan sebelum akun dibuat: percobaan ulang memakai akun yang sama, bukan membuat akun kedua.
        $akun = isset($service->detail['kredensial']) ? json_decode(Crypt::decryptString($service->detail['kredensial']), true) : null;
        if (! $akun) {
            $akun = ['pengguna' => $this->usernameBebas($da, $service->custom_domain), 'sandi' => PodSpec::sandi()];
            $service->update(['node_id' => $node->id, 'detail' => [...($service->detail ?? []), 'da_user' => $akun['pengguna'], 'kredensial' => Crypt::encryptString(json_encode($akun))]]);
        }

        $pemilik = $da->config();
        if (! $da->userAda($akun['pengguna'])) {
            $da->buatAkun($akun['pengguna'], $akun['sandi'], $service->user->email, $service->custom_domain, self::paketDa($service), $pemilik['ip']);
        }

        $panel = rtrim($node->agent_url, '/');
        $service->update([
            'status' => ServiceStatus::Aktif,
            'pesan_gagal' => null,
            'detail' => [
                ...$service->detail,
                'host' => $pemilik['ip'],
                'akses' => "Panel DirectAdmin: {$panel}\n"
                    ."Arahkan nameserver domain ke {$pemilik['ns1']} dan {$pemilik['ns2']}, atau A record ke {$pemilik['ip']}.\n"
                    .'SSL bisa diaktifkan dari panel setelah domain mengarah ke server.',
            ],
        ]);
    }

    public function failed(Throwable $e): void
    {
        $this->service->fresh()->update(['status' => ServiceStatus::Gagal, 'pesan_gagal' => mb_substr($e->getMessage(), 0, 1000)]);
    }

    /** "5 GB" → velocity_5000mb (nama paket DA sama dengan CRM velocitydeveloper.net). */
    public static function paketDa(Service $service): string
    {
        return 'velocity_'.((int) ($service->plan->spek['disk'] ?? 0) * 1000).'mb';
    }

    /** Dari domain: huruf+angka label pertama, diawali huruf, maks 8 + 2 angka bila terpakai (batas username DA 10). */
    public static function usernameDasar(string $domain): string
    {
        $u = preg_replace('/[^a-z0-9]/', '', strtolower(explode('.', $domain)[0]));
        $u = preg_match('/^[a-z]/', $u) ? $u : 'u'.$u;

        return substr($u, 0, 8);
    }

    private function usernameBebas(DirectAdmin $da, string $domain): string
    {
        $dasar = self::usernameDasar($domain);
        foreach ([$dasar, ...array_map(fn () => $dasar.random_int(10, 99), range(1, 8))] as $u) {
            if (strlen($u) >= 3 && ! in_array($u, self::DICADANGKAN, true) && ! Service::where('detail->da_user', $u)->exists() && ! $da->userAda($u)) {
                return $u;
            }
        }
        throw new RuntimeException("Tidak menemukan username DirectAdmin bebas untuk {$domain}.");
    }

    private function pilihNode(): ?Node
    {
        return Node::where('aktif', true)->where('jenis', 'directadmin')
            ->withCount(['services' => fn ($q) => $q->whereIn('status', [ServiceStatus::Aktif, ServiceStatus::Ditangguhkan, ServiceStatus::Disiapkan])])
            ->get()
            ->filter(fn (Node $n) => $n->kapasitas_pod === null || $n->services_count < $n->kapasitas_pod)
            ->sortBy('services_count')
            ->first();
    }
}
