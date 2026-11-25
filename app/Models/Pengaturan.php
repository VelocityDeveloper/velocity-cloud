<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Pengaturan kunci → nilai (JSON) yang diubah admin dari dashboard, mis. midtrans.server_key (terenkripsi). */
class Pengaturan extends Model
{
    protected $table = 'pengaturan';

    protected $primaryKey = 'kunci';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public static function ambil(string $kunci, mixed $bawaan = null): mixed
    {
        $nilai = static::find($kunci)?->nilai;

        return $nilai === null ? $bawaan : json_decode($nilai, true);
    }

    /** OS VPS yang dijual: config cloud.vps.os disaring pilihan admin (bawaan = yang sudah teruji boot). */
    public static function osVps(): array
    {
        $aktif = static::ambil('vps.os_aktif', ['ubuntu-24.04', 'debian-12']);

        return array_intersect_key(config('cloud.vps.os'), array_flip($aktif));
    }

    /** TLD yang dijual di Pesan Domain (diatur Admin › Paket › Domain). Bawaan kosong = belum ada yang dijual. */
    public static function tldDomain(): array
    {
        return static::ambil('domain.tld_aktif', []);
    }

    public static function simpan(string $kunci, mixed $nilai): void
    {
        static::updateOrCreate(['kunci' => $kunci], ['nilai' => json_encode($nilai)]);
    }
}
