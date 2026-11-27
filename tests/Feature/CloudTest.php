<?php

namespace Tests\Feature;

use App\Actions\Billing\ProsesTagihan;
use App\Actions\Billing\TandaiLunas;
use App\Enums\InvoiceStatus;
use App\Enums\PlanKategori;
use App\Enums\ServiceStatus;
use App\Jobs\PodAction;
use App\Jobs\ProvisionHosting;
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

    public function test_hosting_wajib_domain_dan_ditagih_tahunan(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $plan = $this->plan('hosting-hosting-1-gb', PlanKategori::Hosting, 700000);

        $this->actingAs($user)->post(route('katalog.store', $plan))->assertSessionHasErrors('domain');
        $this->actingAs($user)->post(route('katalog.store', $plan), ['domain' => 'bukan domain'])->assertSessionHasErrors('domain');
        $this->actingAs($user)->post(route('katalog.store', $this->plan()), ['domain' => 'contoh.com'])->assertSessionHasErrors('domain'); // selain hosting ditolak

        $this->actingAs($user)->post(route('katalog.store', $plan), ['domain' => ' Contoh.COM '])->assertRedirect();
        $service = Service::sole();
        $this->assertSame('contoh.com', $service->custom_domain);
        $invoice = $service->invoices()->sole();
        $this->assertSame(700000, (int) $invoice->jumlah);
        $this->assertTrue($invoice->periode_selesai->equalTo(today()->addYear()));

        app(TandaiLunas::class)->handle($invoice, 'transfer');
        $this->assertSame(ServiceStatus::Disiapkan, $service->fresh()->status);
        Queue::assertPushed(ProvisionHosting::class); // akun DirectAdmin dibuat job

        $service->update(['status' => ServiceStatus::Aktif, 'jatuh_tempo' => today()->addDays(3)]);
        app(ProsesTagihan::class)->handle();
        $this->assertTrue($service->invoices()->latest('id')->first()->periode_selesai->equalTo(today()->addDays(3)->addYear()));
    }

    public function test_provision_hosting_membuat_akun_directadmin(): void
    {
        $plan = Plan::create(['kategori' => PlanKategori::Hosting, 'slug' => 'hosting-hosting-5-gb', 'nama' => 'Hosting 5 GB', 'harga' => 3500000, 'spek' => ['disk' => '5 GB']]);
        $user = User::factory()->create(['email' => 'pelanggan@contoh.com']);
        $service = Service::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'label' => 'x', 'status' => ServiceStatus::Disiapkan, 'harga' => 3500000, 'custom_domain' => 'toko-baju.co.id']);
        Service::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'label' => 'y', 'status' => ServiceStatus::Disiapkan, 'harga' => 1, 'detail' => ['da_user' => 'tokobaju']]); // sedang dibuat layanan lain
        $node = Node::create(['nama' => 'sanke', 'jenis' => 'directadmin', 'agent_url' => 'https://da.test:2222', 'da_user' => 'admin', 'token' => str_repeat('k', 64), 'kapasitas_pod' => 100]);
        $teks = ['Content-Type' => 'text/plain'];
        Http::fake([
            'da.test:2222/CMD_API_USER_EXISTS*' => fn ($r) => Http::response('error=0&exists='.(str_contains($r->url(), 'user=admin') ? 1 : 0), 200, $teks),
            'da.test:2222/CMD_API_SHOW_USER_CONFIG*' => Http::response('ip=157.66.55.81&ns1=ns1.velocitydeveloper.net&ns2=ns2.velocitydeveloper.net', 200, $teks),
            'da.test:2222/CMD_API_ACCOUNT_USER' => Http::sequence()->push('error=1&text=Cannot%20Create%20Account&details=Domain%20exists', 200, $teks)->push('error=0&text=User%20created', 200, $teks),
        ]);

        try {
            (new ProvisionHosting($service))->handle();
            $this->fail('error DA harus jadi exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Domain exists', $e->getMessage());
        }
        $akun = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($service->fresh()->detail['kredensial']), true);
        $this->assertMatchesRegularExpression('/^tokobaju\d{2}$/', $akun['pengguna']); // tokobaju dipakai layanan lain

        (new ProvisionHosting($service))->handle(); // percobaan ulang: username & sandi sama
        $s = $service->fresh();
        $this->assertSame(ServiceStatus::Aktif, $s->status);
        $this->assertSame($node->id, $s->node_id);
        $this->assertSame($akun, json_decode(\Illuminate\Support\Facades\Crypt::decryptString($s->detail['kredensial']), true));
        $this->assertStringContainsString('ns1.velocitydeveloper.net', $s->detail['akses']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/CMD_API_ACCOUNT_USER') && $r['username'] === $akun['pengguna'] && $r['passwd'] === $akun['sandi']
            && $r['domain'] === 'toko-baju.co.id' && $r['package'] === 'velocity_5000mb' && $r['ip'] === '157.66.55.81' && $r['email'] === 'pelanggan@contoh.com' && $r['notify'] === 'no');
        $this->assertSame('u123abcd', ProvisionHosting::usernameDasar('123abcdefg.com'));
    }

    public function test_hosting_ditangguhkan_suspend_akun_da_dan_lunas_unsuspend(): void
    {
        Queue::fake();
        $plan = Plan::create(['kategori' => PlanKategori::Hosting, 'slug' => 'hosting-hosting-1-gb', 'nama' => 'Hosting 1 GB', 'harga' => 700000, 'spek' => ['disk' => '1 GB']]);
        $user = User::factory()->create();
        $node = Node::create(['nama' => 'sanke', 'jenis' => 'directadmin', 'agent_url' => 'https://da.test:2222', 'da_user' => 'admin', 'token' => str_repeat('k', 64), 'kapasitas_pod' => 100]);
        $s = Service::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'label' => 'x', 'status' => ServiceStatus::Aktif, 'harga' => 700000, 'jatuh_tempo' => today()->subDays(5), 'node_id' => $node->id, 'detail' => ['da_user' => 'contoh']]);
        Invoice::create(['user_id' => $user->id, 'service_id' => $s->id, 'nomor' => 'INV-H', 'jumlah' => 700000, 'status' => InvoiceStatus::BelumDibayar, 'periode_mulai' => today()->subDays(5), 'periode_selesai' => today()->addYear(), 'jatuh_tempo' => today()->subDays(5)]);

        $this->assertSame([0, 1], app(ProsesTagihan::class)->handle());
        $this->assertSame(ServiceStatus::Ditangguhkan, $s->fresh()->status);
        Queue::assertPushed(PodAction::class, fn ($j) => $j->aksi === 'stop' && $j->service->is($s));

        $teks = ['Content-Type' => 'text/plain'];
        $status = 'yes';
        Http::fake([
            'da.test:2222/CMD_API_SELECT_USERS' => Http::response('error=0&text=ok', 200, $teks),
            'da.test:2222/CMD_API_SHOW_USER_CONFIG*' => function () use (&$status, $teks) { return Http::response("suspended=$status", 200, $teks); },
        ]);
        (new PodAction($s->fresh(), 'stop'))->handle();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/CMD_API_SELECT_USERS') && $r['dosuspend'] === 'yes' && $r['select0'] === 'contoh');

        app(TandaiLunas::class)->handle($s->invoices()->sole(), 'transfer');
        $this->assertSame(ServiceStatus::Aktif, $s->fresh()->status);
        Queue::assertPushed(PodAction::class, fn ($j) => $j->aksi === 'start');
        $status = 'no';
        (new PodAction($s->fresh(), 'start'))->handle();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/CMD_API_SELECT_USERS') && ($r['dounsuspend'] ?? null) === 'yes');

        $status = 'yes'; // DA tidak berubah → gagal, job dicoba ulang
        $this->expectException(\RuntimeException::class);
        (new PodAction($s->fresh(), 'start'))->handle();
    }

    public function test_admin_tangguhkan_manual_ikut_hentikan_resource(): void
    {
        Queue::fake();
        $plan = Plan::create(['kategori' => PlanKategori::Hosting, 'slug' => 'hosting-hosting-1-gb', 'nama' => 'Hosting 1 GB', 'harga' => 700000]);
        $node = Node::create(['nama' => 'sanke', 'jenis' => 'directadmin', 'agent_url' => 'https://da.test:2222', 'da_user' => 'admin', 'token' => str_repeat('k', 64)]);
        $s = Service::create(['user_id' => User::factory()->create()->id, 'plan_id' => $plan->id, 'label' => 'x', 'status' => ServiceStatus::Aktif, 'harga' => 1, 'node_id' => $node->id, 'detail' => ['da_user' => 'contoh']]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->put(route('admin.layanan.update', $s), ['status' => 'ditangguhkan'])->assertRedirect();
        Queue::assertPushed(PodAction::class, fn ($j) => $j->aksi === 'stop');
        $this->actingAs($admin)->put(route('admin.layanan.update', $s), ['status' => 'aktif'])->assertRedirect();
        Queue::assertPushed(PodAction::class, fn ($j) => $j->aksi === 'start');
        $this->actingAs($admin)->put(route('admin.layanan.update', $s), ['status' => 'aktif'])->assertRedirect();
        Queue::assertPushed(PodAction::class, 2); // tanpa perubahan status = tanpa aksi
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

    public function test_admin_layanan_filter_jenis(): void
    {
        $u = User::factory()->create();
        $app = Service::create(['user_id' => $u->id, 'plan_id' => $this->plan()->id, 'label' => 'a', 'status' => ServiceStatus::Aktif, 'harga' => 1]);
        $vps = Service::create(['user_id' => $u->id, 'plan_id' => $this->plan('vps-1', PlanKategori::Vps)->id, 'label' => 'v', 'status' => ServiceStatus::Ditangguhkan, 'harga' => 1]);
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $ids = fn (string $q) => collect($this->actingAs($admin)->get('/admin/layanan'.$q)->assertOk()->viewData('page')['props']['layanan'])->pluck('id')->sort()->values()->all();
        $this->assertSame([$vps->id], $ids('?jenis=vps'));
        $this->assertSame([], $ids('?jenis=app&status=ditangguhkan'));
        $this->assertSame([$app->id, $vps->id], $ids('?jenis=ngawur'));
    }

    public function test_admin_tagihan_filter_jenis_produk(): void
    {
        Queue::fake();
        $pemilik = User::factory()->create();
        $app = app(\App\Actions\Order\BuatPesanan::class)->handle($pemilik, $this->plan(), 'n8n');
        $app2 = Invoice::create(['user_id' => $pemilik->id, 'service_id' => $app->service_id, 'nomor' => 'INV-APP-2', 'jumlah' => 1, 'status' => InvoiceStatus::Lunas, 'periode_mulai' => now(), 'periode_selesai' => now()->addMonth(), 'jatuh_tempo' => now()]);
        $vps = Service::create(['user_id' => $pemilik->id, 'plan_id' => $this->plan('vps-1', PlanKategori::Vps)->id, 'label' => 'v', 'status' => ServiceStatus::Aktif, 'harga' => 1]);
        $tagihanVps = Invoice::create(['user_id' => $pemilik->id, 'service_id' => $vps->id, 'nomor' => 'INV-VPS-1', 'jumlah' => 1, 'status' => InvoiceStatus::BelumDibayar, 'periode_mulai' => now(), 'periode_selesai' => now()->addMonth(), 'jatuh_tempo' => now()]);
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $ids = fn (string $q) => collect($this->actingAs($admin)->get('/admin/tagihan'.$q)->assertOk()->viewData('page')['props']['tagihan'])->pluck('id')->sort()->values()->all();
        $this->assertSame([$tagihanVps->id], $ids('?jenis=vps'));
        $this->assertSame([$app->id], $ids('?jenis=app&status=belum_dibayar'));
        $this->assertCount(3, $ids('?jenis=ngawur'));

        // ringkasan: tanpa dibatalkan, ikut filter jenis; nominal = jumlah + kode unik
        Invoice::create(['user_id' => $pemilik->id, 'service_id' => $vps->id, 'nomor' => 'INV-VPS-X', 'jumlah' => 999, 'status' => InvoiceStatus::Dibatalkan, 'periode_mulai' => now(), 'periode_selesai' => now()->addMonth(), 'jatuh_tempo' => now()]);
        $app2->update(['dibayar_pada' => now()]);
        $r = $this->actingAs($admin)->get('/admin/tagihan?jenis=app')->viewData('page')['props']['ringkasan'];
        $this->assertSame(2, $r['tagihan']['jumlah']);
        $this->assertSame($app->fresh()->total() + 1, $r['tagihan']['nominal']);
        $this->assertSame(['nominal' => 1, 'jumlah' => 1], $r['dibayar_hari']);
        $this->assertSame(['nominal' => 1, 'jumlah' => 1], $r['dibayar_bulan']);
        $this->assertSame(1, $this->actingAs($admin)->get('/admin/tagihan?jenis=vps')->viewData('page')['props']['ringkasan']['tagihan']['jumlah']);

        // filter metode: daftar + ringkasan; nilai tak dikenal = semua
        $app2->update(['metode' => 'midtrans']);
        $this->assertSame([$app2->id], $ids('?metode=midtrans'));
        $mode = fn () => collect($this->actingAs($admin)->get('/admin/tagihan?metode=midtrans')->viewData('page')['props']['tagihan'])->pluck('midtrans_mode')->all();
        $app2->update(['midtrans' => ['mode' => 'sandbox']]);
        $this->assertSame(['sandbox'], $mode());
        $app2->update(['midtrans' => ['mode' => 'production']]);
        $this->assertSame(['production'], $mode());
        $this->assertSame([], $ids('?metode=qris'));
        $this->assertCount(4, $ids('?metode=ngawur'));
        $this->assertSame(['nominal' => 1, 'jumlah' => 1], $this->actingAs($admin)->get('/admin/tagihan?metode=midtrans')->viewData('page')['props']['ringkasan']['dibayar']);
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
