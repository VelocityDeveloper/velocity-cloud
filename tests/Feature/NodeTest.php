<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Jobs\ProvisionPod;
use App\Models\Node;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class NodeTest extends TestCase
{
    use RefreshDatabase;

    private function nodeDa(): Node
    {
        return Node::create(['nama' => 'sanke', 'jenis' => 'directadmin', 'agent_url' => 'https://da.test:2222', 'da_user' => 'admin', 'token' => str_repeat('k', 64), 'kapasitas_pod' => 100]);
    }

    public function test_admin_menambah_node_directadmin_wajib_user_da(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $data = ['nama' => 'sanke', 'jenis' => 'directadmin', 'agent_url' => 'https://da.test:2222', 'token' => str_repeat('k', 64), 'kapasitas_pod' => 100, 'aktif' => true];

        $this->actingAs($admin)->post(route('admin.node.store'), $data)->assertSessionHasErrors('da_user');
        $this->actingAs($admin)->post(route('admin.node.store'), $data + ['da_user' => 'admin'])->assertSessionHasNoErrors();

        $node = Node::sole();
        $this->assertSame('directadmin', $node->jenis);
        $this->assertNotSame(str_repeat('k', 64), $node->getRawOriginal('token')); // login key terenkripsi
    }

    public function test_uji_directadmin_membaca_paket_dan_menolak_balasan_html(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $node = $this->nodeDa();

        Http::fake(['da.test:2222/CMD_API_PACKAGES_USER' => Http::sequence()
            ->push('list[]=paket1&list[]=paket2', 200, ['Content-Type' => 'text/plain; charset=utf-8'])
            ->push('<html>login</html>', 200, ['Content-Type' => 'text/html'])]); // key salah → DA memberi halaman login
        $this->actingAs($admin)->post(route('admin.node.uji', $node))->assertInertiaFlash('toast.type', 'success');
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('admin:'.str_repeat('k', 64))));

        $this->actingAs($admin)->post(route('admin.node.uji', $node))->assertInertiaFlash('toast.type', 'error');
    }

    public function test_pod_tidak_pernah_dikirim_ke_node_directadmin(): void
    {
        $this->nodeDa();
        $plan = Plan::create(['kategori' => 'app', 'slug' => 'app-n8n', 'nama' => 'n8n', 'harga' => 50000]);
        $service = Service::create(['user_id' => User::factory()->create()->id, 'plan_id' => $plan->id, 'label' => 'n', 'subdomain' => 'n1', 'status' => ServiceStatus::Disiapkan, 'harga' => 50000]);
        Http::fake();

        (new ProvisionPod($service))->handle();

        $this->assertSame(ServiceStatus::Gagal, $service->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_metrik_ambil_menyimpan_sampel_mesin_node_pod_dan_detail_menampilkannya(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $node = Node::create(['nama' => 'pc', 'jenis' => 'pod', 'agent_url' => 'http://agent.test', 'token' => str_repeat('t', 40), 'kapasitas_pod' => 5]);
        $host = fn ($w, $total, $idle, $rx) => ['waktu' => $w, 'inti' => 8, 'cpu_total' => $total, 'cpu_idle' => $idle, 'ram' => 4 << 30, 'ram_total' => 16 << 30,
            'disk' => 50 << 30, 'disk_total' => 200 << 30, 'load' => 1.5, 'uptime' => 3600, 'rx' => $rx, 'tx' => 0];
        Http::fake(['agent.test/host' => Http::sequence()->push($host(time() - 60, 1000, 800, 0))->push($host(time(), 2000, 1550, 6000))]);

        $this->artisan('metrik:ambil')->assertSuccessful();
        $this->artisan('metrik:ambil')->assertSuccessful();

        $this->assertNull($node->metrik()->oldest('id')->first()->cpu); // sampel pertama belum punya pembanding
        $this->assertEqualsWithDelta(25.0, $node->metrik()->latest('id')->first()->cpu, 0.01); // 1 - 750/1000

        $this->actingAs($admin)->get(route('admin.node.show', $node))->assertInertia(fn ($page) => $page
            ->component('admin/NodeShow')
            ->where('terkini.disk_total', 200 << 30)
            ->where('riwayat.rentang', '24j')
            ->has('riwayat.titik'));
        $this->actingAs(User::factory()->create())->get(route('admin.node.show', $node))->assertForbidden();
    }

    public function test_metrik_ambil_membaca_mesin_node_incus_lewat_ssh_termasuk_node_nonaktif(): void
    {
        $node = Node::create(['nama' => 'vps', 'jenis' => 'incus', 'agent_url' => 'https://10.79.0.1:8443', 'token' => 'pem', 'kapasitas_pod' => 1, 'aktif' => false]);
        $keluaran = fn ($user, $idle, $rx) => "cpu  {$user} 0 0 {$idle} 0 0 0 0 0 0\n@\nMemTotal:       16000000 kB\nMemFree:  1 kB\nMemAvailable:   12000000 kB\n@\n"
            ."0.43\n64960\n56\n@\neno1\n@\n    lo: 999 1 0 0 0 0 0 0 999 1 0 0 0 0 0 0\n  eno1: {$rx} 10 0 0 0 0 0 0 500 5 0 0 0 0 0 0\n vpsbr0: 7 1 0 0 0 0 0 0 7 1 0 0 0 0 0 0\n@\n6497199613\n1755999895552\n";
        Process::preventStrayProcesses();
        Process::fake(['*' => Process::sequence()->push(Process::result($keluaran(200, 800, 1000)))->push(Process::result($keluaran(450, 1550, 4000)))]);

        $this->artisan('metrik:ambil')->assertSuccessful();
        $this->artisan('metrik:ambil')->assertSuccessful();

        Process::assertRanTimes(fn ($p) => $p->command[0] === 'ssh' && in_array('10.79.0.1', $p->command, true), 2);
        $m = $node->metrik()->latest('id')->first();
        $this->assertEqualsWithDelta(25.0, $m->cpu, 0.01); // total 1000→2000, idle 800→1550
        $this->assertSame([56, 4000 * 1024 * 1000, 16000000 * 1024, 1755999895552, 4000, 500],
            [$m->inti, $m->ram, $m->ram_total, $m->disk_total, $m->rx, $m->tx]);
    }
}
