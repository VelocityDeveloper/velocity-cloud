<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Analitik token AI di /admin/paket/ai: cache hit, durasi, usage taksiran, dan request gagal (status != 200, total 0, saldo tidak dipotong).
    public function up(): void
    {
        Schema::table('token_ai_pemakaian', function (Blueprint $table) {
            $table->unsignedInteger('cache_hit')->default(0)->after('prompt');
            $table->unsignedInteger('durasi_ms')->default(0);
            $table->boolean('taksiran')->default(false);
            $table->unsignedSmallInteger('status')->default(200);
            $table->index(['token_ai_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('token_ai_pemakaian', function (Blueprint $table) {
            $table->dropIndex(['token_ai_id', 'created_at']);
            $table->dropColumn(['cache_hit', 'durasi_ms', 'taksiran', 'status']);
        });
    }
};
