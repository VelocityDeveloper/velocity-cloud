<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Jenis tiket: billing (konfirmasi bayar, tagihan) | support (kendala layanan, komplain, lainnya).
    public function up(): void
    {
        Schema::table('tiket', function (Blueprint $table) {
            $table->string('jenis', 20)->default('support')->after('service_id');
        });
    }

    public function down(): void
    {
        Schema::table('tiket', fn (Blueprint $t) => $t->dropColumn('jenis'));
    }
};
