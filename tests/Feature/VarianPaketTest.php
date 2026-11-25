<?php

namespace Tests\Feature;

use App\Enums\ServiceStatus;
use App\Jobs\ProvisionPod;
use App\Models\Node;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use App\Services\Katalog\HargaLanding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Varian paket app (#16): beberapa pilihan CPU/RAM untuk satu app, pod sama, limit berbeda. */
class VarianPaketTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/varian-test-'.uniqid().'/src/data';
        mkdir($this->dir, 0700, true);
        file_put_contents("$this->dir/paket.json", json_encode(['vps' => [], 'managed' => []]));
        file_put_contents("$this->dir/apps.json", json_encode([[
            'slug' => 'waha', 'nama' => 'WAHA', 'kategori' => 'WhatsApp', 'harga' => 50000, 'ringkas' => 'x', 'fitur' => ['a'],
            'pod' => ['cpu' => 0.5, 'ram_mb' => 1024, 'komponen' => ['WAHA']],
            'varian_dasar' => 'Starter',
            'varian' => [['nama' => 'Plus', 'harga' => 80000, 'cpu' => 1, 'ram_mb' => 2048], ['nama' => 'Pro', 'harga' => 150000, 'cpu' => 2, 'ram_mb' => 4096]],
        ]], JSON_PRETTY_PRINT));
        config([
            'cloud.katalog_dir' => $this->dir,
            'cloud.varian' => [
                'waha' => ['app' => 'waha', 'nama' => 'Starter', 'cpu' => 0.5, 'ram_mb' => 1024],
                'waha-plus' => ['app' => 'waha', 'nama' => 'Plus', 'cpu' => 1, 'ram_mb' => 2048],
                'waha-pro' => ['app' => 'waha', 'nama' => 'Pro', 'cpu' => 2, 'ram_mb' => 4096],
            ],
        ]);
    }

    public function test_sync_membuat_varian_dengan_stok_nol_tanpa_menimpa_stok_lama(): void
    {
        $this->artisan('katalog:sync')->assertSuccessful();

        $dasar = Plan::where('slug', 'app-waha')->sole();
        $plus = Plan::where('slug', 'app-waha-plus')->sole();
        $this->assertNull($dasar->stok);
        $this->assertSame(0, $plus->stok);
        $this->assertSame('WAHA Plus', $plus->nama);
        $this->assertSame(80000, $plus->harga);
        $this->assertSame(['kategori_app' => 'WhatsApp', 'cpu' => '1 vCPU', 'ram' => '2 GB', 'disk' => '5 GB'], $plus->spek);
        $this->assertSame('0,5 vCPU', $dasar->spek['cpu']);
        $this->assertFalse($plus->bisaDipesan());

        $plus->update(['stok' => 5]);
        $this->artisan('katalog:sync')->assertSuccessful();
        $this->assertSame(5, $plus->fresh()->stok);
    }

    public function test_varian_memakai_pod_app_induk_dengan_limit_sendiri(): void
    {
        $this->artisan('katalog:sync')->assertSuccessful();
        $pro = Plan::where('slug', 'app-waha-pro')->sole();
        $dasar = Plan::where('slug', 'app-waha')->sole();

        $this->assertSame('waha', $pro->appSlug());
        $this->assertTrue($pro->varianTambahan());
        $this->assertFalse($dasar->varianTambahan());
        $this->assertSame(['cpu' => 2, 'memori' => '4096m'], $pro->pod()['limit']);
        $this->assertSame(['cpu' => 0.5, 'memori' => '1024m'], $dasar->pod()['limit']);
        $this->assertSame(config('cloud.pods.waha.containers'), $pro->pod()['containers']);
        $this->assertSame(['app-waha', 'app-waha-plus', 'app-waha-pro'], $pro->keluargaVarian()->pluck('slug')->all());
    }

    public function test_katalog_menyembunyikan_varian_dan_halaman_pesan_menampilkan_pilihan(): void
    {
        $this->artisan('katalog:sync')->assertSuccessful();
        $user = User::factory()->create();

        $this->withoutVite()->actingAs($user)->get(route('katalog.index'))
            ->assertInertia(fn (Assert $p) => $p->where('paket', fn ($daftar) => collect($daftar)->pluck('slug')->contains('app-waha')
                && ! collect($daftar)->pluck('slug')->contains('app-waha-plus')));

        $this->withoutVite()->actingAs($user)->get(route('katalog.show', 'app-waha'))
            ->assertInertia(fn (Assert $p) => $p->component('katalog/Pesan', false)->has('varian', 3)
                ->where('varian.0.nama_varian', 'Starter')->where('varian.1.slug', 'app-waha-plus')->where('varian.1.habis', true));

        $this->actingAs($user)->post(route('katalog.store', 'app-waha-pro'))->assertStatus(422); // stok 0
    }

    public function test_admin_ubah_harga_varian_menulis_ke_varian_di_json(): void
    {
        $this->artisan('katalog:sync')->assertSuccessful();

        app(HargaLanding::class)->simpan(Plan::where('slug', 'app-waha-plus')->sole(), 90000);

        $data = json_decode(file_get_contents("$this->dir/apps.json"), true);
        $this->assertSame(90000, $data[0]['varian'][0]['harga']);
        $this->assertSame(50000, $data[0]['harga']);
    }

    public function test_pilih_node_menghitung_limit_ram(): void
    {
        $this->artisan('katalog:sync')->assertSuccessful();
        Plan::where('slug', 'like', 'app-waha-%')->update(['stok' => null]);
        $node = Node::create(['nama' => 'pc', 'jenis' => 'pod', 'agent_url' => 'http://agent.test', 'token' => str_repeat('a', 32), 'kapasitas_pod' => 10, 'ram_pod_mb' => 3072]);
        Http::fake(['agent.test/pods' => Http::response(['name' => 'svc', 'status' => 'running'])]);
        $user = User::factory()->create();
        $buat = fn (string $slug, ServiceStatus $status, ?int $nodeId = null) => Service::create([
            'user_id' => $user->id, 'plan_id' => Plan::where('slug', $slug)->sole()->id, 'label' => $slug, 'subdomain' => uniqid('s'),
            'status' => $status, 'harga' => 1, 'node_id' => $nodeId,
        ]);
        $buat('app-waha', ServiceStatus::Aktif, $node->id); // 1024 MB terpakai

        $pro = $buat('app-waha-pro', ServiceStatus::Disiapkan); // 1024 + 4096 > 3072
        (new ProvisionPod($pro))->handle();
        $this->assertSame(ServiceStatus::Gagal, $pro->fresh()->status);

        $plus = $buat('app-waha-plus', ServiceStatus::Disiapkan); // 1024 + 2048 = 3072
        (new ProvisionPod($plus))->handle();
        $this->assertSame(ServiceStatus::Aktif, $plus->fresh()->status);
        Http::assertSent(fn ($r) => $r['limit'] === ['cpu' => 1, 'memori' => '2048m']);

        $node->update(['ram_pod_mb' => null]); // tanpa batas RAM: hanya jumlah pod
        $pro2 = $buat('app-waha-pro', ServiceStatus::Disiapkan);
        (new ProvisionPod($pro2))->handle();
        $this->assertSame(ServiceStatus::Aktif, $pro2->fresh()->status);
    }

    public function test_pesan_ditolak_bila_sisa_ram_node_tidak_cukup(): void
    {
        $this->artisan('katalog:sync')->assertSuccessful();
        Plan::where('slug', 'like', 'app-waha-%')->update(['stok' => null]);
        $node = Node::create(['nama' => 'pc', 'jenis' => 'pod', 'agent_url' => 'http://agent.test', 'token' => str_repeat('a', 32), 'kapasitas_pod' => 10, 'ram_pod_mb' => 4096]);
        $user = User::factory()->create();
        Service::create(['user_id' => $user->id, 'plan_id' => Plan::where('slug', 'app-waha')->sole()->id, 'label' => 'a', 'subdomain' => 'a1', 'status' => ServiceStatus::MenungguPembayaran, 'harga' => 1]);

        $this->assertSame(3072, Node::sisaRamPod()); // pesanan belum bayar ikut memakai jatah
        $this->assertFalse(Plan::where('slug', 'app-waha-pro')->sole()->bisaDipesan());
        $this->assertTrue(Plan::where('slug', 'app-waha-plus')->sole()->bisaDipesan());
        $this->actingAs($user)->post(route('katalog.store', 'app-waha-pro'))->assertStatus(422);

        $node->update(['ram_pod_mb' => null]);
        Once::flush(); // sisaRamPod dimemo per permintaan
        $this->assertNull(Node::sisaRamPod());
        $this->assertTrue(Plan::where('slug', 'app-waha-pro')->sole()->bisaDipesan());
    }

    public function test_sisa_ram_dihitung_per_node_bukan_dijumlah(): void
    {
        $this->artisan('katalog:sync')->assertSuccessful();
        Plan::where('slug', 'like', 'app-waha-%')->update(['stok' => null]);
        $user = User::factory()->create();
        $dasar = Plan::where('slug', 'app-waha')->sole();
        foreach (['a', 'b'] as $nama) { // dua node, masing-masing sisa 2048 MB
            $n = Node::create(['nama' => $nama, 'jenis' => 'pod', 'agent_url' => "http://$nama.test", 'token' => str_repeat('a', 32), 'kapasitas_pod' => 10, 'ram_pod_mb' => 3072]);
            Service::create(['user_id' => $user->id, 'plan_id' => $dasar->id, 'label' => $nama, 'subdomain' => $nama.'1', 'status' => ServiceStatus::Aktif, 'harga' => 1, 'node_id' => $n->id]);
        }

        $this->assertSame(2048, Node::sisaRamPod(segar: true));
        $this->assertFalse(Plan::where('slug', 'app-waha-pro')->sole()->bisaDipesan()); // 4096 tidak muat di satu node
        $this->assertTrue(Plan::where('slug', 'app-waha-plus')->sole()->bisaDipesan());
    }

    public function test_node_dicatat_sebelum_agent_dipanggil_dan_dilepas_bila_gagal(): void
    {
        $this->artisan('katalog:sync')->assertSuccessful();
        Plan::where('slug', 'like', 'app-waha-%')->update(['stok' => null]);
        Node::create(['nama' => 'pc', 'jenis' => 'pod', 'agent_url' => 'http://agent.test', 'token' => str_repeat('a', 32), 'kapasitas_pod' => 10, 'ram_pod_mb' => 8192]);
        $service = Service::create(['user_id' => User::factory()->create()->id, 'plan_id' => Plan::where('slug', 'app-waha-pro')->sole()->id, 'label' => 'p', 'subdomain' => 'p1', 'status' => ServiceStatus::Disiapkan, 'harga' => 1]);
        Http::fake(['agent.test/pods' => function () use ($service) {
            $this->assertNotNull($service->fresh()->node_id); // worker lain sudah melihat jatah RAM pod ini

            return Http::response(['message' => 'gagal'], 500);
        }]);

        try {
            (new ProvisionPod($service))->handle();
        } catch (\Throwable $e) {
            (new ProvisionPod($service))->failed($e);
        }
        $this->assertSame(ServiceStatus::Gagal, $service->fresh()->status);
        $this->assertNull($service->fresh()->node_id);
    }
}
