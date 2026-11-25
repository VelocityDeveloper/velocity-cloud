<?php

namespace Tests\Feature;

use App\Enums\PlanKategori;
use App\Enums\ServiceStatus;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmLayananTest extends TestCase
{
    use RefreshDatabase;

    public function test_crm_membaca_layanan_per_email_tanpa_kredensial(): void
    {
        config(['cloud.crm.masuk_key' => 'rahasia']);
        $u = User::factory()->create(['email' => 'Budi@Contoh.id']);
        $vps = Plan::create(['kategori' => PlanKategori::Vps, 'slug' => 'vps-uji', 'nama' => 'Cloud 1', 'harga' => 100000]);
        Service::create(['user_id' => $u->id, 'plan_id' => $vps->id, 'label' => 'Cloud 1', 'harga' => 100000, 'status' => ServiceStatus::Aktif,
            'detail' => ['host' => '43.159.35.76', 'host_port' => 22002, 'kredensial' => 'RAHASIA-VPS']]);
        Service::create(['user_id' => $u->id, 'plan_id' => $vps->id, 'label' => 'Cloud 1', 'harga' => 100000, 'status' => ServiceStatus::Dibatalkan]);
        User::factory()->create(['email' => 'lain@contoh.id']);

        $r = $this->withToken('rahasia')->getJson('/api/crm/layanan?email[]=budi@contoh.id&email[]=kosong@contoh.id')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonCount(1, 'data.0.layanan')
            ->assertJsonPath('data.0.layanan.0.alamat', '43.159.35.76:22002')
            ->assertJsonPath('data.0.layanan.0.status', 'Aktif');
        $this->assertStringNotContainsString('RAHASIA-VPS', $r->getContent());

        $this->withToken('salah')->getJson('/api/crm/layanan?email[]=budi@contoh.id')->assertNotFound();
        // lewat Cloudflare (publik) ditolak walau kunci benar
        $this->withToken('rahasia')->withHeader('CF-Connecting-IP', '1.2.3.4')->getJson('/api/crm/layanan?email[]=budi@contoh.id')->assertNotFound();
        config(['cloud.crm.masuk_key' => '']);
        $this->withToken('')->getJson('/api/crm/layanan?email[]=budi@contoh.id')->assertNotFound();
    }
}
