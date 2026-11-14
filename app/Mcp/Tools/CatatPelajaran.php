<?php

namespace App\Mcp\Tools;

use App\Models\AgenCatatan;
use App\Models\AgenPelajaran;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Kelola playbook pelajaran. Baru: judul + isi (pola yang bisa diulang, mis. "Judul halaman app yang memuat harga menaikkan CTR") + bukti (id tindakan). Perbarui: id + skor_delta (+1 dikonfirmasi tindakan baru, -1 dibantah) + bukti tambahan; skor ≤ -2 otomatis usang. Jangan buat pelajaran tanpa bukti tindakan yang sudah dievaluasi.')]
class CatatPelajaran extends Tool
{
    public function handle(Request $request): Response
    {
        $d = $request->validate([
            'id' => ['nullable', 'integer', 'exists:agen_pelajaran,id'],
            'judul' => ['required_without:id', 'string', 'max:200'],
            'isi' => ['required_without:id', 'string', 'max:3000'],
            'bukti' => ['required', 'array', 'min:1', 'max:20'],
            'bukti.*' => ['integer'],
            'skor_delta' => ['nullable', 'integer', 'min:-2', 'max:2'],
            'usang' => ['nullable', 'boolean'],
        ]);
        $tidakSah = array_diff($d['bukti'], AgenCatatan::whereIn('id', $d['bukti'])->whereNotNull('dievaluasi_pada')->pluck('id')->all());
        if ($tidakSah) {
            return Response::error('Bukti harus tindakan yang sudah dievaluasi: '.implode(', ', $tidakSah).'.');
        }

        if (isset($d['id'])) {
            $p = AgenPelajaran::findOrFail($d['id']);
            $skor = $p->skor + ($d['skor_delta'] ?? 0);
            $p->update(array_filter([
                'judul' => $d['judul'] ?? null, 'isi' => $d['isi'] ?? null, 'skor' => $skor,
                'bukti' => array_values(array_unique([...($p->bukti ?? []), ...$d['bukti']])),
                'status' => ($d['usang'] ?? false) || $skor <= AgenPelajaran::BATAS_USANG ? 'usang' : 'aktif',
            ], fn ($v) => $v !== null));

            return Response::text("Pelajaran #{$p->id} diperbarui: skor {$p->skor}, {$p->status}.");
        }
        $p = AgenPelajaran::create(['judul' => $d['judul'], 'isi' => $d['isi'], 'bukti' => $d['bukti'], 'skor' => 1 + max(0, $d['skor_delta'] ?? 0)]);

        return Response::text("Pelajaran #{$p->id} tercatat (skor {$p->skor}).");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Isi untuk memperbarui pelajaran yang ada.'),
            'judul' => $schema->string(),
            'isi' => $schema->string()->description('Pola, kapan berlaku, dan batasannya.'),
            'bukti' => $schema->array()->items($schema->integer())->description('Id tindakan yang sudah dievaluasi.')->required(),
            'skor_delta' => $schema->integer()->description('+1/+2 dikonfirmasi, -1/-2 dibantah.'),
            'usang' => $schema->boolean()->description('true bila pola tidak berlaku lagi.'),
        ];
    }
}
