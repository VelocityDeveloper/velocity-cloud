<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Harga input/cache/output terpisah (23 Nov 2026): saldo dipotong dalam token setara output, berbeda dari total token mentah.
    public function up(): void
    {
        Schema::table('token_ai_pemakaian', function (Blueprint $table) {
            $table->unsignedBigInteger('potong')->nullable()->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('token_ai_pemakaian', function (Blueprint $table) {
            $table->dropColumn('potong');
        });
    }
};
