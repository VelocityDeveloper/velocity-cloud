<?php

namespace Tests\Feature;

use App\Enums\PlanKategori;
use App\Jobs\DeployLanding;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaketTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/paket-test-'.uniqid().'/src/data';
        mkdir($this->dir, 0700, true);
        file_put_contents("$this->dir/paket.json", json_encode(['vps' => [['nama' => 'Cloud 1', 'harga' => 114000, 'cpu' => '1 Core', 'ram' => '1 GB', 'disk' => '15 GB', 'fitur' => []]], 'managed' => []], JSON_PRETTY_PRINT));
        file_put_contents("$this->dir/apps.json", json_encode([['slug' => 'n8n', 'nama' => 'n8n', 'kategori' => 'Otomasi', 'harga' => 50000, 'ringkas' => 'x', 'fitur' => []]], JSON_PRETTY_PRINT));
        config(['cloud.katalog_dir' => $this->dir]);
    }

    private function vps(?int $stok = null): Plan
    {
        return Plan::create(['kategori' => PlanKategori::Vps, 'slug' => 'vps-cloud-1', 'nama' => 'Cloud 1', 'harga' => 114000, 'stok' => $stok]);
    }

    public function test_stok_habis_menolak_pesanan_dan_gagal_mengembalikan_stok(): void
    {
        $user = User::factory()->create();
        $plan = $this->vps(1);

        $this->actingAs($user)->post(route('katalog.store', $plan), ['label' => 'satu', 'os' => 'ubuntu-24.04'])->assertRedirect();
        $this->assertSame(0, $plan->sisaStok());
        $this->actingAs($user)->post(route('katalog.store', $plan), ['label' => 'dua', 'os' => 'ubuntu-24.04'])->assertStatus(422);
        $this->assertSame(1, Service::count());

        Service::sole()->update(['status' => 'dibatalkan']);
        $this->assertTrue($plan->fresh()->bisaDipesan());
    }

    public function test_admin_ubah_harga_menulis_json_landing_dan_build_ulang(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $plan = $this->vps();

        $this->actingAs($admin)->put(route('admin.paket.update', $plan), ['harga' => 150000, 'aktif' => true, 'stok' => 5])->assertRedirect();

        $plan->refresh();
        $this->assertSame([150000, 5], [$plan->harga, $plan->stok]);
        $this->assertSame(150000, json_decode(file_get_contents("$this->dir/paket.json"), true)['vps'][0]['harga']);
        Queue::assertPushed(DeployLanding::class);

        // Off → "aktif": false di JSON + build; on lagi → kunci dihapus.
        Queue::fake();
        $this->actingAs($admin)->put(route('admin.paket.update', $plan), ['harga' => 150000, 'aktif' => false, 'stok' => 5])->assertRedirect();
        $this->assertFalse($plan->fresh()->aktif);
        $this->assertFalse(json_decode(file_get_contents("$this->dir/paket.json"), true)['vps'][0]['aktif']);
        Queue::assertPushed(DeployLanding::class);
        $this->actingAs($admin)->put(route('admin.paket.update', $plan), ['harga' => 150000, 'aktif' => true, 'stok' => 5])->assertRedirect();
        $this->assertArrayNotHasKey('aktif', json_decode(file_get_contents("$this->dir/paket.json"), true)['vps'][0]);

        // Hanya stok berubah → tanpa build landing.
        Queue::fake();
        $this->actingAs($admin)->put(route('admin.paket.update', $plan), ['harga' => 150000, 'aktif' => true, 'stok' => null])->assertRedirect();
        Queue::assertNothingPushed();

        $this->actingAs(User::factory()->create())->put(route('admin.paket.update', $plan), ['harga' => 1, 'aktif' => true])->assertForbidden();
    }

    public function test_sync_tidak_menimpa_aktif_dan_stok(): void
    {
        $plan = $this->vps(3);
        $plan->update(['aktif' => false]);

        $this->artisan('katalog:sync')->assertSuccessful();

        $plan->refresh();
        $this->assertFalse($plan->aktif);
        $this->assertSame(3, $plan->stok);
        $this->assertTrue(Plan::where('slug', 'app-n8n')->sole()->aktif);
    }

    public function test_halaman_paket_per_kategori(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->vps();
        Plan::create(['kategori' => PlanKategori::Ai, 'slug' => 'ai-x', 'nama' => 'AI X', 'harga' => 5000]);

        $this->actingAs($admin)->get('/admin/paket')->assertRedirect('/admin/paket/vps');
        $this->actingAs($admin)->get(route('admin.paket.index', 'ai'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('admin/Paket')->where('kategori.value', 'ai')->has('paket', 1)->where('paket.0.nama', 'AI X'));
        $this->actingAs($admin)->get('/admin/paket/salah')->assertNotFound();
        $this->actingAs(User::factory()->create())->get(route('admin.paket.index', 'vps'))->assertForbidden();
    }
}
