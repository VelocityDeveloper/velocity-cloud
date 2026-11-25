<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PlanKategori;
use App\Enums\ServiceStatus;
use App\Jobs\ProvisionVps;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CrmPesanTest extends TestCase
{
    use RefreshDatabase;

    private function isian(array $tambah = []): array
    {
        return ['email' => 'Baru@Contoh.id', 'nama' => 'Pak Baru', 'ref' => 'abcdef123456', 'petugas' => 'cs1', 'catatan_bayar' => 'Transfer BCA 25/11', ...$tambah];
    }

    public function test_crm_membuat_vps_lunas_untuk_akun_baru_sekali_saja(): void
    {
        Queue::fake();
        config(['cloud.crm.masuk_key' => 'rahasia', 'cloud.vps.paket' => ['vps-uji']]);
        $plan = Plan::create(['kategori' => PlanKategori::Vps, 'slug' => 'vps-uji', 'nama' => 'Cloud 1', 'harga' => 114000, 'aktif' => true]);
        $os = array_key_first(\App\Models\Pengaturan::osVps());

        $this->withToken('rahasia')->getJson('/api/crm/katalog')->assertOk()->assertJsonPath('paket.0.butuh_os', true);
        // OS wajib untuk VPS otomatis; akun tidak tertinggal bila isian salah
        $this->withToken('rahasia')->postJson('/api/crm/pesan', $this->isian(['plan_id' => $plan->id]))->assertStatus(422)->assertJsonValidationErrors('os');
        $this->assertSame(0, User::count());

        $r = $this->withToken('rahasia')->postJson('/api/crm/pesan', $this->isian(['plan_id' => $plan->id, 'os' => $os]))
            ->assertCreated()->assertJsonPath('akun_baru', true)->assertJsonPath('jumlah', 114000);
        // klik dua kali dengan ref sama = pesanan yang sama
        $this->withToken('rahasia')->postJson('/api/crm/pesan', $this->isian(['plan_id' => $plan->id, 'os' => $os]))
            ->assertOk()->assertJsonPath('tagihan', $r->json('tagihan'))->assertJsonPath('akun_baru', false);

        $inv = Invoice::sole();
        $this->assertSame(InvoiceStatus::Lunas, $inv->status);
        $this->assertSame('crm', $inv->metode);
        $this->assertSame('CRM abcdef123456 cs1: Transfer BCA 25/11', $inv->referensi);
        $this->assertSame('baru@contoh.id', $inv->user->email);
        $this->assertSame(ServiceStatus::Disiapkan, $inv->service->status);
        Queue::assertPushed(ProvisionVps::class, 1);

        $this->withToken('salah')->postJson('/api/crm/pesan', $this->isian(['plan_id' => $plan->id, 'os' => $os, 'ref' => 'zzzzzz123456']))->assertNotFound();
    }

    public function test_paket_domain_dan_habis_ditolak(): void
    {
        config(['cloud.crm.masuk_key' => 'rahasia']);
        $domain = Plan::create(['kategori' => PlanKategori::Domain, 'slug' => 'domain', 'nama' => 'Domain', 'harga' => 0, 'aktif' => true]);
        $habis = Plan::create(['kategori' => PlanKategori::Vps, 'slug' => 'vps-habis', 'nama' => 'Cloud 9', 'harga' => 1000, 'aktif' => true, 'stok' => 0]);

        foreach ([$domain, $habis] as $p) {
            $this->withToken('rahasia')->postJson('/api/crm/pesan', $this->isian(['plan_id' => $p->id]))->assertStatus(422);
        }
        $this->assertSame(0, Invoice::count());
    }
}
