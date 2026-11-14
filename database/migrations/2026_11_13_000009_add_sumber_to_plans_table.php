<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sumber (upstream) token AI per paket: {url, key, model}, terenkripsi APP_KEY; dibaca juga oleh /opt/velocity-ai-proxy.
        Schema::table('plans', fn (Blueprint $t) => $t->text('sumber')->nullable());
    }

    public function down(): void
    {
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn('sumber'));
    }
};
