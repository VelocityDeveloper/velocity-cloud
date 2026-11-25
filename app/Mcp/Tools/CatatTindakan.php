<?php

namespace App\Mcp\Tools;

use App\Models\AgenCatatan;
use App\Services\Agen\MetrikHalaman;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Catat satu tindakan perbaikan (ubah halaman/artikel/SEO, usulan ke admin, atau pembatalan) agar tampil di Admin › Agent. Untuk perubahan landing, sertakan hash commit git, hipotesis, dan metrik yang diharapkan naik di isi. Tindakan diterapkan dengan target path (/…/) otomatis menyimpan metrik awal 7 hari agar bisa dievaluasi. Kirim id untuk memperbarui status tindakan lama.')]
class CatatTindakan extends Tool
{
    public function handle(Request $request, MetrikHalaman $metrik): Response
    {
        $d = $request->validate([
            'id' => ['nullable', 'integer', 'exists:agen_catatan,id'],
            'judul' => ['required_without:id', 'string', 'max:200'],
            'isi' => ['required_without:id', 'string', 'max:20000'],
            'status' => ['required', 'in:'.implode(',', AgenCatatan::STATUS)],
            'target' => ['nullable', 'string', 'max:300'],
            'agen' => ['nullable', 'string', 'max:60'],
        ]);

        if (isset($d['id'])) {
            $c = AgenCatatan::where('jenis', 'tindakan')->findOrFail($d['id']);
            $c->update(array_filter(['status' => $d['status'], 'judul' => $d['judul'] ?? null, 'isi' => $d['isi'] ?? null]));
            if ($c->status === 'diterapkan' && ! $c->metrik_awal && self::halaman($c->target)) {
                $c->update(['metrik_awal' => $metrik->ambil(self::halaman($c->target))]); // usulan yang baru diterapkan
            }

            return Response::text("Tindakan #{$c->id} diperbarui: {$c->status}.");
        }
        $awal = $d['status'] === 'diterapkan' && self::halaman($d['target'] ?? null) ? $metrik->ambil(self::halaman($d['target'])) : null;
        $c = AgenCatatan::create(['jenis' => 'tindakan', 'agen' => $d['agen'] ?? 'Dudung', 'metrik_awal' => $awal, ...array_intersect_key($d, array_flip(['judul', 'isi', 'status', 'target']))]);

        return Response::text("Tindakan #{$c->id} tercatat ({$c->status})".($awal ? ", metrik awal {$awal['path']} disimpan; dievaluasi mulai ".now()->addDays(AgenCatatan::HARI_EVALUASI)->toDateString().'.' : '. Tanpa target path landing: tidak bisa dievaluasi otomatis.'));
    }

    /** Path landing dari target (mis. "/app/n8n/" atau "https://velocitydatacenter.com/app/n8n/"); null bila bukan halaman. */
    private static function halaman(?string $target): ?string
    {
        $path = $target ? (parse_url($target, PHP_URL_PATH) ?? '') : '';

        return preg_match('#^/[a-z0-9/_-]*$#', $path) ? $path : null;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Isi untuk memperbarui tindakan yang sudah ada.'),
            'judul' => $schema->string()->description('Wajib untuk tindakan baru.'),
            'isi' => $schema->string()->description('Apa yang diubah, kenapa (data), commit git, cara ukur besok.'),
            'status' => $schema->string()->enum(AgenCatatan::STATUS)->description('usulan = menunggu persetujuan admin; diterapkan; dibatalkan (rollback). disetujui/ditolak diisi admin di dashboard.')->required(),
            'target' => $schema->string()->description('Path halaman landing yang diubah, mis. /app/n8n/ (wajib agar bisa dievaluasi). Paket: slug paket.'),
            'agen' => $schema->string(),
        ];
    }
}
