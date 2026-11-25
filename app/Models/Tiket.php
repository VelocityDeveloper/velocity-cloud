<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tiket extends Model
{
    public const JENIS = ['billing' => 'Billing', 'support' => 'Support'];

    public const STATUS = ['terbuka' => 'Menunggu tim', 'dijawab' => 'Dijawab', 'ditutup' => 'Ditutup'];

    protected $table = 'tiket';

    protected $guarded = ['id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function pesan(): HasMany
    {
        return $this->hasMany(TiketPesan::class)->orderBy('id');
    }

    public function tampil(): array
    {
        return [
            'id' => $this->id,
            'judul' => $this->judul,
            'jenis' => $this->jenis,
            'jenis_label' => self::JENIS[$this->jenis] ?? $this->jenis,
            'status' => $this->status,
            'status_label' => self::STATUS[$this->status] ?? $this->status,
            'layanan' => $this->service?->label,
            'pelanggan' => $this->user?->name ?? $this->nama_pengirim ?? $this->email_pengirim,
            'email' => $this->user?->email ?? $this->email_pengirim,
            'dari_email' => $this->email_pengirim !== null,
            'diperbarui' => $this->updated_at->toDateTimeString(),
        ];
    }
}
