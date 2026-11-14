<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Satu baris = satu hit dari landing: pv (tampilan halaman), eg (waktu aktif + scroll), ev (event klik).
        Schema::create('analitik_hits', function (Blueprint $table) {
            $table->id();
            $table->dateTime('waktu')->index(); // waktu lokal Asia/Jakarta
            $table->string('jenis', 2);
            $table->string('vid', 24);
            $table->string('sid', 24)->index();
            $table->string('pid', 24);
            $table->string('path', 200);
            $table->string('judul', 150)->nullable();
            $table->string('referrer', 300)->nullable();
            $table->string('sumber', 100)->nullable();
            $table->string('medium', 50)->nullable();
            $table->string('kampanye', 100)->nullable();
            $table->string('negara', 2)->nullable();
            $table->string('kota', 60)->nullable();
            $table->string('perangkat', 10)->nullable();
            $table->string('browser', 30)->nullable();
            $table->string('os', 30)->nullable();
            $table->string('layar', 11)->nullable();
            $table->string('bahasa', 10)->nullable();
            $table->string('event', 30)->nullable();
            $table->string('label', 150)->nullable();
            $table->unsignedInteger('durasi_ms')->nullable();
            $table->unsignedTinyInteger('scroll')->nullable();
            $table->boolean('baru')->default(false);
            $table->boolean('awal_sesi')->default(false);
            $table->boolean('masuk')->default(false);
            $table->boolean('internal')->default(false);
            $table->index(['jenis', 'waktu']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analitik_hits');
    }
};
