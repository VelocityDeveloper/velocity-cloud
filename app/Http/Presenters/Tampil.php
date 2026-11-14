<?php

namespace App\Http\Presenters;

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Service;
use Illuminate\Support\Facades\Crypt;

/** Bentuk data yang dikirim ke halaman Vue. Satu tempat agar semua halaman konsisten. */
class Tampil
{
    public static function plan(Plan $p): array
    {
        return [
            'id' => $p->id,
            'slug' => $p->slug,
            'kategori' => $p->kategori->value,
            'nama' => $p->nama,
            'harga' => $p->harga,
            'spek' => $p->spek,
            'fitur' => $p->fitur,
            'ringkas' => $p->ringkas,
            'bisa_dipesan' => $p->bisaDipesan(),
            'habis' => $p->sisaStok() === 0,
            'vps_otomatis' => $p->vpsOtomatis(),
        ];
    }

    public static function layanan(Service $s, bool $lengkap = false): array
    {
        $data = [
            'id' => $s->id,
            'label' => $s->label,
            'paket' => $s->plan->nama,
            'kategori' => $s->plan->kategori->value,
            'kategori_label' => $s->plan->kategori->label(),
            'status' => $s->status->value,
            'status_label' => $s->status->label(),
            'harga' => $s->harga,
            'jatuh_tempo' => $s->jatuh_tempo?->toDateString(),
            'url' => $s->detail['url'] ?? null,
        ];

        if ($lengkap) {
            $detail = $s->detail ?? [];
            $data += [
                'subdomain' => $s->subdomain,
                'spek' => $s->plan->spek,
                'is_app' => $s->isApp(),
                'otomatis' => $s->isApp() || $s->plan->vpsOtomatis(),
                'kontrol' => (bool) $s->bisaKontrol(),
                'pairing' => (bool) ($s->plan->pod()['pairing'] ?? false),
                'host' => $detail['host'] ?? null,
                'host_port' => $detail['host_port'] ?? null,
                'akses' => $detail['akses'] ?? null, // diisi admin untuk VPS/managed
                'kredensial' => isset($detail['kredensial']) ? json_decode(Crypt::decryptString($detail['kredensial']), true) : null,
                'pesan_gagal' => $s->pesan_gagal,
            ];
        }

        return $data;
    }

    public static function tagihan(Invoice $i): array
    {
        return [
            'id' => $i->id,
            'nomor' => $i->nomor,
            'layanan' => $i->service->label,
            'jumlah' => $i->jumlah,
            'kode_unik' => $i->kode_unik,
            'total' => $i->total(),
            'status' => $i->status->value,
            'status_label' => $i->status->label(),
            'periode' => $i->token_juta ? number_format($i->token_juta, 0, ',', '.').' juta token' : $i->periode_mulai->toDateString().' s/d '.$i->periode_selesai->toDateString(),
            'jatuh_tempo' => $i->jatuh_tempo->toDateString(),
            'dibayar_pada' => $i->dibayar_pada?->toDateTimeString(),
            'metode' => $i->metode,
        ];
    }
}
