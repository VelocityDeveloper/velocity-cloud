<?php

namespace Tests\Feature;

use App\Enums\PlanKategori;
use App\Models\ChatPercakapan;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_hanya_menampilkan_token_ai_aktif_milik_sendiri(): void
    {
        $plan = Plan::create(['kategori' => PlanKategori::Ai, 'slug' => 'ai-uji', 'nama' => 'Token Uji', 'harga' => 5000, 'aktif' => true, 'spek' => ['model' => 'deepseek-v4.1-flash']]);
        $saya = User::factory()->create();
        $lain = User::factory()->create();
        $buat = fn (User $u, string $status, string $kunci) => Service::create([
            'user_id' => $u->id, 'plan_id' => $plan->id, 'label' => "L-$kunci", 'status' => $status, 'harga' => 0,
            'detail' => ['kredensial' => Crypt::encryptString(json_encode(['api_key' => $kunci]))],
        ]);
        $buat($saya, 'aktif', 'vc-saya');
        $buat($saya, 'ditangguhkan', 'vc-mati');
        $buat($lain, 'aktif', 'vc-orang');

        $this->actingAs($saya)->get('/chat')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('chat/Index')
            ->has('kunci', 1)
            ->missing('kunci.0.api_key') // kunci API tak lagi dikirim ke browser: model dipanggil dari server
            ->where('kunci.0.model', 'deepseek-v4.1-flash')
            ->where('kunci.0.nama', 'Token Uji')
            ->where('aktif', null));
    }

    public function test_riwayat_dibuka_dan_dihapus_hanya_oleh_pemilik(): void
    {
        $saya = User::factory()->create();
        $lain = User::factory()->create();
        $uuid = (string) Str::uuid();
        ChatPercakapan::create(['uuid' => $uuid, 'user_id' => $saya->id, 'judul' => 'Halo',
            'pesan' => [['role' => 'user', 'content' => 'Halo'], ['role' => 'assistant', 'content' => 'Hai']]]);

        $this->actingAs($saya)->get("/chat/$uuid")->assertInertia(fn (Assert $p) => $p
            ->where('aktif.uuid', $uuid)->has('aktif.pesan', 2)->where('aktif.status', null)->has('daftar', 1));

        $this->actingAs($lain)->get("/chat/$uuid")->assertNotFound();
        $this->actingAs($lain)->getJson("/chat/$uuid/status")->assertNotFound();
        $this->actingAs($lain)->postJson("/chat/$uuid/kirim", ['service_id' => 1, 'teks' => 'x'])->assertNotFound();
        $this->actingAs($lain)->delete("/chat/$uuid")->assertNotFound();
        $this->actingAs($lain)->get('/chat')->assertInertia(fn (Assert $p) => $p->has('daftar', 0));

        $this->actingAs($saya)->delete("/chat/$uuid")->assertRedirect('/chat');
        $this->assertDatabaseCount('chat_percakapan', 0);
    }

    public function test_chat_wajib_login(): void
    {
        $this->get('/chat')->assertRedirect('/login');
    }
}
