<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu kejadian (lunas/kedaluwarsa/…) yang dikirim ke webhook klien; dicatat dulu baru dikirim lewat antrean. */
class CheckoutWebhook extends Model
{
    protected $table = 'checkout_webhook';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'terkirim_pada' => 'datetime'];
    }

    /** @return BelongsTo<CheckoutSesi, $this> */
    public function sesi(): BelongsTo
    {
        return $this->belongsTo(CheckoutSesi::class, 'checkout_sesi_id');
    }
}
