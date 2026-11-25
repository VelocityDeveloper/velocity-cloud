<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promos', function (Blueprint $t) {
            $t->id();
            $t->string('nama');
            $t->unsignedInteger('bonus_token_juta');
            $t->unsignedInteger('min_pembelian')->default(0);
            $t->json('kategori'); // nilai PlanKategori yang ikut promo
            $t->dateTime('mulai')->nullable();
            $t->dateTime('selesai')->nullable(); // null = tanpa batas
            $t->boolean('aktif')->default(true);
            $t->timestamps();
        });

        Schema::create('promo_klaim', function (Blueprint $t) {
            $t->id();
            $t->foreignId('promo_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $t->foreignId('token_service_id')->nullable()->constrained('services')->nullOnDelete();
            $t->unsignedInteger('token_juta');
            $t->unsignedInteger('nominal'); // jumlah tagihan (tanpa kode unik) yang memicu bonus
            $t->timestamps();
            $t->unique(['promo_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_klaim');
        Schema::dropIfExists('promos');
    }
};
