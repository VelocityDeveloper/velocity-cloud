<?php

// Pengingat pelanggan (25 Nov 2026). Nilai & logika tagihan tetap di app/Actions/Billing; berkas ini hanya mengatur pemberitahuan.
return [
    // Pemberitahuan di dashboard: tagihan perpanjangan muncul mulai H-7 (sama dengan tagihan:proses).
    'hari_sebelum' => 7,

    // Saldo token AI dianggap menipis bila kurang dari ini (token setara output; beli minimal 1 juta).
    'token_ambang' => (int) env('PENGINGAT_TOKEN_AMBANG', 100_000),

    // Email H-7/H-3/H-1 jatuh tempo: SIAP tapi NONAKTIF sampai Pemilik memilih saluran (perlu SMTP, MAIL_MAILER sekarang log).
    'email' => [
        'aktif' => (bool) env('PENGINGAT_EMAIL_AKTIF', false),
        'hari' => [7, 3, 1],
        'jam' => '08:00',
    ],
];
