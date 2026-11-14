<?php

namespace App\Mcp\Tools;

use App\Actions\Katalog\UbahPaket as Ubah;
use App\Models\AgenCatatan;
use App\Models\Plan;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Ubah harga/bulan, status dijual (aktif), atau stok satu paket. Field yang tidak dikirim tetap. Harga & aktif ikut memperbarui situs (build ±5 dtk). Perubahan otomatis dicatat sebagai tindakan di Admin › Agent. Wajib sertakan alasan.')]
class UbahPaket extends Tool
{
    public function handle(Request $request, Ubah $ubah): Response
    {
        $d = $request->validate([
            'slug' => ['required', 'string', 'exists:plans,slug'],
            'harga' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'harga_hubungi_kami' => ['nullable', 'boolean'],
            'aktif' => ['nullable', 'boolean'],
            'stok' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'stok_tanpa_batas' => ['nullable', 'boolean'],
            'alasan' => ['required', 'string', 'max:500'],
        ]);
        $plan = Plan::where('slug', $d['slug'])->sole();
        $lama = $plan->only('harga', 'aktif', 'stok');

        $harga = ! empty($d['harga_hubungi_kami']) ? null : ($d['harga'] ?? $plan->harga);
        $stok = ! empty($d['stok_tanpa_batas']) ? null : ($d['stok'] ?? $plan->stok);
        $situs = $ubah->handle($plan, $harga, $d['aktif'] ?? $plan->aktif, $stok);
        $baru = $plan->fresh()->only('harga', 'aktif', 'stok');

        AgenCatatan::create([
            'jenis' => 'tindakan', 'status' => 'diterapkan', 'agen' => 'mcp', 'target' => "paket {$plan->slug}",
            'judul' => "Ubah paket {$plan->nama}",
            'isi' => "Sebelum: ".json_encode($lama)."\nSesudah: ".json_encode($baru)."\nAlasan: {$d['alasan']}",
        ]);

        return Response::json(['paket' => $plan->slug, 'sebelum' => $lama, 'sesudah' => $baru, 'situs_diperbarui' => $situs]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()->description('Slug paket dari daftar_paket, mis. vps-cloud-1, app-n8n.')->required(),
            'harga' => $schema->integer()->description('Harga baru per bulan (Rupiah).'),
            'harga_hubungi_kami' => $schema->boolean()->description('true = tanpa harga ("Hubungi kami").'),
            'aktif' => $schema->boolean()->description('false = berhenti dijual (hilang dari situs & katalog).'),
            'stok' => $schema->integer()->description('Batas layanan memakai paket ini; 0 = tutup pesanan.'),
            'stok_tanpa_batas' => $schema->boolean()->description('true = hapus batas stok.'),
            'alasan' => $schema->string()->description('Alasan berbasis data, tampil di Admin › Agent.')->required(),
        ];
    }
}
