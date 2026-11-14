<?php

namespace App\Http\Controllers;

use App\Enums\PlanKategori;
use App\Enums\ServiceStatus;
use App\Jobs\JalankanChat;
use App\Models\ChatPercakapan;
use App\Services\Chat\AlatLayanan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Chat AI pelanggan: giliran AI berjalan di server (job JalankanChat), browser memantau status; aman pindah halaman. */
class ChatController extends Controller
{
    public function index(Request $request, ?string $uuid = null): Response
    {
        $aktif = $uuid ? $this->milik($request, $uuid) : null;

        return Inertia::render('chat/Index', [
            'kunci' => $this->kunci($request),
            'daftar' => $request->user()->hasMany(ChatPercakapan::class)->latest('updated_at')->limit(200)
                ->get(['uuid', 'judul', 'status', 'updated_at'])->map(fn ($p) => self::ringkas($p)),
            'aktif' => $aktif ? ['uuid' => $aktif->uuid, 'service_id' => $aktif->service_id, 'pesan' => $aktif->pesan] + self::keadaan($aktif) : null,
        ]);
    }

    /** Kirim pesan (atau ulangi pesan terakhir): giliran AI dijalankan job JalankanChat di server. */
    public function kirim(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'integer'],
            'teks' => ['required_without_all:ulang,lampiran', 'nullable', 'string', 'max:20000'],
            'ulang' => ['sometimes', 'boolean'],
            'lampiran' => TiketController::LAMPIRAN,
            'lampiran.*' => TiketController::LAMPIRAN_BERKAS,
        ]);
        $p = ChatPercakapan::firstWhere('uuid', $uuid);
        abort_if($p && $p->user_id !== $request->user()->id, 404);
        // menunggu persetujuan boleh ditinggal dengan pesan baru; "jalan" macet >40 mnt (worker mati) dianggap selesai
        abort_if($p && in_array($p->status, ['jalan', 'stop'], true) && $p->updated_at->gt(now()->subMinutes(40)), 409, 'AI masih memproses pesan sebelumnya.');
        abort_unless(JalankanChat::layananAi($request->user()->id, $data['service_id']), 422, 'Model tidak aktif. Pilih model lain.');

        $riwayat = array_values(array_filter($p->pesan ?? [], fn ($m) => empty($m['galat'])));
        if (empty($data['ulang'])) {
            $riwayat[] = ['role' => 'user', 'content' => trim($data['teks'] ?? '')]
                + ($request->hasFile('lampiran') ? ['lampiran' => $this->simpanLampiran($request, $uuid)] : []);
        }
        abort_unless((end($riwayat)['role'] ?? null) === 'user', 422, 'Tidak ada pesan untuk dikirim.');

        $p ??= new ChatPercakapan(['uuid' => $uuid, 'user_id' => $request->user()->id]);
        $p->fill([
            'service_id' => $data['service_id'],
            'judul' => Str::limit(preg_replace('/\s+/', ' ', ($u = collect($riwayat)->firstWhere('role', 'user'))['content'] ?: ($u['lampiran'][0]['nama'] ?? '')), 60, ''),
            'pesan' => [...array_slice($riwayat, -200), ['role' => 'assistant', 'content' => '']],
            'status' => 'jalan',
            'proses' => ['putaran' => 0, 'konteks' => [
                ['role' => 'system', 'content' => JalankanChat::SISTEM],
                ...array_map(fn ($m) => ['role' => $m['role'], 'content' => self::isiModel($m)], array_slice($riwayat, -JalankanChat::BATAS_KONTEKS)),
            ]],
        ])->save();
        JalankanChat::dispatch($p->id);

        // pengguna = pesan user dari server (lampiran sudah ber-URL) untuk mengganti salinan sementara di browser
        return response()->json($this->data($request, $p->fresh()) + ['percakapan' => self::ringkas($p->fresh()), 'pengguna' => end($riwayat)]);
    }

    /** Simpan lampiran ke disk local chat/{uuid}; URL bertanda tangan (tanpa login) agar WP-CLI/curl di layanan bisa mengunduhnya. */
    private function simpanLampiran(Request $request, string $uuid): array
    {
        return array_map(function ($f) use ($uuid) {
            $berkas = basename($f->store("chat/$uuid", 'local'));

            return [
                'nama' => Str::limit($f->getClientOriginalName(), 120, ''),
                'mime' => $f->getMimeType(),
                'ukuran' => $f->getSize(),
                // relatif: app di belakang tunnel (skema http di server), tanda tangan dicek tanpa skema/host
                'url' => URL::signedRoute('chat.lampiran', ['uuid' => $uuid, 'berkas' => $berkas], absolute: false),
            ];
        }, $request->file('lampiran'));
    }

    /**
     * Isi pesan untuk model: lampiran ditulis sebagai URL publik; gambar juga dikirim sebagai image_url
     * berisi penanda lampiran:<path disk> yang diganti data URL oleh JalankanChat saat request (base64 tidak disimpan di DB).
     */
    private static function isiModel(array $m): string|array
    {
        $isi = $m['content'];
        $gambar = [];
        foreach ($m['lampiran'] ?? [] as $l) {
            $isi .= "\n[Lampiran: {$l['nama']} ({$l['mime']}, ".round($l['ukuran'] / 1024).' KB) '.rtrim(config('app.url'), '/').$l['url'].']';
            if (in_array($l['mime'], JalankanChat::MIME_GAMBAR, true)) {
                // url = /chat-lampiran/{uuid}/{berkas}?signature=… → path disk chat/{uuid}/{berkas}
                $gambar[] = ['type' => 'image_url', 'image_url' => ['url' => 'lampiran:chat/'.Str::after(parse_url($l['url'], PHP_URL_PATH), '/chat-lampiran/')]];
            }
        }

        return $gambar ? [['type' => 'text', 'text' => ltrim($isi)], ...$gambar] : ltrim($isi);
    }

    public function lampiran(string $uuid, string $berkas): StreamedResponse
    {
        $path = "chat/$uuid/$berkas";
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'",
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /** Dipantau browser tiap detik selama AI berjalan. */
    public function status(Request $request, string $uuid): JsonResponse
    {
        return response()->json($this->data($request, $this->milik($request, $uuid)));
    }

    private function data(Request $request, ChatPercakapan $p): array
    {
        return ['jumlah' => count($p->pesan), 'akhir' => collect($p->pesan)->last()] + self::keadaan($p)
            + ($p->status ? [] : ['kunci' => $this->kunci($request)]); // saldo terbaru setelah selesai
    }

    /** Jawaban kartu persetujuan alat berisiko; sesi = "Jalankan untuk sesi ini" (percakapan ini tak ditanya lagi). */
    public function izin(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['jalankan' => ['required', 'boolean'], 'sesi' => ['sometimes', 'boolean']]);
        $p = $this->milik($request, $uuid);
        abort_unless($p->status === 'menunggu' && isset($p->proses['menunggu']), 409, 'Tidak ada aksi yang menunggu persetujuan.');
        $p->forceFill([
            'status' => 'jalan',
            'otomatis' => $p->otomatis || ($data['jalankan'] && ! empty($data['sesi'])),
            'proses' => ['keputusan' => ['id' => $p->proses['menunggu']['id'], 'ok' => $data['jalankan']], 'menunggu' => null] + $p->proses,
        ])->save();
        JalankanChat::dispatch($p->id);

        return $this->status($request, $uuid);
    }

    /** "Minta persetujuan lagi": cabut izin otomatis (berlaku juga untuk alat berikutnya di giliran yang sedang jalan). */
    public function otomatis(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['nyala' => ['required', 'boolean']]);
        $this->milik($request, $uuid)->forceFill(['otomatis' => $data['nyala']])->save();

        return $this->status($request, $uuid);
    }

    public function berhenti(Request $request, string $uuid): JsonResponse
    {
        $p = $this->milik($request, $uuid);
        if ($p->status === 'jalan') {
            $p->forceFill(['status' => 'stop'])->save(); // job berhenti di pemeriksaan berikutnya (≤1 dtk saat streaming)
        } elseif ($p->status === 'menunggu' || $p->status === 'stop') {
            $pesan = $p->pesan;
            $akhir = array_pop($pesan);
            $akhir['content'] = trim(($akhir['content'] ?? '')."\n\n(Dihentikan.)");
            $p->forceFill(['pesan' => [...$pesan, $akhir], 'status' => null, 'proses' => null])->save();
        }

        return $this->status($request, $uuid);
    }

    /** Jalankan alat yang diminta model (lihat AlatLayanan); hasil teks dikirim browser kembali ke model. */
    public function alat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:50'],
            'argumen' => ['present', 'array'],
        ]);

        return response()->json(['hasil' => AlatLayanan::jalankan($request->user(), $data['nama'], $data['argumen'])]);
    }

    public function hapus(Request $request, string $uuid): RedirectResponse
    {
        $this->milik($request, $uuid)->delete();
        Storage::disk('local')->deleteDirectory("chat/$uuid");
        $kembali = $request->input('kembali'); // percakapan yang sedang dibuka saat menghapus percakapan lain

        return is_string($kembali) && $kembali !== $uuid && Str::isUuid($kembali) ? to_route('chat', ['uuid' => $kembali]) : to_route('chat');
    }

    private function milik(Request $request, string $uuid): ChatPercakapan
    {
        return ChatPercakapan::where('user_id', $request->user()->id)->where('uuid', $uuid)->firstOrFail();
    }

    /** Keadaan giliran AI untuk tampilan: status, alat yang sedang jalan, kartu persetujuan. */
    private static function keadaan(ChatPercakapan $p): array
    {
        return ['status' => $p->status, 'otomatis' => $p->otomatis, 'sedang' => $p->proses['sedang'] ?? null,
            'menunggu' => $p->status === 'menunggu' ? ($p->proses['menunggu']['ringkas'] ?? null) : null];
    }

    private static function ringkas(ChatPercakapan $p): array
    {
        return ['uuid' => $p->uuid, 'judul' => $p->judul, 'status' => $p->status, 'waktu' => $p->updated_at?->toIso8601String()];
    }

    /** Layanan token AI aktif milik user = daftar model yang bisa dipilih. */
    private function kunci(Request $request): Collection
    {
        return $request->user()->services()
            ->where('status', ServiceStatus::Aktif)
            ->whereHas('plan', fn ($q) => $q->where('kategori', PlanKategori::Ai))
            ->with(['plan', 'tokenAi'])->latest()->get()
            ->filter(fn ($s) => isset($s->detail['kredensial']))
            ->map(fn ($s) => [
                'id' => $s->id,
                'label' => $s->label,
                'nama' => $s->plan->nama,
                'model' => $s->plan->spek['model'] ?? null,
                'saldo' => (int) ($s->tokenAi?->saldo ?? 0),
            ])->values();
    }
}
