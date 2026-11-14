<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Playbook agen: pola yang terbukti (skor naik tiap konfirmasi, turun tiap bantahan; ≤ -2 = usang). */
class AgenPelajaran extends Model
{
    protected $table = 'agen_pelajaran';

    protected $guarded = ['id'];

    protected $casts = ['bukti' => 'array'];

    public const BATAS_USANG = -2;
}
