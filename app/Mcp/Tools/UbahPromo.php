<?php

namespace App\Mcp\Tools;

use App\Enums\PlanKategori;
use App\Models\AgenCatatan;
use App\Models\Promo;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Buat atau ubah promo bonus token AI di dashboard (Admin › Promo). Kirim id untuk mengubah promo yang sudah ada (field yang tidak dikirim dipertahankan). Perubahan otomatis dicatat di Admin › Agent sebagai tindakan diterapkan. Wajib sertakan alasan.')]
class UbahPromo extends Tool
{
    public function handle(Request $request): Response
    {
        $d = $request->validate([
            'id' => ['nullable', 'integer', 'exists:promos,id'],
            'nama' => ['required_without:id', 'string', 'max:100'],
            'bonus_token_juta' => ['required_without:id', 'integer', 'min:1', 'max:1000'],
            'min_pembelian' => ['required_without:id', 'integer', 'min:0', 'max:100000000'],
            'kategori' => ['required_without:id', 'array', 'min:1'],
            'kategori.*' => [Rule::enum(PlanKategori::class)],
            'mulai' => ['nullable', 'date'],
            'selesai' => ['nullable', 'date', 'after_or_equal:mulai'],
            'aktif' => ['nullable', 'boolean'],
            'alasan' => ['required', 'string', 'max:500'],
            'agen' => ['nullable', 'string', 'max:60'],
        ]);

        $fields = array_intersect_key($d, array_flip(['nama', 'bonus_token_juta', 'min_pembelian', 'kategori', 'mulai', 'selesai', 'aktif']));

        if (! empty($d['id'])) {
            $promo = Promo::findOrFail($d['id']);
            $lama = $promo->only('nama', 'bonus_token_juta', 'min_pembelian', 'kategori', 'aktif', 'mulai', 'selesai');
            $promo->update($fields);
            $baru = $promo->fresh()->only('nama', 'bonus_token_juta', 'min_pembelian', 'kategori', 'aktif', 'mulai', 'selesai');
            $judul = "Ubah promo #{$promo->id}: {$promo->nama}";
            $isi = 'Sebelum: '.json_encode($lama)."\nSesudah: ".json_encode($baru)."\nAlasan: {$d['alasan']}";
        } else {
            $fields['aktif'] ??= true;
            $promo = Promo::create($fields);
            $baru = $promo->only('nama', 'bonus_token_juta', 'min_pembelian', 'kategori', 'aktif', 'mulai', 'selesai');
            $judul = "Tambah promo #{$promo->id}: {$promo->nama}";
            $isi = 'Detail: '.json_encode($baru)."\nAlasan: {$d['alasan']}";
        }

        AgenCatatan::create([
            'jenis' => 'tindakan',
            'status' => 'diterapkan',
            'agen' => $d['agen'] ?? 'Dudung',
            'target' => '/token-ai/',
            'judul' => $judul,
            'isi' => $isi,
        ]);

        return Response::json(['id' => $promo->id, 'promo' => $baru]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('ID promo untuk diubah. Kosongkan untuk membuat promo baru.'),
            'nama' => $schema->string()->description('Nama promo yang tampil di banner katalog.'),
            'bonus_token_juta' => $schema->integer()->description('Bonus saldo token AI dalam satuan juta (1–1000).'),
            'min_pembelian' => $schema->integer()->description('Minimal pembelian dalam Rupiah (tanpa kode unik).'),
            'kategori' => $schema->array()->items($schema->string()->enum(array_column(PlanKategori::cases(), 'value')))->description('Kategori paket yang berhak: vps, managed, app, hosting, ai.'),
            'mulai' => $schema->string()->description('Waktu mulai berlaku ISO/YYYY-MM-DD HH:MM (opsional).'),
            'selesai' => $schema->string()->description('Waktu selesai berlaku (opsional).'),
            'aktif' => $schema->boolean()->description('true = berlaku, false = nonaktif.'),
            'alasan' => $schema->string()->description('Alasan berbasis data, dicatat di Admin › Agent.')->required(),
            'agen' => $schema->string()->description('Nama agen, bawaan Dudung.'),
        ];
    }
}
