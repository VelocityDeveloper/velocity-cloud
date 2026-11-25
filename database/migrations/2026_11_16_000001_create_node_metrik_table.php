<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Sampel mesin node per menit (metrik:ambil), untuk halaman Admin › Node › detail; disimpan 7 hari. */
    public function up(): void
    {
        Schema::create('node_metrik', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('waktu'); // epoch detik
            $table->float('cpu')->nullable(); // persen seluruh inti, dari selisih penghitung dengan sampel sebelumnya
            $table->unsignedBigInteger('cpu_total')->nullable(); // penghitung kumulatif jiffies /proc/stat
            $table->unsignedBigInteger('cpu_idle')->nullable();
            $table->unsignedSmallInteger('inti')->nullable();
            $table->unsignedBigInteger('ram')->nullable();
            $table->unsignedBigInteger('ram_total')->nullable();
            $table->unsignedBigInteger('disk')->nullable();
            $table->unsignedBigInteger('disk_total')->nullable();
            $table->float('load')->nullable(); // load average 1 menit
            $table->unsignedInteger('uptime')->nullable();
            $table->unsignedBigInteger('rx')->nullable(); // penghitung kumulatif byte antarmuka fisik
            $table->unsignedBigInteger('tx')->nullable();
            $table->index(['node_id', 'waktu']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_metrik');
    }
};
