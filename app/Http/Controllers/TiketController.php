<?php

namespace App\Http\Controllers;

use App\Models\Tiket;
use App\Models\TiketLampiran;
use App\Models\TiketPesan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Support ticket. Pelanggan hanya melihat tiketnya sendiri; admin boleh membuka, membalas, dan menutup semua tiket. */
class TiketController extends Controller
{
    // Tanpa SVG/HTML: berkas dibuka inline di domain app, jenis yang bisa memuat skrip ditolak.
    private const LAMPIRAN = ['nullable', 'array', 'max:3'];

    private const LAMPIRAN_BERKAS = ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,gif,pdf,mp4,webm,mov', 'mimetypes:image/jpeg,image/png,image/webp,image/gif,application/pdf,video/mp4,video/webm,video/quicktime'];

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('tiket/Index', [
            'tiket' => $user->tiket()->with('service')->latest('updated_at')->get()->map->tampil(),
            'jenis' => Tiket::JENIS,
            'layanan' => $user->services()->whereNot('status', 'dibatalkan')->latest()->get(['id', 'label']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'jenis' => ['required', Rule::in(array_keys(Tiket::JENIS))],
            'judul' => ['required', 'string', 'max:120'],
            'service_id' => ['nullable', 'integer', Rule::exists('services', 'id')->where('user_id', $user->id)],
            'isi' => ['required', 'string', 'max:5000'],
            'lampiran' => self::LAMPIRAN,
            'lampiran.*' => self::LAMPIRAN_BERKAS,
        ]);

        $tiket = DB::transaction(function () use ($request, $user, $data) {
            $tiket = $user->tiket()->create(['jenis' => $data['jenis'], 'judul' => $data['judul'], 'service_id' => $data['service_id'] ?? null]);
            $this->simpanLampiran($request, $tiket->pesan()->create(['user_id' => $user->id, 'isi' => $data['isi']]));

            return $tiket;
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tiket dikirim. Tim kami akan membalas di sini.']);

        return to_route('tiket.show', $tiket);
    }

    public function show(Request $request, Tiket $tiket): Response
    {
        $this->izinkan($request, $tiket);

        return Inertia::render('tiket/Show', [
            'tiket' => $tiket->load('service', 'user')->tampil(),
            'pesan' => $tiket->pesan()->with('user:id,name', 'lampiran')->get()->map(fn ($p) => [
                'id' => $p->id,
                'isi' => $p->isi,
                'dari_admin' => $p->dari_admin,
                'nama' => $p->dari_admin ? 'Tim Velocity' : $p->user->name,
                'waktu' => $p->created_at->toDateTimeString(),
                'lampiran' => $p->lampiran->map(fn ($l) => [
                    'id' => $l->id,
                    'nama' => $l->nama,
                    'mime' => $l->mime,
                    'ukuran' => $l->ukuran,
                    'url' => route('tiket.lampiran', [$tiket, $l]),
                ]),
            ]),
        ]);
    }

    public function balas(Request $request, Tiket $tiket): RedirectResponse
    {
        $this->izinkan($request, $tiket);
        $data = $request->validate([
            'isi' => ['required', 'string', 'max:5000'],
            'lampiran' => self::LAMPIRAN,
            'lampiran.*' => self::LAMPIRAN_BERKAS,
        ]);
        // admin membalas tiket pelanggan lain = jawaban tim; selain itu = pesan pelanggan (membuka lagi tiket yang ditutup)
        $dariAdmin = $request->user()->is_admin && $tiket->user_id !== $request->user()->id;

        DB::transaction(function () use ($request, $tiket, $data, $dariAdmin) {
            $this->simpanLampiran($request, $tiket->pesan()->create(['user_id' => $request->user()->id, 'dari_admin' => $dariAdmin, 'isi' => $data['isi']]));
            $tiket->update(['status' => $dariAdmin ? 'dijawab' : 'terbuka']);
        });

        return back();
    }

    public function tutup(Request $request, Tiket $tiket): RedirectResponse
    {
        $this->izinkan($request, $tiket);
        $tiket->update(['status' => 'ditutup']);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tiket ditutup.']);

        return back();
    }

    public function lampiran(Request $request, Tiket $tiket, TiketLampiran $lampiran): StreamedResponse
    {
        $this->izinkan($request, $tiket);
        abort_unless($lampiran->pesan->tiket_id === $tiket->id, 404);

        return Storage::disk('local')->response($lampiran->path, $lampiran->nama, [
            'Content-Type' => $lampiran->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'",
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    private function simpanLampiran(Request $request, TiketPesan $pesan): void
    {
        foreach ($request->file('lampiran', []) as $berkas) {
            $pesan->lampiran()->create([
                'path' => $berkas->store('tiket/'.$pesan->tiket_id, 'local'),
                'nama' => mb_substr($berkas->getClientOriginalName(), 0, 200),
                'mime' => $berkas->getMimeType(),
                'ukuran' => $berkas->getSize(),
            ]);
        }
    }

    private function izinkan(Request $request, Tiket $tiket): void
    {
        abort_unless($tiket->user_id === $request->user()->id || $request->user()->is_admin, 404);
    }
}
