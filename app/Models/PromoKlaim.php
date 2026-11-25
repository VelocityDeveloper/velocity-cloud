<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromoKlaim extends Model
{
    protected $table = 'promo_klaim';

    protected $guarded = ['id'];

    public function promo(): BelongsTo
    {
        return $this->belongsTo(Promo::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function tokenService(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'token_service_id');
    }
}
