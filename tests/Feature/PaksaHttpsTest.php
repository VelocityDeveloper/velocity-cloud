<?php

namespace Tests\Feature;

use Tests\TestCase;

class PaksaHttpsTest extends TestCase
{
    public function test_http_dari_cloudflare_dialihkan_ke_https(): void
    {
        $this->get('/login', ['X-Forwarded-Proto' => 'http'])
            ->assertStatus(301)->assertRedirect(url('/login'));
        $this->get('/login', ['X-Forwarded-Proto' => 'https'])->assertOk();
        $this->get('/login')->assertOk();
    }
}
