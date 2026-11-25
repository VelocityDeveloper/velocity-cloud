<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PenggunaDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_melihat_detail_pengguna_dan_non_admin_ditolak(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['name' => 'Irvan']);
        $user->tiket()->create(['judul' => 'Tanya token']);

        $this->actingAs($admin)->get(route('admin.pengguna.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.pengguna.show', $user))->assertOk()
            ->assertInertia(fn ($p) => $p->component('admin/PenggunaShow')
                ->where('pengguna.name', 'Irvan')->where('pengguna.total_lunas', 0)
                ->has('layanan', 0)->has('tagihan', 0)->where('tiket.0.judul', 'Tanya token'));
        $this->actingAs($user)->get(route('admin.pengguna.show', $user))->assertForbidden();
    }
}
