<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TiketPesan extends Model
{
    protected $table = 'tiket_pesan';

    protected $guarded = ['id'];

    protected $touches = ['tiket'];

    protected function casts(): array
    {
        return ['dari_admin' => 'boolean'];
    }

    public function tiket(): BelongsTo
    {
        return $this->belongsTo(Tiket::class);
    }

    public function lampiran(): HasMany
    {
        return $this->hasMany(TiketLampiran::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
