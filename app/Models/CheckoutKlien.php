<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/** Web lain yang memakai halaman bayar VDC Pay (API key + rahasia webhook). */
class CheckoutKlien extends Model
{
    protected $table = 'checkout_klien';

    protected $guarded = ['id'];

    protected $hidden = ['kunci_hash', 'rahasia_webhook'];

    public const AWALAN_KUNCI = 'vdcpay_';

    protected function casts(): array
    {
        return ['host_kembali' => 'array', 'aktif' => 'boolean'];
    }

    /** @return HasMany<CheckoutSesi, $this> */
    public function sesi(): HasMany
    {
        return $this->hasMany(CheckoutSesi::class);
    }

    public static function dariKunci(string $kunci): ?self
    {
        return str_starts_with($kunci, self::AWALAN_KUNCI)
            ? static::where('kunci_hash', hash('sha256', $kunci))->where('aktif', true)->first()
            : null;
    }

    /**
     * Buat API key & rahasia webhook baru (yang lama langsung tidak berlaku).
     *
     * @return array{api_key: string, rahasia_webhook: string} hanya ditampilkan sekali ke admin
     */
    public function kunciBaru(): array
    {
        $kunci = self::AWALAN_KUNCI.Str::random(40);
        $rahasia = 'whsec_'.Str::random(40);
        $this->forceFill([
            'kunci_hash' => hash('sha256', $kunci),
            'kunci_akhir' => substr($kunci, -4),
            'rahasia_webhook' => Crypt::encryptString($rahasia),
        ])->save();

        return ['api_key' => $kunci, 'rahasia_webhook' => $rahasia];
    }

    public function rahasia(): string
    {
        return $this->rahasia_webhook ? Crypt::decryptString($this->rahasia_webhook) : '';
    }

    /** url_sukses/url_batal wajib https dan host-nya terdaftar (cegah halaman bayar dipakai untuk open redirect). */
    public function urlBoleh(string $url): bool
    {
        $p = parse_url($url);

        return ($p['scheme'] ?? null) === 'https' && isset($p['host'])
            && in_array(strtolower($p['host']), array_map('strtolower', $this->host_kembali ?? []), true);
    }
}
