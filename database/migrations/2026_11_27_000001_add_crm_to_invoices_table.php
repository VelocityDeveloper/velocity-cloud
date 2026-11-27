<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tagihan lunas Midtrans → billing + bank midtrans_vd di CRM new-vdnet (KirimTagihanCrm). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('crm_billing_id')->nullable();
            $table->unsignedBigInteger('crm_bank_id')->nullable();
            $table->timestamp('crm_terkirim_pada')->nullable();
            $table->string('crm_galat', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['crm_billing_id', 'crm_bank_id', 'crm_terkirim_pada', 'crm_galat']);
        });
    }
};
