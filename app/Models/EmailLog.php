<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailLog extends Model
{
    public const JENIS = [
        \Illuminate\Auth\Notifications\VerifyEmail::class => 'Verifikasi email',
        \Illuminate\Auth\Notifications\ResetPassword::class => 'Reset sandi',
        \App\Notifications\TiketDibalas::class => 'Balasan tiket',
        \App\Notifications\PengingatJatuhTempo::class => 'Pengingat tagihan',
        'manual' => 'Manual',
    ];

    protected $table = 'email_log';

    protected $guarded = ['id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Kelas anonim (mis. email bonus dari tinker) tampil "Notifikasi lain". */
    public function jenisLabel(): string
    {
        return self::JENIS[$this->jenis] ?? (str_contains($this->jenis, '@anonymous') ? 'Notifikasi lain' : class_basename($this->jenis));
    }
}
