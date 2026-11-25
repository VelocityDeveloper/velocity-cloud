<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promos', function (Blueprint $t) {
            // paket token AI tujuan bonus; null = layanan token milik pelanggan (perilaku lama)
            $t->foreignId('plan_id')->nullable()->after('kategori')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('promos', function (Blueprint $t) {
            $t->dropConstrainedForeignId('plan_id');
        });
    }
};
