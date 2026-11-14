<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedInteger('token_juta')->nullable(); // isi = tagihan beli/top-up token AI (juta token)
        });

        // Saldo token AI per layanan. Proxy /opt/velocity-ai-proxy membaca kunci & memotong saldo langsung di tabel ini.
        Schema::create('token_ai', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('kunci_hash', 64)->unique(); // sha256 hex API key
            $table->bigInteger('saldo')->default(0); // token; bisa sedikit minus karena dipotong sesudah request selesai
            $table->unsignedBigInteger('terpakai')->default(0);
            $table->timestamps();
        });

        Schema::create('token_ai_pemakaian', function (Blueprint $table) {
            $table->id();
            $table->foreignId('token_ai_id')->constrained('token_ai')->cascadeOnDelete();
            $table->string('model', 100);
            $table->unsignedInteger('prompt')->default(0);
            $table->unsignedInteger('completion')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_ai_pemakaian');
        Schema::dropIfExists('token_ai');
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('token_juta'));
    }
};
