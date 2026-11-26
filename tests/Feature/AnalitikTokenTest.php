<?php

namespace Tests\Feature;

use App\Enums\PlanKategori;
use App\Enums\ServiceStatus;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AnalitikTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_laporan_token_di_admin_paket_ai(): void
    {
        $user = User::factory()->create(['name' => 'Budi']);
        $plan = Plan::create(['kategori' => PlanKategori::Ai, 'slug' => 'ai-x', 'nama' => 'AI X', 'harga' => 5000, 'spek' => ['model' => 'm1']]);
        $service = Service::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'label' => 'chatbot', 'status' => ServiceStatus::Aktif, 'harga' => 5000]);
        $t = $service->tokenAi()->create(['kunci_hash' => str_repeat('a', 64), 'saldo' => 900, 'terpakai' => 100]);
        $w = now()->toDateTimeString();
        DB::table('token_ai_pemakaian')->insert([
            ['token_ai_id' => $t->id, 'model' => 'm1', 'prompt' => 80, 'cache_hit' => 40, 'completion' => 20, 'total' => 100, 'potong' => 200000, 'durasi_ms' => 1000, 'taksiran' => 0, 'status' => 200, 'created_at' => $w],
            ['token_ai_id' => $t->id, 'model' => 'm1', 'prompt' => 0, 'cache_hit' => 0, 'completion' => 0, 'total' => 0, 'potong' => null, 'durasi_ms' => 5, 'taksiran' => 0, 'status' => 402, 'created_at' => $w],
            // periode lalu (40 hari lalu) tidak ikut ringkasan 30 hari
            ['token_ai_id' => $t->id, 'model' => 'm1', 'prompt' => 10, 'cache_hit' => 0, 'completion' => 10, 'total' => 20, 'potong' => null, 'durasi_ms' => 1, 'taksiran' => 1, 'status' => 200, 'created_at' => now()->subDays(40)->toDateTimeString()],
        ]);

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.paket.index', 'ai'))->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('analitik.ringkasan.request', 2)
                ->where('analitik.ringkasan.token', 100)
                ->where('analitik.ringkasan.cache_rate', 0.5)
                ->where('analitik.ringkasan.gagal', 1)
                ->where('analitik.ringkasan_lalu.token', 20)
                ->where('analitik.pelanggan.0.nama', 'Budi · chatbot')
                ->where('analitik.pelanggan.0.kuota_terpakai', 0.1)
                // nilai = potong × harga output paket / 1 jt: 200.000 × 5.000 / 1 jt
                ->where('analitik.ringkasan.nilai', 1000)
                ->where('analitik.pelanggan.0.nilai', 1000)
                ->where('analitik.pelanggan.0.user_id', $user->id)
                ->where('analitik.model.0.nilai', 1000)
                ->where('analitik.gagal.0.nama', '402 · Saldo habis')
                ->has('analitik.tren', 30));

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.paket.index', 'vps'))
            ->assertInertia(fn ($p) => $p->where('analitik', null));
        // pelanggan tidak melihat baris gagal di riwayat pemakaian
        $this->actingAs($user)->get(route('layanan.show', $service))->assertInertia(fn ($p) => $p->has('token.pemakaian', 2));
    }
}
