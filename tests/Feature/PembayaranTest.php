<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\MetodeBayar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PembayaranTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_kelola_metode_qris_dan_bank(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.pembayaran.store'), ['jenis' => 'qris', 'nama' => 'QRIS', 'aktif' => true])
            ->assertSessionHasErrors('gambar');
        $this->actingAs($admin)->post(route('admin.pembayaran.store'), ['jenis' => 'qris', 'nama' => 'QRIS', 'aktif' => true, 'gambar' => UploadedFile::fake()->image('qris.png')])
            ->assertSessionHasNoErrors();
        $qris = MetodeBayar::sole();
        Storage::disk('public')->assertExists($qris->gambar);

        // ganti gambar → berkas lama dihapus; ubah tanpa gambar → gambar tetap
        $lama = $qris->gambar;
        $this->actingAs($admin)->post(route('admin.pembayaran.update', $qris), ['jenis' => 'qris', 'nama' => 'QRIS', 'aktif' => true, 'gambar' => UploadedFile::fake()->image('baru.png')])->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($lama);
        $this->actingAs($admin)->post(route('admin.pembayaran.update', $qris), ['jenis' => 'qris', 'nama' => 'QRIS Toko', 'aktif' => true])->assertSessionHasNoErrors();
        $this->assertNotNull($qris->fresh()->gambar);

        $this->actingAs($admin)->post(route('admin.pembayaran.store'), ['jenis' => 'bank', 'nama' => 'BCA', 'nomor' => '123', 'atas_nama' => 'PT X', 'aktif' => false])->assertSessionHasNoErrors();
        $this->assertSame(1, MetodeBayar::aktif()->count());

        $gambar = $qris->fresh()->gambar;
        $this->actingAs($admin)->delete(route('admin.pembayaran.destroy', $qris))->assertRedirect();
        Storage::disk('public')->assertMissing($gambar);
    }

    public function test_bukan_admin_ditolak(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.pembayaran.index'))->assertForbidden();
        $this->actingAs(User::factory()->create())->post(route('admin.pembayaran.store'), ['jenis' => 'bank', 'nama' => 'x'])->assertForbidden();
    }
}
