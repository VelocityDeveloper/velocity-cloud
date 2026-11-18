<?php

namespace Tests\Feature;

use App\Enums\PlanKategori;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusKatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_katalog_publik_menandai_stok_habis(): void
    {
        Plan::create(['kategori' => PlanKategori::Vps, 'slug' => 'vps-cloud-1', 'nama' => 'Cloud 1', 'harga' => 114000, 'stok' => null]);
        Plan::create(['kategori' => PlanKategori::Vps, 'slug' => 'vps-cloud-5', 'nama' => 'Cloud 5', 'harga' => 500000, 'stok' => 0]);

        $this->getJson('/api/katalog/status')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertCookieMissing('XSRF-TOKEN')
            ->assertExactJson([
                'vps-cloud-1' => ['habis' => false, 'bisa_dipesan' => true],
                'vps-cloud-5' => ['habis' => true, 'bisa_dipesan' => false],
            ]);
    }

    public function test_stok_terpakai_penuh_dan_paket_nonaktif_tidak_bisa_dipesan(): void
    {
        $user = User::factory()->create();
        $penuh = Plan::create(['kategori' => PlanKategori::Vps, 'slug' => 'vps-cloud-2', 'nama' => 'Cloud 2', 'harga' => 200000, 'stok' => 1]);
        Plan::create(['kategori' => PlanKategori::Vps, 'slug' => 'vps-cloud-3', 'nama' => 'Cloud 3', 'harga' => 300000, 'stok' => null, 'aktif' => false]);
        $this->actingAs($user)->post(route('katalog.store', $penuh), ['label' => 'satu', 'os' => 'ubuntu-24.04'])->assertRedirect();
        $this->assertSame(1, Service::count());

        $this->getJson('/api/katalog/status')->assertOk()->assertExactJson([
            'vps-cloud-2' => ['habis' => true, 'bisa_dipesan' => false],
            'vps-cloud-3' => ['habis' => false, 'bisa_dipesan' => false],
        ]);
    }
}
