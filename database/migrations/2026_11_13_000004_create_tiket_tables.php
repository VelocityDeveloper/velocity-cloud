<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Support ticket pengganti "Bantuan via WhatsApp": status terbuka (menunggu tim) | dijawab (menunggu pelanggan) | ditutup.
    public function up(): void
    {
        Schema::create('tiket', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('judul', 120);
            $table->string('status', 20)->default('terbuka')->index();
            $table->timestamps();
        });
        Schema::create('tiket_pesan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tiket_id')->constrained('tiket')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('dari_admin')->default(false);
            $table->text('isi');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiket_pesan');
        Schema::dropIfExists('tiket');
    }
};
