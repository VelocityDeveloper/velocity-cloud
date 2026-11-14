<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgenCatatan extends Model
{
    protected $table = 'agen_catatan';

    protected $guarded = ['id'];

    protected $casts = ['metrik_awal' => 'array', 'hasil' => 'array', 'dievaluasi_pada' => 'datetime'];

    /** Tindakan diterapkan dinilai setelah sekian hari (cukup data analitik & GSC). */
    public const HARI_EVALUASI = 7;

    public const PUTUSAN = ['berhasil', 'netral', 'gagal'];

    /** usulan → admin: disetujui/ditolak → agen menerapkan → diterapkan; dibatalkan = di-rollback. */
    public const STATUS = ['usulan', 'disetujui', 'ditolak', 'diterapkan', 'dibatalkan'];
}
