<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class MetodeBayar extends Model
{
    public const JENIS = ['qris' => 'QRIS', 'bank' => 'Transfer bank', 'ewallet' => 'E-wallet', 'lainnya' => 'Lainnya'];

    protected $table = 'metode_bayar';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['aktif' => 'boolean'];
    }

    public function scopeAktif(Builder $q): Builder
    {
        return $q->where('aktif', true)->orderBy('urutan')->orderBy('id');
    }

    public function tampil(): array
    {
        return [
            ...$this->only(['id', 'jenis', 'nama', 'nomor', 'atas_nama', 'instruksi', 'urutan', 'aktif']),
            'gambar_url' => $this->gambar ? Storage::disk('public')->url($this->gambar) : null,
        ];
    }
}
