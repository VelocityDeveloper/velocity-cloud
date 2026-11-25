<?php

namespace Tests\Feature;

use App\Models\AgenCatatan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AgenDiskusiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cloud.agen_diskusi_token' => 'rahasia-uji']);
    }

    public function test_endpoint_menolak_tanpa_token_yang_benar(): void
    {
        $isi = ['judul' => 'x', 'isi' => 'y', 'status' => 'terbuka'];
        $this->postJson('/internal/agen/diskusi', $isi)->assertForbidden();
        $this->withToken('salah')->postJson('/internal/agen/diskusi', $isi)->assertForbidden();
        config(['cloud.agen_diskusi_token' => '']);
        $this->withToken('')->postJson('/internal/agen/diskusi', $isi)->assertForbidden();
        $this->assertSame(0, AgenCatatan::count());
    }

    public function test_masalah_dibuka_lalu_ditandai_fix(): void
    {
        $lapor = AgenCatatan::create(['jenis' => 'laporan', 'judul' => 'Laporan harian', 'isi' => '-', 'agen' => 'Dudung']);
        $id = $this->withToken('rahasia-uji')->postJson('/internal/agen/diskusi', [
            'judul' => 'Checkout 500', 'isi' => 'Tombol pesan error', 'agen' => 'Dudung', 'status' => 'terbuka',
            'url' => 'https://bot.velocity.ai.id/grup/6dd8ec9835?pc=abc', 'terkait' => $lapor->id,
        ])->assertCreated()->json('id');

        $c = AgenCatatan::find($id);
        $this->assertSame('masalah', $c->jenis);
        $this->assertSame('terbuka', $c->status);
        $this->assertStringContainsString("#{$lapor->id}", $c->isi);

        $this->withToken('rahasia-uji')->postJson('/internal/agen/diskusi', ['id' => $id, 'status' => 'fix', 'ringkasan' => 'Rute diperbaiki'])->assertOk();
        $c->refresh();
        $this->assertSame('fix', $c->status);
        $this->assertSame('Rute diperbaiki', $c->ringkasan);
        $this->assertStringContainsString('pc=abc', $c->diskusi_url);

        // catatan selain masalah tidak bisa diubah lewat endpoint ini
        $this->withToken('rahasia-uji')->postJson('/internal/agen/diskusi', ['id' => $lapor->id, 'status' => 'fix'])->assertNotFound();
    }

    public function test_tab_masalah_tampil_dengan_jumlah_terbuka(): void
    {
        AgenCatatan::create(['jenis' => 'masalah', 'status' => 'terbuka', 'judul' => 'A', 'isi' => '-', 'agen' => 'Dudung']);
        AgenCatatan::create(['jenis' => 'masalah', 'status' => 'fix', 'judul' => 'B', 'isi' => '-', 'agen' => 'Dudung']);
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get('/admin/agen?jenis=masalah')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('admin/Agen')->where('terbuka', 1)->has('catatan', 2));
    }

    public function test_review_harian_dipisah_dari_masalah_dan_bisa_ditutup_admin(): void
    {
        $id = $this->withToken('rahasia-uji')->postJson('/internal/agen/diskusi', [
            'judul' => 'Review 24 Nov 2026', 'isi' => 'Ringkasan harian', 'agen' => 'Dudung', 'status' => 'terbuka',
        ])->assertCreated()->json('id');

        $c = AgenCatatan::find($id);
        $this->assertSame('review', $c->jenis);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get('/admin/agen?jenis=review')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('terbuka', 0)->where('review_terbuka', 1)->has('catatan', 1));

        $this->actingAs($admin)->post(route('admin.agen.selesai', $c), ['ringkasan' => 'Sudah dicek'])->assertRedirect();
        $this->assertSame('fix', $c->fresh()->status);
        $this->assertSame('Sudah dicek', $c->fresh()->ringkasan);
    }
}
