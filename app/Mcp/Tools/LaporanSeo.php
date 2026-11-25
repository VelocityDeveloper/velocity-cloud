<?php

namespace App\Mcp\Tools;

use App\Services\Seo\LaporanSeo as Seo;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Google Search Console velocitydatacenter.com (landing pindah dari velocitydeveloper.ai.id 18 Nov 2026): klik, tayangan, CTR, posisi vs periode lalu, kata kunci & halaman teratas (25 baris). Data Google terlambat 2-3 hari.')]
class LaporanSeo extends Tool
{
    public function handle(Request $request, Seo $seo): Response
    {
        $periode = $request->validate(['periode' => ['nullable', 'in:'.implode(',', array_keys(Seo::PERIODE))]])['periode'] ?? '28h';
        $l = $seo->semua($periode);
        if ($l['status'] !== 'ok') {
            return Response::error('Search Console: '.$l['status'].(isset($l['galat']) ? ' - '.$l['galat'] : ''));
        }
        unset($l['tren_lalu']);
        foreach (['kueri', 'halaman'] as $k) {
            $l[$k] = array_slice($l[$k], 0, 25);
        }

        return Response::json($l);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['periode' => $schema->string()->enum(array_keys(Seo::PERIODE))->description('Bawaan 28h.')];
    }
}
