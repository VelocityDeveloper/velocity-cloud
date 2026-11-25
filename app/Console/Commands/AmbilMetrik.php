<?php

namespace App\Console\Commands;

use App\Enums\ServiceStatus;
use App\Models\Node;
use App\Models\NodeMetrik;
use App\Models\Service;
use App\Models\ServiceMetrik;
use App\Services\Provisioning\Incus;
use App\Services\Provisioning\NodeAgent;
use Illuminate\Support\Arr;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Throwable;

/** Sampel pemakaian VPS (node Incus) dan mesin node pod/Incus tiap menit untuk grafik layanan & Admin › Node; disimpan 7 hari. */
class AmbilMetrik extends Command
{
    // Mesin node Incus: sertifikat app (restricted) tak boleh baca statistik host → baca /proc lewat SSH.
    // Bagian dipisah '@'; disk = pool Incus 'default' (LVM/ZFS tak terlihat di df), gagal → df /.
    private const PERINTAH_HOST = 'head -1 /proc/stat; echo @; cat /proc/meminfo; echo @; cut -d" " -f1 /proc/loadavg; cut -d" " -f1 /proc/uptime; nproc; echo @; '
        .'for i in /sys/class/net/*; do [ -e "$i/device" ] && echo "${i##*/}"; done; echo @; tail -n +3 /proc/net/dev; echo @; '
        .'d=$(incus storage info default --bytes 2>/dev/null | grep -E "space used|total space" | tr -dc "0-9\n"); '
        .'[ -n "$d" ] && echo "$d" || df -B1 --output=used,size / | tail -1';

    protected $signature = 'metrik:ambil';

    protected $description = 'Ambil sampel CPU/RAM/proses/jaringan VPS Incus dan mesin node pod';

    public function handle(): int
    {
        Service::where('status', ServiceStatus::Aktif)->whereHas('node', fn ($q) => $q->where('jenis', 'incus')->where('aktif', true))
            ->with(['node', 'plan'])->each(function (Service $s) {
                try {
                    $m = (new Incus($s->node))->ringkas($s->podName())['metrik'] ?? null;
                    if (! $m) {
                        return;
                    }
                    $lalu = ServiceMetrik::where('service_id', $s->id)->latest('id')->first();
                    $vcpu = max(1, (int) preg_replace('/\D.*$/', '', (string) ($s->plan->spek['cpu'] ?? '1')));
                    $dt = $lalu ? $m['waktu'] - $lalu->waktu : 0;
                    $cpu = $lalu && $lalu->cpu_ns !== null && $dt > 0 && $m['cpu_ns'] >= $lalu->cpu_ns
                        ? min(100, round(($m['cpu_ns'] - $lalu->cpu_ns) / ($dt * $vcpu * 1e9) * 100, 1)) : null;
                    ServiceMetrik::create([...$m, 'service_id' => $s->id, 'cpu' => $cpu]);
                } catch (Throwable $e) {
                    report($e);
                }
            });
        // Mesin node pod (agent GET /host) dan node Incus (SSH, juga yang nonaktif/belum dibuka untuk pesanan); DirectAdmin belum punya sumber data host.
        Node::where(fn ($q) => $q->where('jenis', 'pod')->where('aktif', true))->orWhere('jenis', 'incus')->each(function (Node $n) {
            try {
                $m = $n->jenis === 'incus' ? $this->hostSsh($n) : (new NodeAgent($n))->host();
                $lalu = $n->metrik()->latest('id')->first();
                $dTotal = $lalu ? $m['cpu_total'] - $lalu->cpu_total : 0;
                $cpu = $dTotal > 0 && $m['cpu_idle'] >= $lalu->cpu_idle ? round((1 - ($m['cpu_idle'] - $lalu->cpu_idle) / $dTotal) * 100, 1) : null;
                $n->metrik()->create([...Arr::only($m, ['waktu', 'cpu_total', 'cpu_idle', 'inti', 'ram', 'ram_total', 'disk', 'disk_total', 'load', 'uptime', 'rx', 'tx']), 'cpu' => $cpu]);
            } catch (Throwable $e) {
                // node tanpa akses SSH (mis. kunci belum dipasang) gagal tiap menit → catat sekali per jam
                if (Cache::add("metrik-node-gagal-{$n->id}", 1, 3600)) {
                    report($e);
                }
            }
        });
        ServiceMetrik::where('waktu', '<', now()->subDays(7)->timestamp)->delete();
        NodeMetrik::where('waktu', '<', now()->subDays(7)->timestamp)->delete();

        return self::SUCCESS;
    }

    /**
     * Host = host agent_url (Incus API :8443); user, port & kunci diatur di /root/.ssh/config per host itu.
     * shortcut: tanpa kolom SSH per node, tambah kolom bila node dikelola orang lain/tanpa akses root PC app.
     */
    private function hostSsh(Node $n): array
    {
        $host = (string) parse_url($n->agent_url, PHP_URL_HOST);
        $r = Process::timeout(20)->run(['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=8', $host, self::PERINTAH_HOST]);
        throw_unless($r->successful(), \RuntimeException::class, "SSH node {$n->nama} ({$host}) gagal: ".trim($r->errorOutput()));

        return self::uraiHost($r->output());
    }

    /** Urai keluaran PERINTAH_HOST jadi format yang sama dengan agent pod GET /host. */
    public static function uraiHost(string $teks): array
    {
        $b = array_map('trim', explode('@', $teks));
        throw_unless(count($b) === 6, \RuntimeException::class, 'Keluaran host tidak lengkap');
        $cpu = array_map('intval', array_slice(preg_split('/\s+/', $b[0]), 1));
        preg_match_all('/^(\w+):\s+(\d+)/m', $b[1], $mm);
        $mem = array_combine($mm[1], array_map(fn ($v) => (int) $v * 1024, $mm[2]));
        [$load, $uptime, $inti] = preg_split('/\s+/', $b[2]);
        $fisik = preg_split('/\s+/', $b[3], -1, PREG_SPLIT_NO_EMPTY);
        $rx = $tx = 0;
        foreach (explode("\n", $b[4]) as $l) {
            [$i, $isi] = array_pad(explode(':', $l, 2), 2, '');
            if (in_array(trim($i), $fisik, true)) {
                $k = preg_split('/\s+/', trim($isi));
                $rx += (int) $k[0];
                $tx += (int) $k[8];
            }
        }
        [$disk, $diskTotal] = array_map('intval', preg_split('/\s+/', $b[5]));
        throw_unless($cpu && isset($mem['MemTotal'], $mem['MemAvailable']) && $diskTotal > 0, \RuntimeException::class, 'Keluaran host tidak terbaca');

        return [
            'waktu' => time(), 'inti' => (int) $inti,
            'cpu_total' => array_sum($cpu), 'cpu_idle' => $cpu[3] + ($cpu[4] ?? 0), // jiffies; idle + iowait
            'ram' => $mem['MemTotal'] - $mem['MemAvailable'], 'ram_total' => $mem['MemTotal'],
            'disk' => $disk, 'disk_total' => $diskTotal,
            'load' => (float) $load, 'uptime' => (int) $uptime, 'rx' => $rx, 'tx' => $tx,
        ];
    }
}
