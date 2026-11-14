<?php

namespace Tests\Feature;

use App\Actions\Billing\TandaiLunas;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\SaldoMutasi;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KodeUnikTest extends TestCase
{
    use RefreshDatabase;

    private function tagihan(User $user, int $jumlah = 50000): Invoice
    {
        $plan = Plan::create(['slug' => 'uji-'.uniqid(), 'kategori' => 'vps', 'nama' => 'Uji', 'harga' => $jumlah, 'aktif' => true]);
        $service = Service::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'label' => 'x', 'status' => ServiceStatus::MenungguPembayaran, 'harga' => $jumlah]);

        return Invoice::create(['user_id' => $user->id, 'service_id' => $service->id, 'nomor' => Invoice::nomorBaru(), 'jumlah' => $jumlah, 'status' => InvoiceStatus::BelumDibayar,
            'periode_mulai' => now(), 'periode_selesai' => now()->addMonth(), 'jatuh_tempo' => now()]);
    }

    public function test_kode_unik_1_sampai_999_dan_total_tidak_kembar(): void
    {
        $user = User::factory()->create();
        $total = collect(range(1, 30))->map(fn () => $this->tagihan($user)->total());

        $this->assertSame(30, $total->unique()->count());
        $this->assertTrue($total->every(fn ($t) => $t > 50000 && $t <= 50999));
    }

    public function test_lunas_kode_unik_masuk_saldo_sekali(): void
    {
        $user = User::factory()->create();
        $inv = $this->tagihan($user);

        app(TandaiLunas::class)->handle($inv, 'qris');
        app(TandaiLunas::class)->handle($inv->fresh(), 'qris'); // dobel klik tidak menambah lagi

        $this->assertSame($inv->kode_unik, $user->fresh()->saldo);
        $this->assertSame(1, SaldoMutasi::where('invoice_id', $inv->id)->count());
    }

    public function test_saldo_tidak_bisa_diubah_lewat_profil(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->patch(route('profile.update'), ['name' => 'X', 'email' => $user->email, 'saldo' => 999999]);
        $this->assertSame(0, $user->fresh()->saldo);
    }
}
