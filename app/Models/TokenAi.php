<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TokenAi extends Model
{
    protected $table = 'token_ai';

    protected $guarded = ['id'];

    protected $hidden = ['kunci_hash'];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function pemakaian(): HasMany
    {
        return $this->hasMany(TokenAiPemakaian::class);
    }
}
