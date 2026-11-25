<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** VDC Pay: web lain (mis. app.velocitycybermedia.id) membuat sesi bayar di sini, dibayar lewat Midtrans VDC, hasilnya dikirim webhook. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_klien', function (Blueprint $t) {
            $t->id();
            $t->string('nama', 60); // tampil di halaman bayar
            $t->string('kode', 6)->unique(); // awalan order Midtrans: CS-<kode>-…
            $t->string('kunci_hash', 64)->nullable()->unique(); // sha256 API key; kunci asli hanya tampil sekali
            $t->string('kunci_akhir', 4)->nullable();
            $t->text('rahasia_webhook')->nullable(); // terenkripsi (Crypt)
            $t->string('url_webhook');
            $t->json('host_kembali'); // host yang boleh dipakai url_sukses/url_batal
            $t->boolean('aktif')->default(true);
            $t->timestamps();
        });

        Schema::create('checkout_sesi', function (Blueprint $t) {
            $t->id();
            $t->string('uuid', 40)->unique(); // id publik (cs_…), dipakai di URL halaman bayar & API
            $t->foreignId('checkout_klien_id')->constrained('checkout_klien')->cascadeOnDelete();
            $t->string('ref', 64); // nomor order di web klien
            $t->unsignedInteger('jumlah');
            $t->string('deskripsi', 120);
            $t->string('nama', 60)->nullable();
            $t->string('email');
            $t->string('url_sukses', 500);
            $t->string('url_batal', 500)->nullable();
            $t->json('metadata')->nullable();
            $t->string('status', 20)->index();
            $t->dateTime('berlaku_sampai'); // batas mulai membayar; transaksi Midtrans yang sudah dibuat ikut kedaluwarsanya sendiri
            $t->json('midtrans')->nullable(); // {order, url, sampai} transaksi Snap terakhir
            $t->string('order_lunas', 50)->nullable();
            $t->string('referensi', 100)->nullable(); // payment_type + transaction_id
            $t->dateTime('dibayar_pada')->nullable();
            $t->timestamps();
            $t->unique(['checkout_klien_id', 'ref']);
        });

        Schema::create('checkout_webhook', function (Blueprint $t) {
            $t->id();
            $t->string('event_id', 40)->unique(); // evt_…, klien memakainya untuk menolak kiriman ganda
            $t->foreignId('checkout_sesi_id')->constrained('checkout_sesi')->cascadeOnDelete();
            $t->string('jenis', 40);
            $t->json('payload');
            $t->string('status', 20)->default('antri'); // antri | terkirim | gagal
            $t->unsignedSmallInteger('percobaan')->default(0);
            $t->unsignedSmallInteger('kode_respons')->nullable();
            $t->string('galat', 500)->nullable();
            $t->dateTime('terkirim_pada')->nullable();
            $t->timestamps();
            $t->unique(['checkout_sesi_id', 'jenis']); // satu kejadian per perubahan status
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_webhook');
        Schema::dropIfExists('checkout_sesi');
        Schema::dropIfExists('checkout_klien');
    }
};
