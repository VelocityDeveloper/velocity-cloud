<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Paket custom khusus klien: tidak tampil di katalog/landing, tetap bisa dipesan lewat link langsung atau CRM.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', fn (Blueprint $t) => $t->boolean('tersembunyi')->default(false));
    }

    public function down(): void
    {
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn('tersembunyi'));
    }
};
