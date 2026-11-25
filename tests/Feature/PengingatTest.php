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
        $this->assertSame(['tertunda', 'jatuh_tempo', 'token', 'tertunda'], array_column($p, 'jenis')); // perpanjangan dulu, lalu token, pesanan baru terakhir
        $this->assertStringContainsString('mulai '.today()->subDays(2)->addDays(4)->translatedFormat('j M Y'), $p[0]['pesan']); // jatuh tempo + 3 hari tenggang + 1
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

    public function test_layanan_ditangguhkan_dan_pesanan_terbengkalai(): void
    {
        $user = User::factory()->create();
        $tangguh = $this->layanan($user, today()->subDays(6)->toDateString(), ServiceStatus::Ditangguhkan);
        $this->tagihan($tangguh, today()->subDays(6)->toDateString());
        $lama = $this->layanan($user, null, ServiceStatus::MenungguPembayaran);
        $this->tagihan($lama, today()->subDays(15)->toDateString(), today()->subDays(18)->toDateString());

        $p = app(DaftarPengingat::class)->untuk($user);
        $this->assertCount(1, $p); // pesanan lewat >14 hari tidak diingatkan lagi
        $this->assertStringContainsString('ditangguhkan', $p[0]['judul']);
    }

    public function test_email_tidak_dikirim_untuk_topup_token(): void
    {
        Notification::fake();
        config(['pengingat.email.aktif' => true]);
        $user = User::factory()->create();
        $ai = $this->layanan($user, null, ServiceStatus::Aktif, PlanKategori::Ai);
        // top-up: periode mulai hari ini, batas bayar +3, layanan AI tanpa jatuh tempo
        Invoice::create(['user_id' => $user->id, 'service_id' => $ai->id, 'nomor' => Invoice::nomorBaru(), 'jumlah' => 28500, 'token_juta' => 1,
            'status' => InvoiceStatus::BelumDibayar, 'periode_mulai' => today(), 'periode_selesai' => today(), 'jatuh_tempo' => today()->addDays(3)]);

        $this->artisan('pengingat:email')->assertSuccessful();
        Notification::assertNothingSent();
    }
}
