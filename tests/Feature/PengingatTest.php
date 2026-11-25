<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PlanKategori;
use App\Enums\ServiceStatus;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use App\Notifications\PengingatJatuhTempo;
use App\Services\Pengingat\DaftarPengingat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PengingatTest extends TestCase
{
    use RefreshDatabase;

    private function layanan(User $user, ?string $jatuhTempo, ServiceStatus $status = ServiceStatus::Aktif, PlanKategori $kategori = PlanKategori::Vps): Service
    {
        $plan = Plan::create(['kategori' => $kategori, 'slug' => 'p-'.uniqid(), 'nama' => 'Cloud 1', 'harga' => 99000]);

        return Service::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'label' => 'Server A', 'status' => $status, 'harga' => 99000, 'jatuh_tempo' => $jatuhTempo]);
    }

    private function tagihan(Service $s, string $jatuhTempo, ?string $mulai = null): Invoice
    {
        return Invoice::create(['user_id' => $s->user_id, 'service_id' => $s->id, 'nomor' => Invoice::nomorBaru(), 'jumlah' => 99000,
            'status' => InvoiceStatus::BelumDibayar, 'periode_mulai' => $mulai ?? $jatuhTempo, 'periode_selesai' => $jatuhTempo, 'jatuh_tempo' => $jatuhTempo]);
    }

    public function test_jatuh_tempo_tertunda_dan_token_muncul_di_dashboard(): void
    {
        $user = User::factory()->create();
        $dekat = $this->layanan($user, today()->addDays(5)->toDateString());
        $this->tagihan($dekat, today()->addDays(5)->toDateString());
        $lewat = $this->layanan($user, today()->subDays(2)->toDateString());
        $this->tagihan($lewat, today()->subDays(2)->toDateString());
        $baru = $this->layanan($user, null, ServiceStatus::MenungguPembayaran);
        $this->tagihan($baru, today()->addDays(3)->toDateString(), today()->toDateString());
        $ai = $this->layanan($user, null, ServiceStatus::Aktif, PlanKategori::Ai);
        $ai->tokenAi()->create(['kunci_hash' => 'x', 'saldo' => 5_000]);
        $batal = $this->layanan($user, null, ServiceStatus::Dibatalkan);
        $this->tagihan($batal, today()->subDays(9)->toDateString());

        $p = app(DaftarPengingat::class)->untuk($user);
        $this->assertSame(['tertunda', 'tertunda', 'jatuh_tempo', 'token'], array_column($p, 'jenis')); // urut jatuh tempo, token terakhir
        $this->assertStringContainsString('5 hari lagi', collect($p)->firstWhere('jenis', 'jatuh_tempo')['judul']);
        $this->assertStringContainsString('lewat jatuh tempo', collect($p)->firstWhere('tingkat', 'bahaya')['judul']);

        $this->actingAs($user)->get(route('tagihan.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('pengingat', 4));
        $this->actingAs(User::factory()->create())->get(route('tagihan.index'))
            ->assertInertia(fn ($page) => $page->has('pengingat', 0));
    }

    public function test_perpanjangan_lebih_dari_h7_dan_token_cukup_tidak_muncul(): void
    {
        $user = User::factory()->create();
        $jauh = $this->layanan($user, today()->addDays(10)->toDateString());
        $this->tagihan($jauh, today()->addDays(10)->toDateString());
        $ai = $this->layanan($user, null, ServiceStatus::Aktif, PlanKategori::Ai);
        $ai->tokenAi()->create(['kunci_hash' => 'x', 'saldo' => 2_000_000]);

        $this->assertSame([], app(DaftarPengingat::class)->untuk($user));
    }

    public function test_email_nonaktif_tidak_mengirim_dan_aktif_hanya_h7_h3_h1_sekali(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        foreach ([7, 3, 2, 1] as $h) {
            $s = $this->layanan($user, today()->addDays($h)->toDateString());
            $this->tagihan($s, today()->addDays($h)->toDateString());
        }

        $this->artisan('pengingat:email')->assertSuccessful();
        Notification::assertNothingSent();

        config(['pengingat.email.aktif' => true]);
        $this->artisan('pengingat:email')->assertSuccessful();
        $this->artisan('pengingat:email')->assertSuccessful(); // dijalankan ulang: tidak dobel
        Notification::assertSentToTimes($user, PengingatJatuhTempo::class, 3);
    }
}
