<?php

namespace App\Notifications;

use App\Models\TiketPesan;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;

/** Balasan admin untuk tiket yang dibuat dari email: dikirim ke pengirim, berangkai (In-Reply-To) agar balasan pelanggan masuk tiket yang sama. */
class TiketDibalas extends Notification
{
    public function __construct(public TiketPesan $pesan, public string $messageId, public ?string $rujukan) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tiket = $this->pesan->tiket;
        $mail = (new MailMessage)
            ->subject('Re: '.$tiket->judul.' [Tiket #'.$tiket->id.']')
            ->greeting('Halo '.($tiket->user?->name ?? $tiket->nama_pengirim ?? '').',');
        foreach (preg_split('/\R{2,}/', trim($this->pesan->isi)) as $paragraf) {
            $mail->line($paragraf);
        }
        foreach ($this->pesan->lampiran as $l) {
            $mail->attachData(Storage::disk('local')->get($l->path), $l->nama, ['mime' => $l->mime]);
        }

        return $mail->line('Balas email ini untuk menanggapi.')
            ->salutation("Salam,\n\nTim Velocity Data Center")
            ->withSymfonyMessage(function (Email $m) {
                $h = $m->getHeaders();
                $h->addIdHeader('Message-ID', trim($this->messageId, '<>'));
                if ($this->rujukan) {
                    $h->addIdHeader('In-Reply-To', trim($this->rujukan, '<>'));
                    $h->addIdHeader('References', trim($this->rujukan, '<>'));
                }
            });
    }
}
