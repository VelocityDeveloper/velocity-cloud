<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TandaMasukTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_memasang_cookie_penanda_tamu_menghapusnya(): void
    {
        $this->actingAs(User::factory()->create())->get('/dashboard')
            ->assertCookie('vc_masuk', '1', false);

        auth()->logout();
        $this->withUnencryptedCookie('vc_masuk', '1')->get('/login')
            ->assertCookieExpired('vc_masuk');
    }
}
