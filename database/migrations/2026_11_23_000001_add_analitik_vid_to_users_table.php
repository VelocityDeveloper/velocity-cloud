<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // vid pengunjung landing (cookie vc_vid) saat daftar → tautan ke analitik_hits.vid
            $table->string('analitik_vid', 32)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['analitik_vid']);
            $table->dropColumn('analitik_vid');
        });
    }
};
