<?php

namespace Tests\Feature;

use App\Models\Tiket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TiketTest extends TestCase
{
    use RefreshDatabase;

    public function test_alur_tiket_pelanggan_dan_admin(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)->post(route('tiket.store'), ['jenis' => 'support', 'judul' => 'App tidak bisa dibuka', 'isi' => 'Error 502'])->assertRedirect();
        $tiket = Tiket::sole();
        $this->assertSame('terbuka', $tiket->status);
        $this->assertSame('support', $tiket->jenis);
        $this->actingAs($user)->post(route('tiket.store'), ['jenis' => 'lain', 'judul' => 'x', 'isi' => 'y'])->assertSessionHasErrors('jenis');

        $this->actingAs($admin)->get(route('admin.tiket.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('tiketTerbuka', 1)); // badge sidebar
        $this->actingAs($user)->get(route('tiket.index'))->assertInertia(fn ($page) => $page->where('tiketTerbuka', 0));
        $this->actingAs($admin)->post(route('tiket.balas', $tiket), ['isi' => 'Sudah kami perbaiki'])->assertRedirect();
        $this->assertSame('dijawab', $tiket->fresh()->status);
        $this->assertTrue($tiket->pesan()->get()->last()->dari_admin);

        $this->actingAs($user)->post(route('tiket.tutup', $tiket))->assertRedirect();
        $this->assertSame('ditutup', $tiket->fresh()->status);
        $this->actingAs($user)->post(route('tiket.balas', $tiket), ['isi' => 'Error lagi'])->assertRedirect();
        $this->assertSame('terbuka', $tiket->fresh()->status); // balasan pelanggan membuka lagi
        $this->assertFalse($tiket->pesan()->get()->last()->dari_admin);
    }

    public function test_pelanggan_lain_tidak_bisa_melihat_atau_membalas(): void
    {
        $tiket = User::factory()->create()->tiket()->create(['judul' => 'x']);
        $lain = User::factory()->create();

        $this->actingAs($lain)->get(route('tiket.show', $tiket))->assertNotFound();
        $this->actingAs($lain)->post(route('tiket.balas', $tiket), ['isi' => 'y'])->assertNotFound();
        $this->actingAs($lain)->get(route('admin.tiket.index'))->assertForbidden();
    }

    public function test_layanan_milik_orang_lain_ditolak(): void
    {
        $lain = User::factory()->create();
        $plan = \App\Models\Plan::create(['slug' => 'p', 'kategori' => 'vps', 'nama' => 'P', 'harga' => 1, 'aktif' => true]);
        $service = \App\Models\Service::create(['user_id' => $lain->id, 'plan_id' => $plan->id, 'label' => 's', 'status' => 'aktif', 'harga' => 1]);

        $this->actingAs(User::factory()->create())->post(route('tiket.store'), ['jenis' => 'billing', 'judul' => 'x', 'isi' => 'y', 'service_id' => $service->id])
            ->assertSessionHasErrors('service_id');
    }

    public function test_lampiran_privat_dan_jenis_berbahaya_ditolak(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('tiket.store'), [
            'jenis' => 'billing', 'judul' => 'Konfirmasi', 'isi' => 'Bukti terlampir',
            'lampiran' => [UploadedFile::fake()->image('bukti.png'), UploadedFile::fake()->create('nota.pdf', 100, 'application/pdf')],
        ])->assertSessionHasNoErrors();
        $tiket = Tiket::sole();
        $lampiran = $tiket->pesan()->first()->lampiran;
        $this->assertCount(2, $lampiran);
        Storage::disk('local')->assertExists($lampiran[0]->path);

        $this->actingAs($user)->get(route('tiket.lampiran', [$tiket, $lampiran[0]]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs(User::factory()->create())->get(route('tiket.lampiran', [$tiket, $lampiran[0]]))->assertNotFound();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('tiket.lampiran', [$tiket, $lampiran[0]]))->assertOk();

        // lampiran tiket lain lewat URL tiket sendiri ditolak
        $lain = $user->tiket()->create(['judul' => 'lain']);
        $this->actingAs($user)->get(route('tiket.lampiran', [$lain, $lampiran[0]]))->assertNotFound();

        $this->actingAs($user)->post(route('tiket.balas', $tiket), ['isi' => 'x', 'lampiran' => [UploadedFile::fake()->createWithContent('a.svg', '<svg onload="alert(1)"/>')]])
            ->assertSessionHasErrors('lampiran.0');
        $this->actingAs($user)->post(route('tiket.balas', $tiket), ['isi' => 'x', 'lampiran' => array_fill(0, 4, UploadedFile::fake()->image('a.png'))])
            ->assertSessionHasErrors('lampiran');
    }
}
