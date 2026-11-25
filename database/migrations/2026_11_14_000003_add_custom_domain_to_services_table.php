<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('custom_domain')->nullable()->unique();
            $table->timestamp('custom_domain_aktif_at')->nullable(); // DNS sudah menunjuk ke relay (khusus app)
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['custom_domain', 'custom_domain_aktif_at']);
        });
    }
};
