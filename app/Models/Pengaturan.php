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

    public static function simpan(string $kunci, mixed $nilai): void
    {
        static::updateOrCreate(['kunci' => $kunci], ['nilai' => json_encode($nilai)]);
    }
}
