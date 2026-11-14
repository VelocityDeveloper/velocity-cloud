<?php

namespace Tests\Feature;

use App\Enums\PlanKategori;
use App\Models\ChatPercakapan;
use App\Models\Node;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Giliran Chat AI di server (job JalankanChat, queue sync di tes): aman pindah halaman. */
class ChatJalanTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Service $ai;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $plan = Plan::create(['kategori' => PlanKategori::Ai, 'slug' => 'ai-uji', 'nama' => 'Token Uji', 'harga' => 5000, 'aktif' => true, 'spek' => ['model' => 'model-uji']]);
        $this->ai = Service::create(['user_id' => $this->user->id, 'plan_id' => $plan->id, 'label' => 'AI', 'status' => 'aktif', 'harga' => 0,
            'detail' => ['kredensial' => Crypt::encryptString(json_encode(['api_key' => 'vc-kunci']))]]);
    }

    /** Badan SSE ala OpenAI dari daftar delta. */
    private static function sse(array ...$delta): string
    {
        return implode("\n", array_map(fn ($d) => 'data: '.json_encode(['choices' => [['delta' => $d]]]), $delta))."\ndata: [DONE]\n";
    }

    private static function panggil(string $nama, array $arg, string $id = 'c1'): array
    {
        return ['tool_calls' => [['index' => 0, 'id' => $id, 'function' => ['name' => $nama, 'arguments' => json_encode($arg)]]]];
    }

    private function model(string ...$badan): void
    {
        $seq = Http::sequence();
        foreach ($badan as $b) {
            $seq->push($b, 200, ['content-type' => 'text/event-stream']);
        }
        Http::fake(['*/chat/completions' => $seq, 'agent.test/*' => Http::response(['ok' => true, 'out' => 'akismet,inactive', 'err' => ''])]);
    }

    private function kirim(string $uuid, string $teks)
    {
        return $this->actingAs($this->user)->postJson("/chat/$uuid/kirim", ['service_id' => $this->ai->id, 'teks' => $teks]);
    }

    public function test_jawaban_teks_disimpan_server_dan_kunci_dari_server(): void
    {
        $this->model(self::sse(['content' => 'Ha'], ['content' => 'lo!']));
        $uuid = (string) Str::uuid();

        $this->kirim($uuid, 'Hai')->assertOk()->assertJsonPath('status', null)->assertJsonPath('akhir.content', 'Halo!')
            ->assertJsonPath('percakapan.judul', 'Hai')->assertJsonStructure(['kunci']);

        $p = ChatPercakapan::firstWhere('uuid', $uuid);
        $this->assertSame([['role' => 'user', 'content' => 'Hai'], ['role' => 'assistant', 'content' => 'Halo!']], $p->pesan);
        $this->assertNull($p->proses);
        Http::assertSent(fn (Request $r) => $r->url() === config('cloud.chat_ai.endpoint').'/chat/completions'
            && $r->hasHeader('Authorization', 'Bearer vc-kunci') && $r['model'] === 'model-uji'
            && $r['messages'][0]['role'] === 'system' && end($r->data()['messages'])['content'] === 'Hai');
    }

    public function test_alat_tanpa_persetujuan_dijalankan_lalu_hasilnya_ke_model(): void
    {
        $this->model(self::sse(['content' => 'Saya cek dulu.'], self::panggil('daftar_layanan', [])), self::sse(['content' => 'Ada 1 layanan.']));
        $uuid = (string) Str::uuid();

        $this->kirim($uuid, 'Layanan saya apa?')->assertOk()
            ->assertJsonPath('akhir.content', "Saya cek dulu.\n\nAda 1 layanan.")
            ->assertJsonPath('akhir.alat.0', ['nama' => 'daftar_layanan', 'ringkas' => 'Lihat daftar layanan', 'status' => 'ok']);

        $kedua = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/chat/completions'))[1][0]->data()['messages'];
        $this->assertSame('daftar_layanan', $kedua[2]['tool_calls'][0]['function']['name']);
        $this->assertSame(['role' => 'tool', 'tool_call_id' => 'c1'], array_intersect_key($kedua[3], ['role' => 1, 'tool_call_id' => 1]));
        $this->assertStringContainsString('"label":"AI"', $kedua[3]['content']);
    }

    public function test_alat_berisiko_menunggu_persetujuan_lalu_ditolak(): void
    {
        $wp = $this->wordpress();
        $this->model(self::sse(self::panggil('wp', ['id' => $wp->id, 'args' => ['plugin', 'list']])), self::sse(['content' => 'Baik, tidak jadi.']));
        $uuid = (string) Str::uuid();

        $this->kirim($uuid, 'Daftar plugin')->assertOk()->assertJsonPath('status', 'menunggu')
            ->assertJsonPath('menunggu', "wp plugin list  (WordPress #{$wp->id})")->assertJsonMissingPath('kunci');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'agent.test'));

        $this->actingAs($this->user)->postJson("/chat/$uuid/izin", ['jalankan' => false])->assertOk()
            ->assertJsonPath('status', null)->assertJsonPath('akhir.content', 'Baik, tidak jadi.')
            ->assertJsonPath('akhir.alat.0.status', 'ditolak');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'agent.test'));
        $this->actingAs($this->user)->postJson("/chat/$uuid/izin", ['jalankan' => true])->assertStatus(409);
    }

    public function test_jalankan_untuk_sesi_ini_tidak_bertanya_lagi(): void
    {
        $wp = $this->wordpress();
        $alat = fn ($id) => self::sse(self::panggil('wp', ['id' => $wp->id, 'args' => ['plugin', 'list']], $id));
        $this->model($alat('c1'), $alat('c2'), self::sse(['content' => 'Selesai.']));
        $uuid = (string) Str::uuid();

        $this->kirim($uuid, 'Cek plugin dua kali')->assertJsonPath('status', 'menunggu');
        $this->actingAs($this->user)->postJson("/chat/$uuid/izin", ['jalankan' => true, 'sesi' => true])->assertOk()
            ->assertJsonPath('status', null)->assertJsonPath('otomatis', true)->assertJsonPath('akhir.content', 'Selesai.')
            ->assertJsonCount(2, 'akhir.alat');
        Http::assertSentCount(5); // 3 model + 2 WP-CLI

        $this->actingAs($this->user)->postJson("/chat/$uuid/otomatis", ['nyala' => false])->assertJsonPath('otomatis', false);
    }

    public function test_berhenti_saat_menunggu_dan_kirim_ditolak_saat_masih_jalan(): void
    {
        $wp = $this->wordpress();
        $this->model(self::sse(['content' => 'Mulai.'], self::panggil('wp', ['id' => $wp->id, 'args' => ['core', 'update']])));
        $uuid = (string) Str::uuid();
        $this->kirim($uuid, 'Update WP')->assertJsonPath('status', 'menunggu');

        $this->actingAs($this->user)->postJson("/chat/$uuid/berhenti")->assertOk()
            ->assertJsonPath('status', null)->assertJsonPath('akhir.content', "Mulai.\n\n(Dihentikan.)");

        ChatPercakapan::where('uuid', $uuid)->update(['status' => 'jalan']);
        $this->kirim($uuid, 'Lagi')->assertStatus(409);
        $this->actingAs($this->user)->postJson("/chat/$uuid/berhenti")->assertJsonPath('status', 'stop');
    }

    public function test_galat_proxy_tampil_dan_bisa_diulang(): void
    {
        Http::fake(['*/chat/completions' => Http::sequence()
            ->push(['error' => ['message' => 'Saldo token habis.']], 402)
            ->push(self::sse(['content' => 'Oke.']), 200)]);
        $uuid = (string) Str::uuid();

        $this->kirim($uuid, 'Hai')->assertJsonPath('akhir', ['role' => 'assistant', 'content' => 'Saldo token habis.', 'galat' => true]);
        $this->actingAs($this->user)->postJson("/chat/$uuid/kirim", ['service_id' => $this->ai->id, 'ulang' => true])
            ->assertJsonPath('jumlah', 2)->assertJsonPath('akhir.content', 'Oke.');
    }

    public function test_model_milik_orang_lain_ditolak(): void
    {
        $lain = User::factory()->create();
        $this->actingAs($lain)->postJson('/chat/'.Str::uuid().'/kirim', ['service_id' => $this->ai->id, 'teks' => 'Hai'])->assertStatus(422);
        $this->assertDatabaseCount('chat_percakapan', 0);
    }

    private function wordpress(): Service
    {
        $plan = Plan::create(['kategori' => PlanKategori::App, 'slug' => 'app-wordpress', 'nama' => 'WP', 'harga' => 1, 'aktif' => true]);

        return Service::create(['user_id' => $this->user->id, 'plan_id' => $plan->id, 'label' => 'WP', 'status' => 'aktif', 'harga' => 0,
            'node_id' => Node::create(['nama' => 'pod', 'agent_url' => 'http://agent.test', 'token' => str_repeat('a', 32)])->id]);
    }
}
