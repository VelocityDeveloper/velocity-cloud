<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Node DirectAdmin (mis. sanke) untuk app PHP berbasis akun hosting: agent_url = URL panel DA, token = login key, da_user = pemilik key.
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->string('jenis', 20)->default('pod')->after('nama');
            $table->string('da_user', 60)->nullable()->after('agent_url');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['jenis', 'da_user']);
        });
    }
};
