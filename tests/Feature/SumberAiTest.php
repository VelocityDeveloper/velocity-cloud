<?php

namespace Tests\Feature;

use App\Enums\PlanKategori;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SumberAiTest extends TestCase
{
    use RefreshDatabase;

    private function plan(): Plan
    {
        return Plan::create(['kategori' => PlanKategori::Ai, 'slug' => 'ai-uji', 'nama' => 'Token Uji', 'harga' => 5000, 'aktif' => true, 'spek' => ['model' => 'publik-1']]);
    }

    public function test_admin_simpan_sumber_key_terenkripsi_dan_tak_dikirim_ke_browser(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $plan = $this->plan();

        $this->actingAs($admin)->put("/admin/paket/{$plan->id}/sumber", ['url' => 'https://api.contoh.com/v1/', 'model' => 'm-up'])
            ->assertSessionHasErrors('key'); // key wajib saat belum ada
        $this->actingAs($admin)->put("/admin/paket/{$plan->id}/sumber", ['url' => 'https://api.contoh.com/v1/', 'model' => 'm-up', 'key' => 'sk-rahasia'])
            ->assertSessionHasNoErrors();

        $plan->refresh();
        $this->assertSame(['url' => 'https://api.contoh.com/v1', 'model' => 'm-up', 'key' => 'sk-rahasia'], $plan->sumber);
        $this->assertStringNotContainsString('sk-rahasia', $plan->getRawOriginal('sumber'));

        // key kosong = tetap memakai key lama
        $this->actingAs($admin)->put("/admin/paket/{$plan->id}/sumber", ['url' => 'https://api.contoh.com/v1', 'model' => 'm-2', 'key' => ''])->assertSessionHasNoErrors();
        $this->assertSame('sk-rahasia', $plan->refresh()->sumber['key']);

        $res = $this->actingAs($admin)->get('/admin/paket/ai');
        $res->assertInertia(fn (Assert $p) => $p->where('paket.0.sumber.model', 'm-2')->where('paket.0.sumber.ada_key', true)->missing('paket.0.sumber.key'));
        $this->assertStringNotContainsString('sk-rahasia', $res->getContent());

        // URL kosong = kembali ke sumber bawaan proxy
        $this->actingAs($admin)->put("/admin/paket/{$plan->id}/sumber", ['url' => ''])->assertSessionHasNoErrors();
        $this->assertNull($plan->refresh()->sumber);
    }

    public function test_uji_sumber_dan_hanya_admin(): void
    {
        Http::fake(['api.contoh.com/*' => Http::response(['choices' => []], 200)]);
        $plan = $this->plan();
        $plan->update(['sumber' => ['url' => 'https://api.contoh.com/v1', 'model' => 'm-up', 'key' => 'sk-x']]);

        $this->actingAs(User::factory()->create())->put("/admin/paket/{$plan->id}/sumber", ['url' => ''])->assertForbidden();
        $this->actingAs(User::factory()->create(['is_admin' => true]))->post("/admin/paket/{$plan->id}/uji-sumber")->assertRedirect();
        Http::assertSent(fn ($r) => $r->url() === 'https://api.contoh.com/v1/chat/completions' && $r->hasHeader('Authorization', 'Bearer sk-x') && $r['model'] === 'm-up');
    }

    public function test_admin_tambah_paket_ai_baru_off_dengan_sumber(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->plan(); // model publik-1 sudah ada
        $data = ['nama' => 'DeepSeek V4.1 Pro', 'model' => 'deepseek-v4.1-pro', 'harga' => 12000, 'sumber_url' => 'https://api.contoh.com/v1/', 'sumber_model' => 'deepseek-reasoner', 'sumber_key' => 'sk-baru'];

        $this->actingAs(User::factory()->create())->post('/admin/paket/ai', $data)->assertForbidden();
        $this->actingAs($admin)->post('/admin/paket/ai', ['model' => 'Ada Spasi'] + $data)->assertSessionHasErrors('model');
        $this->actingAs($admin)->post('/admin/paket/ai', ['model' => 'publik-1'] + $data)->assertSessionHasErrors('model');
        $this->actingAs($admin)->post('/admin/paket/ai', $data)->assertSessionHasNoErrors()->assertRedirect();

        $plan = Plan::where('slug', 'ai-deepseek-v4-1-pro')->firstOrFail();
        $this->assertSame(PlanKategori::Ai, $plan->kategori);
        $this->assertFalse($plan->aktif);
        $this->assertSame(12000, $plan->harga);
        $this->assertSame('deepseek-v4.1-pro', $plan->spek['model']);
        $this->assertSame(['url' => 'https://api.contoh.com/v1', 'model' => 'deepseek-reasoner', 'key' => 'sk-baru'], $plan->sumber);
        $this->actingAs($admin)->post('/admin/paket/ai', $data)->assertSessionHasErrors('model'); // kembar
    }
}
