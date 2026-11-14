<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('kategori', 20)->index();
            $table->string('slug')->unique();
            $table->string('nama');
            $table->unsignedInteger('harga')->nullable(); // null = harga custom, pesan lewat konsultasi
            $table->json('spek')->nullable();
            $table->json('fitur')->nullable();
            $table->text('ringkas')->nullable();
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->boolean('aktif')->default(true);
            $table->timestamps();
        });

        Schema::create('nodes', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('agent_url');
            $table->text('token'); // terenkripsi lewat cast
            $table->unsignedSmallInteger('kapasitas_pod')->default(20);
            $table->boolean('aktif')->default(true);
            $table->timestamps();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('node_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label');
            $table->string('subdomain')->nullable()->unique();
            $table->string('status', 30)->index();
            $table->unsignedInteger('harga');
            $table->date('jatuh_tempo')->nullable();
            $table->json('detail')->nullable(); // info akses: IP, user, URL pod, catatan admin
            $table->text('pesan_gagal')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('nomor')->unique();
            $table->unsignedInteger('jumlah');
            $table->string('status', 20)->index();
            $table->date('periode_mulai');
            $table->date('periode_selesai');
            $table->date('jatuh_tempo');
            $table->timestamp('dibayar_pada')->nullable();
            $table->string('metode')->nullable();
            $table->string('referensi')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('services');
        Schema::dropIfExists('nodes');
        Schema::dropIfExists('plans');
    }
};
