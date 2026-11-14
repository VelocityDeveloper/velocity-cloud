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
}
