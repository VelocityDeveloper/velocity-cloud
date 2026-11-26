<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** Notifikasi di lonceng dashboard (mis. bonus token AI). Bonus manual dari tinker: $user->notify(new Pemberitahuan('bonus', 'Judul', 'Isi', '/layanan/13')). */
class Pemberitahuan extends Notification
{
    public function __construct(public string $jenis, public string $judul, public string $isi, public ?string $url = null, public ?string $kiriman = null) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        // kiriman = id siaran admin (Admin › Notifikasi), untuk riwayat & tarik kembali
        return array_filter(['jenis' => $this->jenis, 'judul' => $this->judul, 'isi' => $this->isi, 'url' => $this->url, 'kiriman' => $this->kiriman], fn ($v) => $v !== null);
    }
}
