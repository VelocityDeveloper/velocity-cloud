<?php

namespace App\Services\Pengingat;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;

/**
 * Pengingat untuk pelanggan (hanya baca, tidak mengubah tagihan/layanan):
 * - jatuh_tempo: tagihan perpanjangan belum dibayar, jatuh tempo hari ini s/d H-7
 * - tertunda: tagihan belum dibayar yang lewat batas bayar, atau pesanan/top-up yang belum dibayar
 * - token: saldo token AI layanan aktif di bawah ambang
 */
class DaftarPengingat
{
    /** @return list<array{jenis: string, tingkat: string, judul: string, pesan: string, url: string}> */
    public function untuk(User $user): array
    {
        [$perpanjangan, $pesanan] = $this->tagihan($user);

        // perpanjangan didahulukan agar tidak tergeser pesanan lama yang terbengkalai (dashboard menampilkan 3 teratas)
        return [...$perpanjangan, ...$this->token($user), ...$pesanan];
    }

    /** Hari dari hari ini ke tanggal (negatif = sudah lewat). */
    public static function selisihHari($tanggal): int
    {
        return (int) today()->diffInDays($tanggal, false);
    }

    /** Pesanan/top-up yang lewat batas bayar lebih dari ini tidak diingatkan lagi (dianggap ditinggalkan). */
    private const PESANAN_LEWAT_MAKS_HARI = 14;

    /** @return array{0: list<array>, 1: list<array>} [perpanjangan, pesanan baru/top-up] */
    private function tagihan(User $user): array
    {
        $batasTangguh = (int) config('cloud.tagihan.tangguhkan_hari_sesudah');
        $hasil = [];
        $pesanan = [];

        Invoice::with('service.plan')->where('user_id', $user->id)
            ->where('status', InvoiceStatus::BelumDibayar)
            ->whereHas('service', fn ($q) => $q->whereNotIn('status', [ServiceStatus::Dibatalkan, ServiceStatus::Gagal]))
            ->orderBy('jatuh_tempo')
            ->each(function (Invoice $inv) use (&$hasil, &$pesanan, $batasTangguh) {
                $s = $inv->service;
                $nama = $s->label ?: $s->plan?->nama ?: 'Layanan';
                $tgl = $inv->jatuh_tempo->translatedFormat('j M Y');
                $rp = 'Rp'.number_format($inv->total(), 0, ',', '.');
                $sisa = self::selisihHari($inv->jatuh_tempo);
                $url = route('tagihan.show', $inv, false);
                $perpanjangan = $s->jatuh_tempo && $inv->periode_mulai?->isSameDay($s->jatuh_tempo)
                    && in_array($s->status, [ServiceStatus::Aktif, ServiceStatus::Ditangguhkan], true);

                if ($perpanjangan && $s->status === ServiceStatus::Ditangguhkan) {
                    $hasil[] = $this->item('tertunda', 'bahaya', "$nama ditangguhkan", "Tagihan {$inv->nomor} ($rp) belum dibayar sejak $tgl. Bayar untuk mengaktifkan kembali layanan.", $url);
                } elseif ($perpanjangan && $sisa < 0) {
                    $tangguh = $inv->jatuh_tempo->copy()->addDays($batasTangguh + 1)->translatedFormat('j M Y');
                    $hasil[] = $this->item('tertunda', 'bahaya', "Tagihan $nama lewat jatuh tempo", "Tagihan {$inv->nomor} ($rp) jatuh tempo $tgl. Layanan ditangguhkan mulai $tangguh bila belum dibayar.", $url);
                } elseif ($perpanjangan) {
                    if ($sisa > (int) config('pengingat.hari_sebelum')) {
                        return;
                    }
                    $kapan = $sisa === 0 ? 'hari ini' : "$sisa hari lagi ($tgl)";
                    $hasil[] = $this->item('jatuh_tempo', 'peringatan', "$nama jatuh tempo $kapan", "Bayar tagihan perpanjangan {$inv->nomor} ($rp) agar layanan tidak ditangguhkan.", $url);
                } else {
                    if ($sisa < -self::PESANAN_LEWAT_MAKS_HARI) {
                        return;
                    }
                    $batas = $sisa < 0 ? "batas bayar $tgl sudah lewat" : "batas bayar $tgl";
                    $pesanan[] = $this->item('tertunda', $sisa < 0 ? 'bahaya' : 'peringatan', "Tagihan $nama belum dibayar", "Tagihan {$inv->nomor} ($rp), $batas.", $url);
                }
            });

        return [$hasil, $pesanan];
    }

    private function token(User $user): array
    {
        $ambang = (int) config('pengingat.token_ambang');

        return Service::with(['tokenAi', 'plan'])->where('user_id', $user->id)
            ->where('status', ServiceStatus::Aktif)
            ->whereHas('tokenAi', fn ($q) => $q->where('saldo', '<', $ambang))
            ->whereDoesntHave('invoices', fn ($q) => $q->where('status', InvoiceStatus::BelumDibayar)) // top-up sudah ditagih
            ->get()
            ->map(function (Service $s) {
                $nama = $s->label ?: $s->plan?->nama ?: 'Token AI';
                $saldo = (int) $s->tokenAi->saldo;

                return $saldo <= 0
                    ? $this->item('token', 'bahaya', "Saldo token $nama habis", 'Permintaan API ditolak sampai saldo diisi. Isi ulang di halaman layanan.', route('layanan.show', $s, false))
                    : $this->item('token', 'peringatan', "Saldo token $nama menipis", 'Sisa '.number_format($saldo, 0, ',', '.').' token. Isi ulang agar API tidak berhenti.', route('layanan.show', $s, false));
            })->all();
    }

    private function item(string $jenis, string $tingkat, string $judul, string $pesan, string $url): array
    {
        return compact('jenis', 'tingkat', 'judul', 'pesan', 'url');
    }
}
