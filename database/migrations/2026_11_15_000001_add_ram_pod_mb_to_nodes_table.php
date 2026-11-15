<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Kapasitas node pod berdasarkan RAM (#16): jumlah limit RAM pod tidak boleh melebihi ram_pod_mb; null = hanya batas jumlah pod.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->unsignedInteger('ram_pod_mb')->nullable()->after('kapasitas_pod');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('ram_pod_mb');
        });
    }
};
