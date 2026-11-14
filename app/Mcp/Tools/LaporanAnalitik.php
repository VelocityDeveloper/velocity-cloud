<?php

namespace App\Mcp\Tools;

use App\Services\Analitik\Laporan;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Analitik pengunjung situs velocitydeveloper.ai.id (tanpa trafik kantor): ringkasan vs periode lalu, sumber, halaman, landing, event klik Pesan/WA/Daftar, perangkat, negara. 10 baris teratas per tabel.')]
class LaporanAnalitik extends Tool
{
    public function handle(Request $request): Response
    {
        $periode = $request->validate(['periode' => ['nullable', 'in:'.implode(',', array_keys(Laporan::PERIODE))]])['periode'] ?? '7h';
        $l = (new Laporan(tanpaInternal: true))->semua($periode);
        $atas = fn (string $k) => array_slice($l[$k] ?? [], 0, 10);

        return Response::json([
            'periode' => $periode, 'dari' => $l['dari'], 'sampai' => $l['sampai'],
            'ringkasan' => $l['ringkasan'], 'ringkasan_lalu' => $l['ringkasan_lalu'],
            ...collect(['sumber', 'kampanye', 'referrer', 'halaman', 'halaman_landing', 'halaman_keluar', 'event', 'perangkat', 'negara'])
                ->mapWithKeys(fn ($k) => [$k => $atas($k)])->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['periode' => $schema->string()->enum(array_keys(Laporan::PERIODE))->description('Bawaan 7h.')];
    }
}
