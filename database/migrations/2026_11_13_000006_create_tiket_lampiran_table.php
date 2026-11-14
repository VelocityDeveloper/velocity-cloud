<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Lampiran pesan tiket (gambar/video/PDF). Berkas di disk local (privat), dibuka lewat rute yang dicek izinnya.
    public function up(): void
    {
        Schema::create('tiket_lampiran', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tiket_pesan_id')->constrained('tiket_pesan')->cascadeOnDelete();
            $table->string('path');
            $table->string('nama');
            $table->string('mime', 100);
            $table->unsignedInteger('ukuran');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiket_lampiran');
    }
};
