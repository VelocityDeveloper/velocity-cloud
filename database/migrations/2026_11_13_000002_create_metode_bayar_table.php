<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Metode pembayaran manual yang diatur admin (/admin/pembayaran): QRIS (gambar), rekening bank, e-wallet, lainnya.
    public function up(): void
    {
        Schema::create('metode_bayar', function (Blueprint $table) {
            $table->id();
            $table->string('jenis', 20);
            $table->string('nama', 60);
            $table->string('nomor', 60)->nullable();
            $table->string('atas_nama', 100)->nullable();
            $table->string('gambar')->nullable();
            $table->text('instruksi')->nullable();
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->boolean('aktif')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metode_bayar');
    }
};
