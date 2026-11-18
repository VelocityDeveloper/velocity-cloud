<?php

namespace Tests\Feature;

use App\Enums\PlanKategori;
use App\Models\Plan;
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
}
