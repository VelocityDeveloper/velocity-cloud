<?php

namespace App\Mcp\Tools;

use App\Models\AgenCatatan;
use App\Services\Agen\MetrikHalaman;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Simpan penilaian tindakan yang sudah diterapkan (dari daftar tindakan_perlu_evaluasi di mulai-siklus). Metrik kini diambil otomatis dengan jendela yang sama panjang dengan metrik awal. Setelah menilai, perbarui pelajaran lewat catat-pelajaran.')]
class EvaluasiTindakan extends Tool
{
    public function handle(Request $request, MetrikHalaman $metrik): Response
    {
        $d = $request->validate([
            'id' => ['required', 'integer'],
            'putusan' => ['required', 'in:'.implode(',', AgenCatatan::PUTUSAN)],
            'catatan' => ['required', 'string', 'max:2000'],
        ]);
        $c = AgenCatatan::where('jenis', 'tindakan')->where('status', 'diterapkan')->whereNotNull('metrik_awal')->find($d['id']);
        if (! $c) {
            return Response::error("Tindakan #{$d['id']} tidak ada, belum diterapkan, atau tidak punya metrik awal.");
        }
        $awal = $c->metrik_awal;
        $hari = max(1, (int) round((strtotime($awal['sampai']) - strtotime($awal['dari'])) / 86400) + 1);
        $c->update([
            'hasil' => ['putusan' => $d['putusan'], 'catatan' => $d['catatan'], 'metrik' => $metrik->ambil($awal['path'], $hari)],
            'dievaluasi_pada' => now(),
        ]);

        return Response::text("Tindakan #{$c->id} dinilai {$d['putusan']}. Sekarang perbarui/buat pelajaran terkait dengan catat-pelajaran (bukti: {$c->id}).");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
            'putusan' => $schema->string()->enum(AgenCatatan::PUTUSAN)->description('berhasil: metrik utama naik ≥20% dengan data cukup; gagal: turun ≥20%; netral: lainnya atau data kurang setelah 21 hari.')->required(),
            'catatan' => $schema->string()->description('Angka awal → kini dan alasannya, singkat.')->required(),
        ];
    }
}
