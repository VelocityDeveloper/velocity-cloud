<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\AgenCatatan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dipanggil server bot (bot.velocity.ai.id, mesin yang sama) saat masalah atau review dibuka di grup dan saat ditandai fix.
 * Origin tunnel juga 127.0.0.1, jadi wajib token bersama (cloud.agen_diskusi_token).
 */
class AgenDiskusiController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) config('cloud.agen_diskusi_token');
        abort_unless($token !== '' && hash_equals($token, (string) $request->bearerToken()), 403);

        $d = $request->validate([
            'id' => ['nullable', 'integer'],
            'judul' => ['required_without:id', 'string', 'max:200'],
            'isi' => ['required_without:id', 'string', 'max:20000'],
            'agen' => ['nullable', 'string', 'max:60'],
            'url' => ['nullable', 'url', 'max:300'],
            'status' => ['required', 'in:terbuka,fix'],
            'ringkasan' => ['nullable', 'string', 'max:5000'],
            'terkait' => ['nullable', 'integer'],
        ]);

        if (! empty($d['id'])) {
            $c = AgenCatatan::whereIn('jenis', ['masalah', 'review'])->findOrFail($d['id']);
            $c->update(array_filter(['status' => $d['status'], 'ringkasan' => $d['ringkasan'] ?? null, 'diskusi_url' => $d['url'] ?? null], fn ($v) => $v !== null));

            return response()->json(['id' => $c->id]);
        }

        $jenis = preg_match('/^review\b/i', trim($d['judul'])) ? 'review' : 'masalah';
        $terkait = ! empty($d['terkait']) && AgenCatatan::whereKey($d['terkait'])->exists() ? "\n\nTerkait catatan #{$d['terkait']}." : '';
        $c = AgenCatatan::create([
            'jenis' => $jenis,
            'status' => $d['status'],
            'judul' => $d['judul'],
            'isi' => $d['isi'].$terkait,
            'agen' => $d['agen'] ?? 'Dudung',
            'diskusi_url' => $d['url'] ?? null,
        ]);

        return response()->json(['id' => $c->id], 201);
    }
}
