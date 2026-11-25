<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Riwayat email keluar (Admin › Support › Email). Isi email tidak disimpan: email reset sandi memuat token rahasia.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ke');
            $table->string('subjek');
            $table->string('jenis'); // kelas notifikasi/mailable, atau "manual"
            $table->string('status')->default('gagal'); // gagal = dicatat saat mulai kirim, belum dikonfirmasi terkirim
            $table->string('message_id')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_log');
    }
};
