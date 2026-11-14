<?php

use App\Models\Invoice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Kode unik tagihan (dibayar = jumlah + kode_unik); kode unik masuk saldo pelanggan saat lunas. Saldo tidak bisa ditarik.
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedSmallInteger('kode_unik')->default(0)->after('jumlah');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('saldo')->default(0);
        });
        Schema::create('saldo_mutasi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('jumlah');
            $table->unsignedInteger('saldo_akhir');
            $table->string('keterangan');
            $table->timestamps();
        });

        // tagihan yang masih terbuka ikut diberi kode unik
        Invoice::where('status', 'belum_dibayar')->where('kode_unik', 0)->each(
            fn (Invoice $i) => $i->update(['kode_unik' => Invoice::kodeUnikBaru($i->jumlah)])
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('saldo_mutasi');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('saldo'));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('kode_unik'));
    }
};
