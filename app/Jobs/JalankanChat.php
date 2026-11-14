<?php

namespace App\Jobs;

use App\Enums\PlanKategori;
use App\Enums\ServiceStatus;
use App\Models\ChatPercakapan;
use App\Models\Service;
use App\Services\Chat\AlatLayanan;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Satu giliran Chat AI di server: panggil model (streaming) → jalankan alat → ulangi sampai model selesai menjawab.
 * Progres ditulis ke chat_percakapan (pesan terakhir + proses) dan dipantau browser lewat GET chat/{uuid}/status,
 * jadi pelanggan aman pindah halaman/tutup tab. Alat berisiko berhenti di status "menunggu" sampai pelanggan
 * menyetujui (POST chat/{uuid}/izin, job dikirim ulang). Status "stop" = pelanggan menekan Berhenti.
 */
class JalankanChat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800; // < retry_after koneksi queue "chat" (2400)

    public const BATAS_KONTEKS = 20; // pesan terakhir yang dikirim ke model, agar saldo tidak terkuras percakapan panjang

    public const MIME_GAMBAR = ['image/jpeg', 'image/png', 'image/webp', 'image/gif']; // dikirim ke model sebagai image_url (vision)

    public const BATAS_PUTARAN = 30; // putaran alat per pertanyaan (desain situs ±15–25), agar model tidak berputar tanpa akhir

    public const SISTEM = <<<'TXT'
        Anda asisten AI Velocity Cloud (app.velocitydeveloper.ai.id). Selain menjawab pertanyaan umum, Anda bisa mengelola layanan yang dibeli pelanggan memakai alat: lihat daftar & status, baca log, nyalakan/matikan/mulai ulang, WP-CLI untuk App WordPress, dan perintah shell untuk Cloud Server (VPS).
        - Panggil daftar_layanan dulu bila belum tahu ID layanan; pakai hanya alat yang tercantum di field "alat" layanan itu.
        - Alat yang mengubah layanan butuh persetujuan pelanggan; bila ditolak, jangan ulangi tanpa diminta.
        - Untuk tindakan berisiko (hapus data, ganti sandi, update besar) jelaskan dampaknya dulu.
        - Managed Server dan layanan nonaktif belum bisa dikelola dari chat: arahkan ke menu Tiket.
        - Membuat/mendesain halaman atau tampilan WordPress: panggil panduan_desain_wp dulu lalu ikuti alurnya; jangan menulis HTML polos sebagai isi halaman.
        - Lampiran pelanggan tertulis sebagai [Lampiran: nama (jenis, ukuran) URL]. Gambar (jpg/png/webp/gif) ikut terkirim sehingga isinya bisa Anda lihat; PDF dan video tidak, jangan menebak isinya. Untuk memakainya di WordPress unggah dengan WP-CLI `media import <URL> --title=<nama> --porcelain` (pakai URL utuh, termasuk ?signature=…), di VPS unduh dengan curl.
        - Jawab ringkas dalam bahasa pelanggan.
        TXT;

    private const TULIS_TIAP = 0.7; // detik: jeda tulis teks streaming ke DB (SQLite)

    private ChatPercakapan $p;

    public function __construct(public int $percakapanId)
    {
        $this->onConnection(config('cloud.chat_ai.antrean'));
    }

    /** Layanan token AI aktif milik user yang dipakai percakapan; null = tidak bisa dipakai. */
    public static function layananAi(int $userId, ?int $serviceId): ?Service
    {
        $s = Service::with('plan')->where('user_id', $userId)->whereKey($serviceId ?? 0)
            ->where('status', ServiceStatus::Aktif)
            ->whereHas('plan', fn ($q) => $q->where('kategori', PlanKategori::Ai))->first();

        return $s && isset($s->detail['kredensial']) ? $s : null;
    }

    public function handle(): void
    {
        $p = ChatPercakapan::find($this->percakapanId);
        if (! $p || $p->status !== 'jalan') {
            return;
        }
        $this->p = $p;
        $s = self::layananAi($p->user_id, $p->service_id);
        if (! $s) {
            $this->selesai('Layanan Token AI percakapan ini tidak aktif lagi. Pilih model lain lalu kirim ulang.', galat: true);

            return;
        }
        $kunci = json_decode(Crypt::decryptString($s->detail['kredensial']), true)['api_key'] ?? '';
        $model = $s->plan->spek['model'] ?? '';
        $user = $s->user;

        for (; ;) {
            // 1. alat yang diminta model tapi belum dijalankan (juga lanjutan setelah persetujuan)
            foreach ($this->alatTertunda() as $c) {
                if ($this->dimintaBerhenti()) {
                    $this->selesai('(Dihentikan.)');

                    return;
                }
                $nama = (string) ($c['function']['name'] ?? '');
                $arg = json_decode($c['function']['arguments'] ?? '' ?: '{}', true);
                if (! is_array($arg)) {
                    $this->balasAlat($c, 'Argumen alat bukan JSON yang sah.');

                    continue;
                }
                $ringkas = AlatLayanan::ringkas($nama, $arg);
                if (in_array($nama, AlatLayanan::KONFIRMASI, true) && ! $this->otomatis()) {
                    $keputusan = $this->proses('keputusan');
                    if (($keputusan['id'] ?? null) !== $c['id']) {
                        $this->ubahProses(['menunggu' => ['id' => $c['id'], 'ringkas' => $ringkas]]);
                        $this->p->status = 'menunggu';
                        $this->p->save();

                        return; // dilanjutkan job baru setelah POST chat/{uuid}/izin
                    }
                    if (! $keputusan['ok']) {
                        $this->catatAlat($nama, $ringkas, 'ditolak');
                        $this->balasAlat($c, 'Pelanggan menolak menjalankan aksi ini.');

                        continue;
                    }
                }
                $this->ubahProses(['sedang' => $ringkas], simpan: true);
                $hasil = AlatLayanan::jalankan($user, $nama, $arg);
                $this->catatAlat($nama, $ringkas, 'ok');
                $this->balasAlat($c, $hasil);
            }
            if ($this->dimintaBerhenti()) {
                $this->selesai('(Dihentikan.)');

                return;
            }
            $putaran = (int) $this->proses('putaran');
            if ($putaran > self::BATAS_PUTARAN) {
                $this->selesai('(Dihentikan: terlalu banyak langkah beruntun. Lanjutkan dengan pesan baru bila perlu.)');

                return;
            }

            // 2. minta jawaban model
            try {
                $r = $this->aliran($kunci, $model);
            } catch (Throwable $e) {
                if (! $e instanceof \DomainException) {
                    report($e);
                }
                $this->selesai($e instanceof \DomainException ? $e->getMessage() : 'Gagal menghubungi server AI. Coba lagi.', galat: true);

                return;
            }
            if ($r === null) {
                $this->selesai('(Dihentikan.)');

                return;
            }
            if (! $r['panggilan']) {
                $this->selesai();

                return;
            }
            $konteks = $this->proses('konteks');
            $konteks[] = ['role' => 'assistant', 'content' => $r['teks'] !== '' ? $r['teks'] : null, 'tool_calls' => $r['panggilan']]
                + ($r['nalar'] !== '' ? ['reasoning_content' => $r['nalar']] : []);
            $this->ubahProses(['konteks' => $konteks, 'putaran' => $putaran + 1], simpan: true);
        }
    }

    /** Job mati (timeout/galat tak tertangkap): jangan biarkan percakapan tertahan di status jalan. */
    public function failed(?Throwable $e): void
    {
        $p = ChatPercakapan::find($this->percakapanId);
        if ($p && $p->status) {
            $this->p = $p;
            $this->selesai('Proses AI terhenti di server. Coba lagi.', galat: true);
        }
    }

    /**
     * Satu permintaan streaming ke proxy token AI; teks ditulis bertahap ke pesan terakhir.
     *
     * @return array{teks: string, nalar: string, panggilan: array}|null null = pelanggan menekan Berhenti
     */
    private function aliran(string $kunci, string $model): ?array
    {
        $res = Http::withToken($kunci)->withOptions(['stream' => true])->connectTimeout(15)->timeout(600)
            ->post(rtrim(config('cloud.chat_ai.endpoint'), '/').'/chat/completions', [
                'model' => $model, 'stream' => true,
                'messages' => self::denganGambar($this->proses('konteks')), 'tools' => AlatLayanan::definisi(),
            ]);
        if ($res->failed()) {
            throw new \DomainException($res->json('error.message') ?? "Gagal (HTTP {$res->status()}).");
        }
        $awal = $this->akhir()['content'];
        $awal = $awal !== '' ? "$awal\n\n" : '';
        $body = $res->toPsrResponse()->getBody();
        [$sisa, $teks, $nalar, $panggilan, $tulis, $cek] = ['', '', '', [], 0.0, microtime(true)];
        while (! $body->eof()) {
            $sisa .= $body->read(8192);
            $baris = explode("\n", $sisa);
            $sisa = array_pop($baris);
            foreach ($baris as $b) {
                $data = str_starts_with($b, 'data:') ? trim(substr($b, 5)) : '';
                $delta = $data !== '' && $data !== '[DONE]' ? (json_decode($data, true)['choices'][0]['delta'] ?? null) : null;
                if (! is_array($delta)) {
                    continue; // baris SSE tak utuh/bukan JSON
                }
                $nalar .= $delta['reasoning_content'] ?? '';
                $teks .= $delta['content'] ?? '';
                // tool_calls datang terpotong-potong per index: nama & argumen disambung
                foreach ($delta['tool_calls'] ?? [] as $t) {
                    $c = &$panggilan[$t['index'] ?? 0];
                    $c ??= ['id' => '', 'type' => 'function', 'function' => ['name' => '', 'arguments' => '']];
                    $c['id'] = ($t['id'] ?? '') ?: $c['id'];
                    $c['function']['name'] .= $t['function']['name'] ?? '';
                    $c['function']['arguments'] .= $t['function']['arguments'] ?? '';
                    unset($c);
                }
            }
            $kini = microtime(true);
            if ($teks !== '' && $kini - $tulis >= self::TULIS_TIAP) {
                $this->ubahAkhir(['content' => $awal.$teks]);
                $tulis = $kini;
            }
            if ($kini - $cek >= 1) {
                if ($this->dimintaBerhenti()) {
                    $body->close();
                    if ($teks !== '') {
                        $this->ubahAkhir(['content' => $awal.$teks], simpan: false);
                    }

                    return null;
                }
                $cek = $kini;
            }
        }
        if ($teks !== '') {
            $this->ubahAkhir(['content' => $awal.$teks]);
        }
        ksort($panggilan);

        return ['teks' => $teks, 'nalar' => $nalar, 'panggilan' => array_values(array_filter($panggilan, fn ($c) => $c['function']['name'] !== ''))];
    }

    /** Ganti penanda image_url "lampiran:<path>" dengan data URL base64 dari disk local; berkas hilang → catatan teks. */
    // shortcut: gambar dikirim ukuran asli (maks 10 MB lampiran) tiap putaran; perkecil dengan GD bila request jadi lambat.
    public static function denganGambar(array $konteks): array
    {
        return array_map(function ($m) {
            if (! is_array($m['content'] ?? null)) {
                return $m;
            }
            $m['content'] = array_map(function ($b) {
                $url = $b['image_url']['url'] ?? '';
                if (! str_starts_with($url, 'lampiran:')) {
                    return $b;
                }
                $path = substr($url, 9);
                $disk = Storage::disk('local');

                return $disk->exists($path)
                    ? ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$disk->mimeType($path).';base64,'.base64_encode($disk->get($path))]]
                    : ['type' => 'text', 'text' => '[Gambar lampiran sudah tidak tersedia]'];
            }, $m['content']);

            return $m;
        }, $konteks);
    }

    /** tool_calls pesan assistant terakhir di konteks yang belum punya balasan role=tool. */
    private function alatTertunda(): array
    {
        $konteks = $this->proses('konteks');
        $akhir = collect($konteks)->last(fn ($m) => $m['role'] === 'assistant');
        $sudah = collect($konteks)->where('role', 'tool')->pluck('tool_call_id')->all();

        return array_values(array_filter($akhir['tool_calls'] ?? [], fn ($c) => ! in_array($c['id'], $sudah, true)));
    }

    private function balasAlat(array $c, string $hasil): void
    {
        $konteks = $this->proses('konteks');
        $konteks[] = ['role' => 'tool', 'tool_call_id' => $c['id'], 'content' => $hasil];
        $this->ubahProses(['konteks' => $konteks, 'sedang' => null, 'keputusan' => null], simpan: true);
    }

    private function catatAlat(string $nama, string $ringkas, string $status): void
    {
        $alat = $this->akhir()['alat'] ?? [];
        $alat[] = ['nama' => $nama, 'ringkas' => $ringkas, 'status' => $status];
        $this->ubahAkhir(['alat' => array_slice($alat, -100)], simpan: false);
    }

    /** Tutup giliran: tambahkan catatan (bila ada) ke jawaban, kosongkan proses. */
    private function selesai(string $catatan = '', bool $galat = false): void
    {
        $akhir = $this->akhir();
        $isi = trim($akhir['content'] ?? '');
        if ($galat && $isi === '' && empty($akhir['alat'])) {
            $akhir = ['role' => 'assistant', 'content' => $catatan, 'galat' => true];
        } else {
            $isi = trim($isi.($catatan !== '' ? "\n\n$catatan" : ''));
            if ($isi === '' && empty($akhir['alat'])) {
                $akhir = ['role' => 'assistant', 'content' => 'Model tidak memberi jawaban. Coba lagi.', 'galat' => true];
            } else {
                $akhir['content'] = $isi;
            }
        }
        $pesan = $this->p->pesan;
        $pesan[count($pesan) - 1] = $akhir;
        $this->p->forceFill(['pesan' => $pesan, 'status' => null, 'proses' => null])->save();
    }

    private function dimintaBerhenti(): bool
    {
        return ChatPercakapan::whereKey($this->p->id)->value('status') !== 'jalan'; // stop, atau percakapan dihapus
    }

    private function otomatis(): bool
    {
        return (bool) ChatPercakapan::whereKey($this->p->id)->value('otomatis'); // bisa dicabut pelanggan di tengah jalan
    }

    private function proses(string $k): mixed
    {
        return $this->p->proses[$k] ?? null;
    }

    private function ubahProses(array $ubah, bool $simpan = false): void
    {
        $this->p->proses = array_merge($this->p->proses ?? [], $ubah);
        if ($simpan) {
            $this->p->save();
        }
    }

    private function akhir(): array
    {
        return collect($this->p->pesan)->last() ?? ['role' => 'assistant', 'content' => ''];
    }

    private function ubahAkhir(array $ubah, bool $simpan = true): void
    {
        $pesan = $this->p->pesan;
        $pesan[count($pesan) - 1] = array_merge($this->akhir(), $ubah);
        $this->p->pesan = $pesan;
        if ($simpan) {
            $this->p->save();
        }
    }
}
