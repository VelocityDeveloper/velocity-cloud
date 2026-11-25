<?php

namespace Tests\Feature;

use App\Models\Tiket;
use App\Models\User;
use App\Notifications\TiketDibalas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TiketEmailTest extends TestCase
{
    use RefreshDatabase;

    private function emailMasuk(array ...$pesan): void
    {
        $kred = tempnam(sys_get_temp_dir(), 'kred');
        config(['cloud.tiket_email.kredensial' => $kred]);
        $baris = collect($pesan)->map(fn ($p, $i) => json_encode([
            'uid' => (string) ($i + 1), 'message_id' => null, 'rujukan' => [], 'dari' => 'tamu@contoh.id', 'nama' => 'Tamu',
            'subjek' => 'Tanya VPS', 'isi' => 'Halo', 'otomatis' => false, 'lampiran' => [], ...$p,
        ]))->implode("\n");
        Process::fake(fn ($p) => Process::result(in_array('tandai', (array) $p->command) ? '' : $baris));
    }

    public function test_email_dari_tamu_jadi_tiket_dan_balasan_admin_dikirim_berangkai(): void
    {
        Notification::fake();
        Storage::fake('local');
        $lampiran = tempnam(sys_get_temp_dir(), 'lamp');
        file_put_contents($lampiran, 'PDF');
        $this->emailMasuk(
            ['message_id' => '<a1@luar>', 'lampiran' => [['path' => $lampiran, 'nama' => 'bukti.pdf', 'mime' => 'application/pdf', 'ukuran' => 3], ['path' => $lampiran, 'nama' => 'x.svg', 'mime' => 'image/svg+xml', 'ukuran' => 3]]],
            ['message_id' => '<bounce@luar>', 'dari' => 'mailer-daemon@luar', 'otomatis' => true],
        );

        $this->artisan('tiket:ambil-email')->assertSuccessful();
        $this->artisan('tiket:ambil-email')->assertSuccessful(); // diulang: tidak dobel

        $tiket = Tiket::sole();
        $this->assertSame([null, 'tamu@contoh.id', 'Tanya VPS', 'terbuka'], [$tiket->user_id, $tiket->email_pengirim, $tiket->judul, $tiket->status]);
        $this->assertSame(['bukti.pdf'], $tiket->pesan()->sole()->lampiran->pluck('nama')->all()); // SVG ditolak
        Process::assertRan(fn ($p) => str_contains(implode(' ', (array) $p->command), 'tandai') && in_array('2', (array) $p->command)); // bounce tetap ditandai

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('tiket.show', $tiket))->assertOk()->assertInertia(fn ($p) => $p->where('pesan.0.nama', 'Tamu'));
        $this->actingAs($admin)->post(route('tiket.balas', $tiket), ['isi' => 'Bisa, kak'])->assertRedirect();

        Notification::assertSentTo(new AnonymousNotifiable, TiketDibalas::class, function ($n, $ch, $to) {
            return $to->routes['mail'] === 'tamu@contoh.id' && $n->rujukan === '<a1@luar>' && str_starts_with($n->messageId, '<tiket-');
        });
        $idBalasan = $tiket->pesan()->get()->last()->email_id;

        // pelanggan membalas email tim → masuk tiket yang sama dan membukanya lagi
        $this->emailMasuk(['message_id' => '<a2@luar>', 'rujukan' => [$idBalasan], 'subjek' => 'Re: Tanya VPS [Tiket #'.$tiket->id.']', 'isi' => 'Terima kasih']);
        $this->artisan('tiket:ambil-email')->assertSuccessful();
        $this->assertSame(1, Tiket::count());
        $this->assertSame(['terbuka', 3], [$tiket->fresh()->status, $tiket->pesan()->count()]);
    }

    public function test_pengirim_terdaftar_tertaut_ke_akunnya_dan_subjek_tiket_orang_lain_diabaikan(): void
    {
        $user = User::factory()->create(['email' => 'pelanggan@contoh.id']);
        $lain = User::factory()->create()->tiket()->create(['judul' => 'rahasia']);
        $this->emailMasuk(['message_id' => '<b1@luar>', 'dari' => 'pelanggan@contoh.id', 'subjek' => 'Re: x [Tiket #'.$lain->id.']']);

        $this->artisan('tiket:ambil-email')->assertSuccessful();

        $baru = Tiket::latest('id')->first();
        $this->assertNotSame($lain->id, $baru->id);
        $this->assertSame($user->id, $baru->user_id);
        $this->assertSame(0, $lain->pesan()->count());
    }
}
