<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_metrik', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('waktu'); // epoch detik
            $table->float('cpu')->nullable(); // persen dari seluruh vCPU
            $table->unsignedBigInteger('cpu_ns')->nullable(); // penghitung kumulatif, untuk menghitung cpu sampel berikutnya
            $table->unsignedBigInteger('ram')->nullable();
            $table->unsignedBigInteger('ram_total')->nullable();
            $table->unsignedInteger('proses')->nullable();
            $table->unsignedBigInteger('rx')->nullable(); // penghitung kumulatif byte
            $table->unsignedBigInteger('tx')->nullable();
            $table->index(['service_id', 'waktu']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_metrik');
    }
};
