<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Node Incus ber-IP publik (matsuba, 25 Nov): host akses SSH/port VPS per node; null = relay global VPS_RELAY_HOST.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->string('vps_host')->nullable()->after('agent_url');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('vps_host');
        });
    }
};
