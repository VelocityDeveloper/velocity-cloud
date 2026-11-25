<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_keluar_tercatat_dan_tampil_di_admin(): void
    {
        $user = User::factory()->create(['name' => 'Irvan', 'email' => 'Irvan@Contoh.id']);
        $user->notify(new ResetPassword('token-rahasia'));
        Mail::raw('halo', fn ($m) => $m->to('tamu@luar.id')->subject('Uji manual'));

        $this->assertSame(
            [[$user->id, 'Reset sandi', 'terkirim'], [null, 'Manual', 'terkirim']],
            EmailLog::orderBy('id')->get()->map(fn ($e) => [$e->user_id, $e->jenisLabel(), $e->status])->all(),
        );
        $this->assertStringNotContainsString('token-rahasia', EmailLog::first()->toJson()); // isi tidak disimpan

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.email.index', ['cari' => 'Uji']))->assertOk()
            ->assertInertia(fn ($p) => $p->component('admin/Email')->has('email', 1)->where('email.0.ke', 'tamu@luar.id'));
        $this->actingAs($user)->get(route('admin.email.index'))->assertForbidden();
    }
}
