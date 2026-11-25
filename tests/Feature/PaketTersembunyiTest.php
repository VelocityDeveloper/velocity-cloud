<?php

namespace Tests\Feature;

use App\Actions\Katalog\UbahPaket;
use App\Enums\PlanKategori;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Paket custom khusus klien: tak tampil di katalog, tetap bisa dipesan lewat link, tak dimatikan katalog:sync, tak ditulis ke landing. */
class PaketTersembunyiTest extends TestCase
{
    use RefreshDatabase;

    public function test_paket_tersembunyi_hanya_lewat_link_langsung(): void
    {
        $dir = sys_get_temp_dir().'/tersembunyi-test-'.uniqid();
        mkdir($dir, 0700, true);
        file_put_contents("$dir/paket.json", json_encode(['vps' => [], 'managed' => []]));
        file_put_contents("$dir/apps.json", '[]');
        config(['cloud.katalog_dir' => $dir, 'cloud.varian' => []]);

        $plan = Plan::create([
            'kategori' => PlanKategori::Vps, 'slug' => 'vps-custom-uji', 'nama' => 'Custom Uji', 'harga' => 1500000, 'stok' => 1, 'tersembunyi' => true,
            'spek' => ['cpu' => '2 Core', 'ram' => '4 GB', 'disk' => '80 GB NVMe'], 'fitur' => ['a'],
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->get('/katalog/cloud')->assertInertia(fn (Assert $p) => $p->where('paket', []));
        $this->actingAs($user)->get('/katalog/vps-custom-uji')->assertOk();

        $this->artisan('katalog:sync')->assertSuccessful();
        $this->assertTrue($plan->fresh()->aktif);

        // tanpa lewat landing: paket.json tak memuatnya, jadi HargaLanding akan melempar bila dipanggil
        $this->assertFalse(app(UbahPaket::class)->handle($plan->fresh(), 1400000, true, 1));
        $this->assertSame(1400000, $plan->fresh()->harga);
    }
}
