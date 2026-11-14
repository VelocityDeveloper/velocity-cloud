<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Masalah yang didiskusikan tim bot di grup bot.velocity.ai.id: jenis "masalah", status terbuka|fix, tautan percakapan grup.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agen_catatan', function (Blueprint $table) {
            $table->string('diskusi_url', 300)->nullable();
            $table->text('ringkasan')->nullable(); // ringkasan penyelesaian saat ditandai fix
        });
    }

    public function down(): void
    {
        Schema::table('agen_catatan', fn (Blueprint $t) => $t->dropColumn(['diskusi_url', 'ringkasan']));
    }
};
