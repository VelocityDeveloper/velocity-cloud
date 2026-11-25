<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Email masuk support@ jadi tiket (tiket:ambil-email): pengirim bisa belum punya akun → user_id boleh kosong.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tiket', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('email_pengirim')->nullable()->after('user_id'); // isi = tiket dari email; balasan admin dikirim ke sini
            $table->string('nama_pengirim')->nullable()->after('email_pengirim');
        });
        Schema::table('tiket_pesan', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('email_id')->nullable()->unique()->after('isi'); // Message-ID: cegah dobel + rangkai balasan
        });
    }

    public function down(): void
    {
        Schema::table('tiket_pesan', function (Blueprint $table) {
            $table->dropUnique(['email_id']);
            $table->dropColumn('email_id');
        });
        Schema::table('tiket', function (Blueprint $table) {
            $table->dropColumn(['email_pengirim', 'nama_pengirim']);
        });
    }
};
