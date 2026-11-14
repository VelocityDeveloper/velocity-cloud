<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TiketLampiran extends Model
{
    protected $table = 'tiket_lampiran';

    protected $guarded = ['id'];

    public function pesan(): BelongsTo
    {
        return $this->belongsTo(TiketPesan::class, 'tiket_pesan_id');
    }
}
