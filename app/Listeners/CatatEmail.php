<?php

namespace App\Listeners;

use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Symfony\Component\Mime\Address;

/** Catat tiap email keluar: baris dibuat saat mulai kirim (status gagal), diperbarui jadi terkirim bila transport sukses. */
class CatatEmail
{
    /** @var array<int, int> spl_object_id(pesan) → id email_log */
    private static array $baris = [];

    public function sending(MessageSending $e): void
    {
        $ke = collect($e->message->getTo())->map(fn (Address $a) => $a->getAddress())->implode(', ');
        $jenis = $e->data['__laravel_notification'] ?? $e->data['__laravel_mailable'] ?? 'manual';
        $log = EmailLog::create([
            'user_id' => User::whereRaw('lower(email) = ?', [strtolower(explode(', ', $ke)[0])])->value('id'),
            'ke' => mb_substr($ke, 0, 255),
            'subjek' => mb_substr((string) $e->message->getSubject(), 0, 255),
            'jenis' => mb_substr($jenis, 0, 255),
        ]);
        self::$baris[spl_object_id($e->message)] = $log->id;
    }

    public function sent(MessageSent $e): void
    {
        $id = self::$baris[spl_object_id($e->sent->getOriginalMessage())] ?? null;
        if ($id) {
            EmailLog::whereKey($id)->update(['status' => 'terkirim', 'message_id' => $e->sent->getMessageId()]);
            unset(self::$baris[spl_object_id($e->sent->getOriginalMessage())]);
        }
    }
}
