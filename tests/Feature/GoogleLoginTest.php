<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'id-uji', 'services.google.client_secret' => 'rahasia-uji']);
    }

    private function google(string $id, string $email, bool $terverifikasi = true): void
    {
        $g = (new GoogleUser)->setRaw(['email_verified' => $terverifikasi])->map(['id' => $id, 'name' => 'Budi Google', 'email' => $email]);
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->andReturn($g);
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
    }

    public function test_tombol_google_hanya_tampil_jika_dikonfigurasi(): void
    {
        $this->get(route('login'))->assertInertia(fn (Assert $p) => $p->where('google', true));
        config(['services.google.client_id' => null]);
        $this->get(route('register'))->assertInertia(fn (Assert $p) => $p->where('google', false));
        $this->get(route('google.redirect'))->assertNotFound();
    }

    public function test_pengguna_baru_dibuat_terverifikasi_dan_masuk(): void
    {
        $this->google('g-1', 'Budi@Contoh.id');

        $this->get(route('google.callback'))->assertRedirect(route('dashboard'));

        $u = User::firstWhere('email', 'budi@contoh.id');
        $this->assertSame('g-1', $u->google_id);
        $this->assertNotNull($u->email_verified_at);
        $this->assertAuthenticatedAs($u);
    }

    public function test_akun_lama_ditautkan_lewat_email_terverifikasi(): void
    {
        $lama = User::factory()->create(['email' => 'ani@contoh.id']);
        $this->google('g-2', 'ani@contoh.id');

        $this->get(route('google.callback'))->assertRedirect(route('dashboard'));

        $this->assertSame('g-2', $lama->fresh()->google_id);
        $this->assertAuthenticatedAs($lama);
        $this->assertSame(1, User::count());
    }

    public function test_email_google_belum_terverifikasi_ditolak(): void
    {
        User::factory()->create(['email' => 'ani@contoh.id']);
        $this->google('g-3', 'ani@contoh.id', terverifikasi: false);

        $this->get(route('google.callback'))->assertRedirect(route('login'))->assertSessionHas('galat_google');
        $this->assertGuest();
    }

    public function test_email_yang_tertaut_google_lain_ditolak(): void
    {
        User::factory()->create(['email' => 'ani@contoh.id', 'google_id' => 'g-asli']);
        $this->google('g-lain', 'ani@contoh.id');

        $this->get(route('google.callback'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_akun_2fa_tetap_lewat_tantangan_kode(): void
    {
        $u = User::factory()->create(['email' => 'dua@contoh.id']);
        $u->forceFill(['two_factor_secret' => encrypt('x'), 'two_factor_confirmed_at' => now()])->save();
        $this->google('g-4', 'dua@contoh.id');

        $this->get(route('google.callback'))->assertRedirect(route('two-factor.login'))->assertSessionHas('login.id', $u->id);
        $this->assertGuest();
    }
}
