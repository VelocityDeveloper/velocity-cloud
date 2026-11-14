<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Siklus belajar agen: tindakan menyimpan metrik awal & hasil evaluasi; pelajaran = playbook berskor dari bukti.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agen_catatan', function (Blueprint $table) {
            $table->json('metrik_awal')->nullable();
            $table->json('hasil')->nullable(); // {putusan: berhasil|netral|gagal, catatan, metrik}
            $table->timestamp('dievaluasi_pada')->nullable();
        });

        Schema::create('agen_pelajaran', function (Blueprint $table) {
            $table->id();
            $table->string('judul', 200);
            $table->text('isi');
            $table->json('bukti')->nullable(); // id tindakan pendukung/pembantah
            $table->integer('skor')->default(1);
            $table->string('status', 10)->default('aktif'); // aktif | usang
            $table->string('agen', 60)->default('Dudung');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agen_pelajaran');
        Schema::table('agen_catatan', fn (Blueprint $t) => $t->dropColumn(['metrik_awal', 'hasil', 'dievaluasi_pada']));
    }
};
