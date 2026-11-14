<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laporan & tindakan yang dicatat agen (bot Dudung) lewat MCP, tampil di Admin › Agent.
        Schema::create('agen_catatan', function (Blueprint $table) {
            $table->id();
            $table->string('jenis', 20)->index(); // laporan | tindakan
            $table->string('judul', 200);
            $table->text('isi');
            $table->string('status', 20)->nullable(); // tindakan: usulan | diterapkan | dibatalkan
            $table->string('target', 300)->nullable(); // halaman/paket yang disentuh
            $table->string('agen', 60);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agen_catatan');
    }
};
