<?php

namespace App\Notifications;

use App\Models\Invoice;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Email pengingat tagihan perpanjangan H-7/H-3/H-1 (dikirim pengingat:email; nonaktif bawaan). */
class PengingatJatuhTempo extends Notification
{
    public function __construct(public Invoice $invoice, public int $sisaHari) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $inv = $this->invoice;
        $s = $inv->service;
        $nama = $s->label ?: $s->plan?->nama ?: 'Layanan';
        $tgl = $inv->jatuh_tempo->translatedFormat('j F Y');
        $rp = 'Rp'.number_format($inv->total(), 0, ',', '.');
        $kapan = $this->sisaHari === 1 ? 'besok' : "{$this->sisaHari} hari lagi";
        $tangguh = $inv->jatuh_tempo->copy()->addDays((int) config('cloud.tagihan.tangguhkan_hari_sesudah') + 1)->translatedFormat('j F Y');

        return (new MailMessage)
            ->subject("Tagihan $nama jatuh tempo $kapan")
            ->greeting('Halo '.$notifiable->name.',')
            ->line("Tagihan perpanjangan {$inv->nomor} untuk $nama sebesar $rp jatuh tempo $tgl.")
            ->line("Bila belum dibayar, layanan ditangguhkan mulai $tangguh.")
            ->action('Bayar tagihan', route('tagihan.show', $inv))
            ->line('Abaikan email ini bila tagihan sudah dibayar.');
    }
}
