<?php

namespace Tests\Feature;

use App\Actions\Billing\ProsesTagihan;
use App\Actions\Billing\TandaiLunas;
use App\Enums\InvoiceStatus;
use App\Enums\PlanKategori;
use App\Enums\ServiceStatus;
use App\Jobs\PodAction;
use App\Jobs\ProvisionPod;
use App\Models\Invoice;
use App\Models\Node;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CloudTest extends TestCase
{
    use RefreshDatabase;

    private function plan(string $slug = 'app-n8n', PlanKategori $kategori = PlanKategori::App, ?int $harga = 50000): Plan
    {
        return Plan::create(['kategori' => $kategori, 'slug' => $slug, 'nama' => 'Paket '.$slug, 'harga' => $harga]);
    }

    public function test_order_app_creates_pending_service_and_invoice(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan();

        $this->actingAs($user)->post(route('katalog.store', $plan))
            ->assertRedirect();

        $service = Service::sole();
        $this->assertSame($plan->nama, $service->label); // form tanpa field nama → nama paket
        $this->assertSame(ServiceStatus::MenungguPembayaran, $service->status);
        $this->assertMatchesRegularExpression('/^[a-z][a-z0-9]{9}$/', $service->subdomain); // subdomain acak
        $invoice = Invoice::sole();
        $this->assertSame(50000, $invoice->jumlah);
        $this->assertSame(InvoiceStatus::BelumDibayar, $invoice->status);
    }

    public function test_katalog_kategori_url_memilih_tab_tanpa_menutupi_halaman_pesan(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan();

        foreach (['/katalog' => 'app', '/katalog/cloud' => 'vps', '/katalog/managed-cloud' => 'managed', '/katalog/ai' => 'ai'] as $url => $kategori) {
            $this->actingAs($user)->get($url)
                ->assertInertia(fn (Assert $p) => $p->component('katalog/Index')->where('kategori', $kategori));
        }
        $this->actingAs($user)->get('/katalog/'.$plan->slug)->assertInertia(fn (Assert $p) => $p->component('katalog/Pesan'));
        $this->actingAs($user)->get('/katalog/tidak-ada')->assertNotFound();
    }

    public function test_order_rejects_invalid_label_and_unorderable_plan(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan();
        $this->actingAs($user)->post(route('katalog.store', $plan), ['label' => str_repeat('x', 61)])->assertSessionHasErrors('label');

        config(['cloud.pods.hermes-agent.containers.0.image' => null]); // image kosong → tidak bisa dipesan
        $hermes = $this->plan('app-hermes-agent');
        $this->actingAs($user)->post(route('katalog.store', $hermes), ['label' => 'x'])->assertStatus(422);

        $custom = $this->plan('managed-managed-custom', PlanKategori::Managed, null);
        $this->actingAs($user)->post(route('katalog.store', $custom), ['label' => 'x'])->assertStatus(422);
        $this->assertSame(0, Service::count());
    }

    public function test_paying_first_invoice_provisions_app_and_activates_vps_manually(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $tandai = app(TandaiLunas::class);

        $app = app(\App\Actions\Order\BuatPesanan::class)->handle($user, $this->plan(), 'n8n');
        $tandai->handle($app, 'transfer');
        $this->assertSame(ServiceStatus::Disiapkan, $app->service->fresh()->status);
        Queue::assertPushed(ProvisionPod::class, 1);

        $vps = app(\App\Actions\Order\BuatPesanan::class)->handle($user, $this->plan('vps-cloud-1', PlanKategori::Vps, 114000), 'vps');
        $tandai->handle($vps, 'transfer');
        $tandai->handle($vps, 'transfer'); // idempoten
        $this->assertSame(ServiceStatus::Disiapkan, $vps->service->fresh()->status);
        $this->assertSame($vps->periode_selesai->toDateString(), $vps->service->fresh()->jatuh_tempo->toDateString());
        Queue::assertPushed(ProvisionPod::class, 1);
    }

    public function test_provision_pod_calls_agent_and_stores_encrypted_credentials(): void
    {
        $node = Node::create(['nama' => 'node-1', 'agent_url' => 'http://agent.test', 'token' => str_repeat('a', 32)]);
        Http::fake(['agent.test/pods' => Http::response(['name' => 'svc-1', 'status' => 'running', 'host_port' => 41001])]);
        $service = Service::create(['user_id' => User::factory()->create()->id, 'plan_id' => $this->plan('app-waha')->id, 'label' => 'wa', 'subdomain' => 'wa1', 'status' => ServiceStatus::Disiapkan, 'harga' => 50000]);

        (new ProvisionPod($service))->handle();

        $service->refresh();
        $this->assertSame(ServiceStatus::Aktif, $service->status);
        $this->assertSame($node->id, $service->node_id);
        $this->assertStringNotContainsString('sandi', $service->getRawOriginal('detail'));
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer '.str_repeat('a', 32))
            && $r['name'] === 'svc-'.$service->id
            && ! str_contains(json_encode($r->data()), '{sandi}'));

        $this->withoutVite()->actingAs($service->user)->get(route('layanan.show', $service))
            ->assertInertia(fn (Assert $page) => $page->component('layanan/Show', false)->where('layanan.kredensial.sandi', fn ($v) => strlen($v) === 24));
    }

    public function test_spec_hermes_dashboard_berlogin_tanpa_membocorkan_rahasia(): void
    {
        $service = Service::create(['user_id' => User::factory()->create()->id, 'plan_id' => $this->plan('app-hermes-agent')->id, 'label' => 'h', 'subdomain' => 'h1', 'status' => ServiceStatus::Disiapkan, 'harga' => 75000]);

        [$spec, $kredensial] = \App\Services\Provisioning\PodSpec::untuk($service);
        $env = $spec['containers'][0]['env'];

        $this->assertSame(9119, $spec['port']);
        $this->assertSame($kredensial['sandi'], $env['HERMES_DASHBOARD_BASIC_AUTH_PASSWORD']);
        $this->assertSame('admin', $kredensial['pengguna']);
        $this->assertSame(32, strlen(base64_decode($env['HERMES_DASHBOARD_BASIC_AUTH_SECRET'], true)));
        $this->assertNotContains($env['HERMES_DASHBOARD_BASIC_AUTH_SECRET'], $kredensial);
        $this->assertArrayNotHasKey('sandi_db', $kredensial);
    }

    public function test_spec_nakama_seed_admin_email_pelanggan(): void
    {
        $user = User::factory()->create(['email' => 'budi@contoh.id']);
        $service = Service::create(['user_id' => $user->id, 'plan_id' => $this->plan('app-nakama')->id, 'label' => 'n', 'subdomain' => 'nk1', 'status' => ServiceStatus::Disiapkan, 'harga' => 50000]);

        [$spec, $kredensial] = \App\Services\Provisioning\PodSpec::untuk($service);
        $env = $spec['containers'][0]['env'];

        $this->assertSame(4310, $spec['port']);
        $this->assertSame('budi@contoh.id', $env['NAKAMA_SEED_ADMIN_EMAIL']);
        $this->assertSame('budi@contoh.id', $kredensial['pengguna']);
        $this->assertSame($kredensial['sandi'], $env['NAKAMA_SEED_ADMIN_PASSWORD']);
        $this->assertSame('https://'.$spec['host'], $env['NAKAMA_WEB_PUBLIC_URL']);
        $this->assertSame(32, strlen(base64_decode($env['NAKAMA_SECRETS_KEY'], true)));
        $this->assertNotContains($env['NAKAMA_SECRETS_KEY'], $kredensial);
    }

    public function test_provision_fails_cleanly_without_node(): void
    {
        $service = Service::create(['user_id' => User::factory()->create()->id, 'plan_id' => $this->plan()->id, 'label' => 'n', 'subdomain' => 'n1', 'status' => ServiceStatus::Disiapkan, 'harga' => 50000]);
        (new ProvisionPod($service))->handle();
        $this->assertSame(ServiceStatus::Gagal, $service->fresh()->status);
    }

    public function test_billing_issues_renewal_and_suspends_overdue(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $plan = $this->plan();
        $buatLayanan = fn (string $sub, $tempo) => Service::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'label' => $sub, 'subdomain' => $sub, 'status' => ServiceStatus::Aktif, 'harga' => 50000, 'jatuh_tempo' => $tempo, 'node_id' => Node::create(['nama' => $sub, 'agent_url' => 'http://a.test', 'token' => str_repeat('b', 32)])->id]);

        $segera = $buatLayanan('segera', today()->addDays(5));
        $jauh = $buatLayanan('jauh', today()->addDays(20));
        $telat = $buatLayanan('telat', today()->subDays(5));
        Invoice::create(['user_id' => $user->id, 'service_id' => $telat->id, 'nomor' => 'INV-X', 'jumlah' => 50000, 'status' => InvoiceStatus::BelumDibayar, 'periode_mulai' => today()->subDays(5), 'periode_selesai' => today()->addDays(25), 'jatuh_tempo' => today()->subDays(5)]);

        [$terbit, $ditangguhkan] = app(ProsesTagihan::class)->handle();

        $this->assertSame([1, 1], [$terbit, $ditangguhkan]);
        $this->assertSame(1, $segera->invoices()->count());
        $this->assertSame(0, $jauh->invoices()->count());
        $this->assertSame(ServiceStatus::Ditangguhkan, $telat->fresh()->status);
        Queue::assertPushed(PodAction::class, fn ($j) => $j->aksi === 'stop');
        $this->assertSame([0, 0], app(ProsesTagihan::class)->handle()); // tidak dobel

        app(TandaiLunas::class)->handle($telat->invoices()->sole(), 'transfer');
        $this->assertSame(ServiceStatus::Aktif, $telat->fresh()->status);
    }

    public function test_customers_cannot_see_others_or_admin_pages(): void
    {
        $pemilik = User::factory()->create();
        $lain = User::factory()->create();
        $invoice = app(\App\Actions\Order\BuatPesanan::class)->handle($pemilik, $this->plan(), 'n8n');

        $this->actingAs($lain)->get(route('tagihan.show', $invoice))->assertNotFound();
        $this->actingAs($lain)->get(route('layanan.show', $invoice->service))->assertNotFound();
        $this->actingAs($pemilik)->get(route('admin.tagihan.index'))->assertForbidden();
        $this->actingAs($pemilik)->post(route('admin.tagihan.lunas', $invoice), ['metode' => 'transfer'])->assertForbidden();
        $this->assertSame(InvoiceStatus::BelumDibayar, $invoice->fresh()->status);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->post(route('admin.tagihan.lunas', $invoice), ['metode' => 'transfer'])->assertRedirect();
        $this->assertSame(InvoiceStatus::Lunas, $invoice->fresh()->status);
    }

    public function test_pelanggan_melihat_status_dan_log_pod_miliknya(): void
    {
        Http::fake([
            'a.test/pods/*/log*' => Http::response(['name' => 'x', 'log' => ['svc-1-n8n' => 'siap']]),
            'a.test/pods/*' => Http::response(['name' => 'x', 'status' => 'Running', 'containers' => [], 'pemakaian' => []]),
        ]);
        $user = User::factory()->create();
        $node = Node::create(['nama' => 'n', 'agent_url' => 'http://a.test', 'token' => str_repeat('b', 32)]);
        $s = Service::create(['user_id' => $user->id, 'plan_id' => $this->plan()->id, 'label' => 'n', 'subdomain' => 'abcdefghij', 'status' => ServiceStatus::Aktif, 'harga' => 50000, 'node_id' => $node->id]);

        $this->actingAs($user)->get(route('layanan.show', $s))
            ->assertInertia(fn (Assert $p) => $p->missing('pod')->missing('log')
                ->loadDeferredProps(fn (Assert $r) => $r->where('pod.status', 'Running')));
        $this->actingAs($user)->get(route('layanan.show', $s), ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'layanan/Show', 'X-Inertia-Partial-Data' => 'log', 'X-Inertia-Version' => \Inertia\Inertia::getVersion()])
            ->assertJsonPath('props.log.svc-1-n8n', 'siap');
        $this->actingAs(User::factory()->create())->get(route('layanan.show', $s))->assertNotFound();
    }

    public function test_spec_openclaw_token_origin_dan_proxy(): void
    {
        $service = Service::create(['user_id' => User::factory()->create()->id, 'plan_id' => $this->plan('app-openclaw')->id, 'label' => 'o', 'subdomain' => 'oc1', 'status' => ServiceStatus::Disiapkan, 'harga' => 100000]);

        [$spec, $kredensial] = \App\Services\Provisioning\PodSpec::untuk($service);
        $c = $spec['containers'][0];

        $this->assertSame(18789, $spec['port']);
        $this->assertSame($kredensial['sandi'], $c['env']['OPENCLAW_GATEWAY_TOKEN']);
        $this->assertSame($spec['host'], $c['env']['VC_HOST']);
        $this->assertStringContainsString('gateway.trustedProxies', $c['args'][2]);
        $this->assertStringContainsString('process.env.VC_HOST', $c['args'][2]);
        $this->assertLessThanOrEqual(500, strlen($c['args'][2])); // batas panjang arg di agent
        $this->assertSame(['sandi'], array_keys($kredensial));
    }

    public function test_pelanggan_menyetujui_pairing_openclaw_miliknya(): void
    {
        Http::fake([
            'a.test/pods/svc-*/perangkat/req-1' => Http::response(['requestId' => 'req-1']),
            'a.test/pods/svc-*/perangkat' => Http::response(['pending' => [['requestId' => 'req-1', 'platform' => 'Linux x86_64', 'remoteIp' => '203.0.113.7', 'ts' => 1794465701222]], 'paired' => []]),
            'a.test/pods/*' => Http::response(['name' => 'x', 'status' => 'Running', 'containers' => [], 'pemakaian' => []]),
        ]);
        $user = User::factory()->create();
        $node = Node::create(['nama' => 'n', 'agent_url' => 'http://a.test', 'token' => str_repeat('b', 32)]);
        $s = Service::create(['user_id' => $user->id, 'plan_id' => $this->plan('app-openclaw')->id, 'label' => 'o', 'subdomain' => 'abcdefghij', 'status' => ServiceStatus::Aktif, 'harga' => 100000, 'node_id' => $node->id]);

        $this->actingAs($user)->get(route('layanan.show', $s))->assertInertia(fn (Assert $p) => $p->where('layanan.pairing', true)->missing('perangkat'));
        $this->actingAs($user)->get(route('layanan.show', $s), ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'layanan/Show', 'X-Inertia-Partial-Data' => 'perangkat', 'X-Inertia-Version' => \Inertia\Inertia::getVersion()])
            ->assertJsonPath('props.perangkat.menunggu.0.id', 'req-1')
            ->assertJsonPath('props.perangkat.menunggu.0.nama', 'Browser · Linux x86_64')
            ->assertJsonPath('props.perangkat.menunggu.0.ip', '203.0.113.7')
            ->assertJsonMissingPath('props.perangkat.menunggu.0.publicKey');

        $this->actingAs($user)->post(route('layanan.perangkat', [$s, 'req-1']))->assertRedirect();
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/pods/'.$s->podName().'/perangkat/req-1'));

        $this->actingAs($user)->post(route('layanan.perangkat', [$s, 'id tidak sah']))->assertStatus(422);
        $this->actingAs(User::factory()->create())->post(route('layanan.perangkat', [$s, 'req-1']))->assertNotFound();

        $n8n = Service::create(['user_id' => $user->id, 'plan_id' => $this->plan()->id, 'label' => 'n', 'subdomain' => 'bcdefghijk', 'status' => ServiceStatus::Aktif, 'harga' => 50000, 'node_id' => $node->id]);
        $this->actingAs($user)->post(route('layanan.perangkat', [$n8n, 'req-1']))->assertStatus(422);
    }

    public function test_provision_n8n_membuat_owner_sebelum_aktif(): void
    {
        Node::create(['nama' => 'node-1', 'agent_url' => 'http://agent.test', 'token' => str_repeat('a', 32)]);
        $user = User::factory()->create(['email' => 'budi@contoh.id']);
        $service = Service::create(['user_id' => $user->id, 'plan_id' => $this->plan()->id, 'label' => 'n', 'subdomain' => 'n8nuji1234', 'status' => ServiceStatus::Disiapkan, 'harga' => 50000]);
        Http::fake([
            'agent.test/pods' => Http::response(['name' => 'svc-1', 'status' => 'Running', 'host_port' => 41001]),
            'n8nuji1234.*/healthz/readiness' => Http::sequence()->push('', 503)->push(['status' => 'ok']),
            'n8nuji1234.*/rest/owner/setup' => Http::response(['data' => ['id' => 'x']]),
        ]);
        \Illuminate\Support\Sleep::fake();

        (new ProvisionPod($service))->handle();

        $service->refresh();
        $this->assertSame(ServiceStatus::Aktif, $service->status);
        $kred = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($service->detail['kredensial']), true);
        $this->assertSame('budi@contoh.id', $kred['pengguna']);
        $this->assertMatchesRegularExpression('/(?=.*[A-Z])(?=.*[a-z])(?=.*\d)/', $kred['sandi']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/rest/owner/setup') && $r['email'] === 'budi@contoh.id' && $r['password'] === $kred['sandi']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'agent.test') && str_contains(json_encode($r->data()), 'owner'));
    }

    public function test_provision_wordpress_gagal_bila_instalasi_tidak_berhasil(): void
    {
        Node::create(['nama' => 'node-1', 'agent_url' => 'http://agent.test', 'token' => str_repeat('a', 32)]);
        $service = Service::create(['user_id' => User::factory()->create()->id, 'plan_id' => $this->plan('app-wordpress')->id, 'label' => 'w', 'subdomain' => 'wpuji12345', 'status' => ServiceStatus::Disiapkan, 'harga' => 35000]);
        Http::fake([
            'agent.test/pods' => Http::response(['name' => 'svc-1', 'status' => 'Running', 'host_port' => 41002]),
            'wpuji12345.*/wp-admin/install.php' => Http::response('<form>install</form>'),
            'wpuji12345.*/wp-admin/install.php?step=2' => Http::response('<p>Error: sandi tidak cocok</p>'),
        ]);

        $this->expectException(\RuntimeException::class);
        try {
            (new ProvisionPod($service))->handle();
        } finally {
            $this->assertSame(ServiceStatus::Disiapkan, $service->fresh()->status); // tidak ditandai aktif
            Http::assertSent(fn ($r) => str_contains($r->url(), 'step=2') && $r['user_name'] === $service->user->email && $r['admin_password'] === $r['admin_password2']);
        }
    }
}
