<?php

namespace App\Services\Provisioning;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * Langkah pertama app setelah pod jalan (buat owner n8n, instal WordPress), lewat URL publik app.
 * Dijalankan sebelum layanan ditandai aktif, agar wizard tidak sempat diklaim orang lain.
 * shortcut: retry job setelah langkah sukses akan gagal (owner sudah ada); tambahkan penanda per langkah kalau ini terjadi.
 */
class SiapkanApp
{
    /** @param array{tunggu: string, langkah: list<array{path: string, json?: array, form?: array, cocok?: string}>} $siapkan */
    public static function jalankan(string $url, array $siapkan, int $batasDetik = 240, int $jedaDetik = 3): void
    {
        $http = fn () => Http::baseUrl($url)->timeout(30)->withUserAgent('VelocityCloud-Provisioning');

        $sampai = time() + $batasDetik;
        while (! rescue(fn () => $http()->get($siapkan['tunggu'])->successful(), false, report: false)) {
            if (time() >= $sampai) {
                throw new RuntimeException("App belum siap setelah {$batasDetik} detik ({$siapkan['tunggu']}).");
            }
            Sleep::for($jedaDetik)->seconds();
        }

        foreach ($siapkan['langkah'] as $l) {
            $r = isset($l['form']) ? $http()->asForm()->post($l['path'], $l['form']) : $http()->post($l['path'], $l['json'] ?? []);
            if (! $r->successful() || (isset($l['cocok']) && ! str_contains($r->body(), $l['cocok']))) {
                throw new RuntimeException("Langkah {$l['path']} gagal: HTTP {$r->status()} ".mb_substr(strip_tags($r->body()), 0, 300));
            }
        }
    }
}
