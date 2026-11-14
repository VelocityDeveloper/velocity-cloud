<?php

namespace Tests\Feature;

use App\Actions\Billing\ProsesTagihan;
use App\Actions\Billing\TandaiLunas;
use App\Enums\PlanKategori;
use App\Jobs\PodAction;
use App\Jobs\ProvisionVps;
use App\Models\Invoice;
use App\Models\Node;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VpsTest extends TestCase
{
    use RefreshDatabase;

    private const PEM = "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n";

    protected function setUp(): void
    {
        parent::setUp();
        config(['cloud.vps.relay_host' => 'relay.contoh.id']);
    }

    private function plan(string $slug = 'vps-cloud-2'): Plan
    {
        return Plan::create(['kategori' => PlanKategori::Vps, 'slug' => $slug, 'nama' => 'Cloud 2', 'harga' => 168000,
            'spek' => ['cpu' => '1 Core', 'ram' => '2 GB', 'disk' => '20 GB NVMe', 'bandwidth' => '1 TB/bln']]);
    }

    private function node(int $kapasitas = 3): Node
    {
        return Node::create(['nama' => 'pc-bekas', 'jenis' => 'incus', 'agent_url' => 'https://192.168.72.50:8443', 'token' => self::PEM, 'kapasitas_pod' => $kapasitas]);
    }

    private function pesanDanLunas(Plan $plan, array $data = ['os' => 'debian-12']): Service
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('katalog.store', $plan), ['label' => 'VPS saya', ...$data])->assertRedirect();
        app(TandaiLunas::class)->handle(Invoice::latest('id')->first(), 'transfer');

        return Service::latest('id')->first();
    }

    private function fakeIncus(bool $ada = false): void
    {
        Http::fake([
            '*/1.0/instances/svc-*/state*' => Http::sequence()->push(['metadata' => ['status' => 'Stopped']])->push(['operation' => '/1.0/operations/b'])->whenEmpty(['metadata' => ['status' => 'Running']]),
            '*/1.0/instances/svc-*' => $ada ? Http::response(['metadata' => []]) : Http::response(['error' => 'not found'], 404),
            '*/1.0/instances*' => Http::response(['operation' => '/1.0/operations/a']),
            '*/1.0/operations/*' => Http::response(['metadata' => ['status' => 'Success', 'err' => '']]),
        ]);
    }

    public function test_lunas_membuat_vm_dengan_spek_os_ip_dan_port_relay(): void
    {
        $this->fakeIncus();
        $this->node();
        $plan = $this->plan();

        $this->actingAs(User::factory()->create())->post(route('katalog.store', $plan), ['label' => 'x'])->assertSessionHasErrors('os');
        $s = $this->pesanDanLunas($plan); // queue sync: ProvisionVps langsung jalan

        $s->refresh();
        $this->assertSame('aktif', $s->status->value);
        $this->assertSame([2, '10.77.0.2', 'relay.contoh.id', 22002], [$s->detail['vm'], $s->detail['ip'], $s->detail['host'], $s->detail['host_port']]);
        $this->assertStringContainsString('relay.contoh.id:40020-40029', $s->detail['akses']);
        $kred = json_decode(Crypt::decryptString($s->detail['kredensial']), true);
        $this->assertSame('root', $kred['pengguna']);

        $buat = collect(Http::recorded())->first(fn ($r) => $r[0]->method() === 'POST' && str_ends_with(parse_url($r[0]->url(), PHP_URL_PATH), '/1.0/instances'))[0];
        $this->assertStringContainsString('project=velocity', $buat->url());
        $this->assertSame('virtual-machine', $buat['type']);
        $this->assertSame('debian/12/cloud', $buat['source']['alias']);
        $this->assertSame(['1', '2GiB'], [$buat['config']['limits.cpu'], $buat['config']['limits.memory']]);
        $this->assertSame('20GiB', $buat['devices']['root']['size']);
        $this->assertSame('10.77.0.2', $buat['devices']['eth0']['ipv4.address']);
        $this->assertStringContainsString('password: '.$kred['sandi'], $buat['config']['cloud-init.user-data']);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['action'] === 'start');
    }

    public function test_tanpa_node_atau_paket_bukan_otomatis_tetap_manual(): void
    {
        Http::fake();
        $s = $this->pesanDanLunas($this->plan()); // belum ada node incus
        $this->assertSame('disiapkan', $s->fresh()->status->value);

        $this->node();
        $s2 = $this->pesanDanLunas($this->plan('vps-cloud-6'), []); // paket besar: manual, tanpa pilihan OS
        $this->assertSame('disiapkan', $s2->fresh()->status->value);
        Http::assertNothingSent();
    }

    public function test_nomor_vm_tidak_dipakai_ulang_dan_kapasitas_dihormati(): void
    {
        $this->fakeIncus();
        $node = $this->node(2);
        $plan = $this->plan();
        Service::create(['user_id' => User::factory()->create()->id, 'plan_id' => $plan->id, 'node_id' => $node->id, 'label' => 'lama', 'status' => 'dibatalkan', 'harga' => 1, 'detail' => ['vm' => 2]]);

        $a = $this->pesanDanLunas($plan);
        $this->assertSame(3, $a->fresh()->detail['vm']);

        Service::create(['user_id' => User::factory()->create()->id, 'plan_id' => $plan->id, 'node_id' => $node->id, 'label' => 'penuh', 'status' => 'aktif', 'harga' => 1, 'detail' => ['vm' => 9]]);
        $b = $this->pesanDanLunas($plan); // node penuh (2 aktif) → manual
        $this->assertSame(['disiapkan', null], [$b->fresh()->status->value, $b->fresh()->node_id]);
    }

    public function test_vm_aktif_bisa_dikontrol_dan_ditangguhkan(): void
    {
        $node = $this->node();
        $plan = $this->plan();
        $user = User::factory()->create();
        $s = Service::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'node_id' => $node->id, 'label' => 'v', 'status' => 'aktif', 'harga' => 168000, 'jatuh_tempo' => today()->subDays(10), 'detail' => ['vm' => 2]]);
        Invoice::create(['user_id' => $user->id, 'service_id' => $s->id, 'nomor' => 'INV-X', 'jumlah' => 1, 'status' => 'belum_dibayar', 'periode_mulai' => today(), 'periode_selesai' => today(), 'jatuh_tempo' => today()->subDays(5)]);

        Queue::fake();
        $this->actingAs($user)->post(route('layanan.aksi', $s), ['aksi' => 'restart'])->assertRedirect();
        Queue::assertPushed(PodAction::class, fn ($j) => $j->aksi === 'restart');

        app(ProsesTagihan::class)->handle();
        $this->assertSame('ditangguhkan', $s->fresh()->status->value);
        Queue::assertPushed(PodAction::class, fn ($j) => $j->aksi === 'stop');

        Http::fake(['*/state*' => Http::response(['operation' => '/1.0/operations/c']), '*/operations/*' => Http::response(['metadata' => ['status' => 'Success', 'err' => '']])]);
        (new PodAction($s, 'stop'))->handle();
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['action'] === 'stop' && $r['force'] === false);
    }

    public function test_percobaan_ulang_memakai_vm_yang_sudah_ada(): void
    {
        $this->fakeIncus(ada: true);
        $this->node();
        $s = $this->pesanDanLunas($this->plan());
        $this->assertSame('aktif', $s->fresh()->status->value);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_job_tidak_jalan_tanpa_relay(): void
    {
        config(['cloud.vps.relay_host' => null]);
        Http::fake();
        $this->node();
        $s = $this->pesanDanLunas($this->plan());
        $this->assertSame('disiapkan', $s->fresh()->status->value);
        Http::assertNothingSent();
    }
}
