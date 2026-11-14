<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatPercakapan extends Model
{
    protected $table = 'chat_percakapan';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['pesan' => 'array', 'proses' => 'array', 'otomatis' => 'boolean'];
    }
}
