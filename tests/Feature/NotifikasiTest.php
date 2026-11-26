<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\Pemberitahuan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotifikasiTest extends TestCase
{
    use RefreshDatabase;

    public function test_lonceng_tampil_dan_klik_menandai_dibaca(): void
    {
        $u = User::factory()->create();
        $u->notify(new Pemberitahuan('bonus', 'Bonus 1.000.000 token', 'Isi', '/layanan'));
        $u->notify(new Pemberitahuan('bonus', 'Luar', 'Isi', 'https://contoh.test'));

        $this->actingAs($u)->get('/customer/dashboard')->assertInertia(fn (Assert $p) => $p
            ->where('notifikasi.belum', 2)->has('notifikasi.daftar', 2)->has('notifikasi.daftar.0.judul')->where('notifikasi.daftar.0.dibaca', false));

        $id = $u->notifications->firstWhere('data.judul', 'Bonus 1.000.000 token')->id;
        $this->actingAs($u)->post("/notifikasi/$id/baca")->assertRedirect('/layanan');
        $this->assertSame(1, $u->unreadNotifications()->count());

        // URL luar tidak diikuti
        $luar = $u->unreadNotifications()->value('id');
        $this->actingAs($u)->from('/customer/dashboard')->post("/notifikasi/$luar/baca")->assertRedirect('/customer/dashboard');

        $u->notify(new Pemberitahuan('bonus', 'Lagi', 'Isi'));
        $this->actingAs($u)->post('/notifikasi/baca-semua');
        $this->assertSame(0, $u->unreadNotifications()->count());
    }

    public function test_tidak_bisa_membaca_notifikasi_orang_lain(): void
    {
        $a = User::factory()->create();
        $a->notify(new Pemberitahuan('bonus', 'Rahasia', 'Isi'));
        $this->actingAs(User::factory()->create())->post('/notifikasi/'.$a->notifications()->value('id').'/baca')->assertNotFound();
        $this->assertSame(1, $a->unreadNotifications()->count());
    }

    public function test_admin_siarkan_ke_semua_lalu_tarik(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $users = User::factory()->count(2)->create();

        $this->actingAs($users[0])->post('/admin/notifikasi', [])->assertForbidden();
        $this->actingAs($admin)->post('/admin/notifikasi', ['tujuan' => 'semua', 'judul' => 'Diskon', 'isi' => 'Isi', 'url' => 'https://luar.test'])->assertSessionHasErrors('url');
        $this->actingAs($admin)->post('/admin/notifikasi', ['tujuan' => 'semua', 'judul' => 'Diskon', 'isi' => 'Isi', 'url' => '/katalog/ai'])->assertSessionHasNoErrors();
        $this->assertSame(1, $users[1]->unreadNotifications()->count());
        $this->assertSame('info', $users[1]->notifications()->first()->data['jenis']);

        $this->actingAs($admin)->post('/admin/notifikasi', ['tujuan' => 'email', 'email' => $users[0]->email, 'judul' => 'Pribadi', 'isi' => 'Isi']);
        $this->assertSame(2, $users[0]->notifications()->count());
        $this->assertSame(1, $users[1]->notifications()->count());

        $this->actingAs($admin)->get("/admin/notifikasi")->assertInertia(fn (Assert $p) => $p
            ->has('riwayat', 2)->where('riwayat', fn ($r) => collect($r)->firstWhere('judul', 'Diskon')['penerima'] === 3));

        $kiriman = $users[1]->notifications()->first()->data['kiriman'];
        $this->actingAs($admin)->delete("/admin/notifikasi/$kiriman");
        $this->assertSame(0, $users[1]->notifications()->count());
        $this->assertSame(1, $users[0]->notifications()->count());

        // notifikasi perorangan tanpa kiriman (bonus manual/promo) ikut tampil dengan nama penerima & bisa ditarik
        $users[1]->notify(new \App\Notifications\Pemberitahuan('bonus', 'Bonus 300.000 token', 'Isi', '/layanan/1'));
        $id = $users[1]->notifications()->first()->id;
        $this->actingAs($admin)->get('/admin/notifikasi')->assertInertia(fn (Assert $p) => $p
            ->where('riwayat', fn ($r) => collect($r)->firstWhere('judul', 'Bonus 300.000 token')['ke'] === $users[1]->name));
        $this->actingAs($admin)->delete("/admin/notifikasi/$id");
        $this->assertSame(0, $users[1]->notifications()->count());
        $this->assertSame(1, $users[0]->notifications()->count());
    }
}
