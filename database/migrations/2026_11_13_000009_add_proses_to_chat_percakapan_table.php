<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Chat AI berjalan di server (job JalankanChat): browser cukup memantau, aman pindah halaman. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_percakapan', function (Blueprint $table) {
            $table->string('status', 10)->nullable(); // null = diam, jalan, menunggu (persetujuan alat), stop (diminta berhenti)
            $table->boolean('otomatis')->default(false); // "Jalankan untuk sesi ini": alat berisiko tanpa bertanya
            $table->json('proses')->nullable(); // konteks API putaran berjalan, alat yang sedang/menunggu
        });
    }

    public function down(): void
    {
        Schema::table('chat_percakapan', function (Blueprint $table) {
            $table->dropColumn(['status', 'otomatis', 'proses']);
        });
    }
};
