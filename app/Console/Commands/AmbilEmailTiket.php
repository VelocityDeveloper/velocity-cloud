<?php

namespace App\Console\Commands;

use App\Models\Tiket;
use App\Models\TiketPesan;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Email masuk support@ → tiket (tiap menit). Balasan (In-Reply-To/References ke Message-ID yang dikenal, atau subjek
 * "[Tiket #id]" dari pengirim yang sama) masuk ke tiket lama; selain itu tiket baru. Pengirim = akun bila email cocok.
 * Email otomatis (bounce, auto-reply, dari support@ sendiri) hanya ditandai, tidak jadi tiket.
 */
class AmbilEmailTiket extends Command
{
    protected $signature = 'tiket:ambil-email {--tandai-saja : tandai semua email yang ada tanpa membuat tiket (pemasangan awal)}';

    protected $description = 'Ambil email masuk support@ menjadi tiket';

    private const MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf', 'video/mp4', 'video/webm', 'video/quicktime'];

    public function handle(): int
    {
        $kred = config('cloud.tiket_email.kredensial');
        if (! $kred || ! is_readable($kred)) {
            return self::SUCCESS; // belum dipasang
        }
        $folder = storage_path('app/private/tiket-email-'.Str::random(8));
        File::ensureDirectoryExists($folder, 0700);
        try {
            $hasil = Process::timeout(120)->run(['python3', '-I', base_path('scripts/ambil-email.py'), 'ambil', $kred, $folder]);
            if ($hasil->failed()) {
                $this->error(trim($hasil->errorOutput()));

                return self::FAILURE;
            }
            $selesai = [];
            foreach (array_filter(explode("\n", $hasil->output())) as $baris) {
                $m = json_decode($baris, true);
                if (! $this->option('tandai-saja') && ! $m['otomatis']) {
                    $this->simpan($m);
                }
                $selesai[] = $m['uid'];
            }
            if ($selesai) {
                Process::timeout(60)->run(['python3', '-I', base_path('scripts/ambil-email.py'), 'tandai', $kred, ...$selesai])->throw();
            }
            $this->info(count($selesai).' email diproses.');
        } finally {
            File::deleteDirectory($folder);
        }

        return self::SUCCESS;
    }

    /** @param array{message_id: ?string, rujukan: string[], dari: string, nama: string, subjek: string, isi: string, lampiran: array} $m */
    private function simpan(array $m): void
    {
        $id = $m['message_id'] ?: '<uid-'.$m['uid'].'-'.md5($m['dari'].$m['subjek']).'>';
        if (TiketPesan::where('email_id', $id)->exists()) {
            return;
        }
        $user = User::whereRaw('lower(email) = ?', [$m['dari']])->first();
        $tiket = $this->tiketLama($m);

        DB::transaction(function () use ($m, $id, $user, &$tiket) {
            $tiket ??= Tiket::create([
                'user_id' => $user?->id,
                'email_pengirim' => $m['dari'],
                'nama_pengirim' => mb_substr($m['nama'], 0, 120) ?: null,
                'jenis' => 'support',
                'judul' => mb_substr($m['subjek'], 0, 120) ?: '(tanpa subjek)',
                'status' => 'terbuka',
            ]);
            $pesan = $tiket->pesan()->create([
                'user_id' => $user?->id,
                'dari_admin' => false,
                'isi' => mb_substr(trim($m['isi']), 0, 20000) ?: '(email tanpa isi teks)',
                'email_id' => $id,
            ]);
            // aturan sama dengan unggahan web: maks 3 berkas, 10 MB, tanpa SVG/HTML
            foreach (collect($m['lampiran'])->filter(fn ($l) => in_array($l['mime'], self::MIME) && $l['ukuran'] <= 10 * 1024 * 1024)->take(3) as $l) {
                $path = 'tiket/'.$tiket->id.'/'.Str::random(40);
                Storage::disk('local')->put($path, file_get_contents($l['path']));
                $pesan->lampiran()->create(['path' => $path, 'nama' => $l['nama'], 'mime' => $l['mime'], 'ukuran' => $l['ukuran']]);
            }
            $tiket->update(['status' => 'terbuka']);
        });
    }

    private function tiketLama(array $m): ?Tiket
    {
        if ($m['rujukan'] && $p = TiketPesan::whereIn('email_id', $m['rujukan'])->latest('id')->first()) {
            return $p->tiket;
        }
        if (preg_match('/\[Tiket #(\d+)\]/', $m['subjek'], $c)) {
            return Tiket::whereKey($c[1])->where(fn ($q) => $q->where('email_pengirim', $m['dari'])
                ->orWhereHas('user', fn ($u) => $u->whereRaw('lower(email) = ?', [$m['dari']])))->first();
        }

        return null;
    }
}
