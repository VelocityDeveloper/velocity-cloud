<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_pelanggan_dialihkan_ke_dashboard_customer()
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard').'?verified=1')->assertRedirect(route('customer.dashboard').'?verified=1');
        $this->get(route('customer.dashboard'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('customer/Dashboard'));
    }

    public function test_admin_melihat_dashboard_admin()
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $this->get(route('dashboard'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/Dashboard')->has('ringkasan.layanan_per_status', 6));
        $this->get(route('customer.dashboard'))->assertOk();
    }
}
