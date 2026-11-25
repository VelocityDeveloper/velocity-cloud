# VDC Pay: API halaman bayar untuk web lain

Web klien (mis. app.velocitycybermedia.id) membuat **sesi bayar** di app.velocitydatacenter.com, mengarahkan pembeli ke
halaman bayar VDC (Midtrans: VA, QRIS, e-wallet, kartu), lalu menerima hasilnya lewat **webhook** bertanda tangan HMAC.

```
Web klien ──POST /api/checkout/sessions──▶ VDC  → { id, url }
Pembeli ──redirect ke url──▶ /bayar/{id} ──▶ Midtrans Snap ──▶ kembali ke url_sukses?checkout_id={id}
Midtrans ──notifikasi──▶ VDC ──webhook (checkout.lunas, …)──▶ url_webhook klien
```

> **Status bayar hanya dipercaya dari webhook atau API status.** Redirect ke `url_sukses` sekadar untuk tampilan.

## 1. Kredensial

Admin VDC mendaftarkan klien di **Admin › Pembayaran › VDC Pay**: nama, kode order (mis. `VCM`), URL webhook (https),
dan daftar host yang boleh dipakai untuk `url_sukses`/`url_batal`. Sesudah disimpan muncul **sekali**:

| Kunci | Pakai untuk |
|---|---|
| API key `vdcpay_…` | Header `Authorization: Bearer <api key>` ke API |
| Rahasia webhook `whsec_…` | Memverifikasi tanda tangan webhook |

Simpan keduanya di `.env` klien (`VDCPAY_API_KEY`, `VDCPAY_WEBHOOK_SECRET`). Tombol "Kunci baru" membuat pasangan baru
dan pasangan lama langsung tidak berlaku.

## 2. Buat sesi bayar

`POST https://app.velocitydatacenter.com/api/checkout/sessions`

| Kolom | Wajib | Keterangan |
|---|---|---|
| `ref` | ya | Nomor order di web klien, unik per klien. `A-Z a-z 0-9 . _ : -`, maks 64 |
| `jumlah` | ya | Rupiah, bilangan bulat, 1.000–100.000.000 |
| `deskripsi` | ya | Tampil di halaman bayar & Midtrans, maks 120 (Midtrans memotong ke 50) |
| `email` | ya | Email pembeli |
| `nama` | tidak | Nama pembeli, maks 60 |
| `url_sukses` | ya | https, host terdaftar. Pembeli kembali ke sini sesudah lunas, ditambah `?checkout_id=cs_…` |
| `url_batal` | tidak | https, host terdaftar. Tautan "Batal, kembali" di halaman bayar |
| `berlaku_menit` | tidak | Batas **mulai** membayar, 5–1440, bawaan 60 |
| `metadata` | tidak | Objek string→string (maks 20 kunci), dikembalikan apa adanya di API & webhook |

```bash
curl -X POST https://app.velocitydatacenter.com/api/checkout/sessions \
  -H "Authorization: Bearer $VDCPAY_API_KEY" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"ref":"VCM-2026-0001","jumlah":149000,"deskripsi":"Velocia Bisnis, 1 bulan","email":"pembeli@contoh.id",
       "url_sukses":"https://app.velocitycybermedia.id/billing/sukses","url_batal":"https://app.velocitycybermedia.id/billing",
       "metadata":{"tenant_id":"5","plan_id":"3"}}'
```

Balasan `201`:

```json
{
  "id": "cs_yplx4hyonbghjsi9ykviz5g4a761zapg",
  "ref": "VCM-2026-0001",
  "status": "dibuat",
  "jumlah": 149000,
  "deskripsi": "Velocia Bisnis, 1 bulan",
  "email": "pembeli@contoh.id",
  "metadata": {"tenant_id": "5", "plan_id": "3"},
  "url": "https://app.velocitydatacenter.com/bayar/cs_yplx4hyonbghjsi9ykviz5g4a761zapg",
  "berlaku_sampai": "2026-11-24T10:37:33+07:00",
  "dibayar_pada": null,
  "metode": null
}
```

Arahkan pembeli ke `url`.

**Idempoten per `ref`:** mengirim ulang `ref` yang sama dengan `jumlah`, `email`, dan `deskripsi` yang sama mendapat
sesi yang sama (`200`). Aman diulang saat timeout jaringan. `ref` yang sama dengan isi berbeda ditolak `409`. Untuk
pembayaran ulang (sesi kedaluwarsa/batal), buat `ref` baru.

| Kode | Arti |
|---|---|
| 201 / 200 | Sesi baru / sesi lama dengan ref sama |
| 401 | API key salah atau klien nonaktif (lebih dari 20 kali/menit dari IP yang sama → `429`) |
| 409 | `ref` sudah dipakai untuk isi lain (balasan berisi `id` sesi lama) |
| 422 | Validasi gagal (`errors`), atau URL kembali bukan https/host tidak terdaftar (`galat`, `kolom`) |
| 429 | Lebih dari 120 permintaan/menit per klien |
| 503 | Pembayaran online VDC sedang dimatikan |

## 3. Cek status (cadangan webhook)

`GET /api/checkout/sessions/{id}` dengan header yang sama. Balasannya berbentuk sama dengan di atas. Bila status masih
`menunggu`, VDC menanyakan dulu ke Midtrans sebelum menjawab. Pakai saat pembeli kembali ke `url_sukses` (webhook bisa
datang beberapa detik kemudian) atau untuk rekonsiliasi.

## 4. Status

| Status | Arti | Final? | Webhook |
|---|---|---|---|
| `dibuat` | Belum memilih metode bayar | – | – |
| `menunggu` | Transaksi Midtrans dibuat, dana belum masuk (mis. VA belum ditransfer) | – | – |
| `lunas` | Dana masuk dan nominal cocok | ya* | `checkout.lunas` |
| `kedaluwarsa` | Tidak dibayar sampai batas | ya** | `checkout.kedaluwarsa` |
| `batal` | Dibatalkan di Midtrans | ya** | `checkout.batal` |
| `ditolak` | Ditolak bank/fraud | ya** | `checkout.ditolak` |
| `refund` | Dana dikembalikan **penuh** | ya | `checkout.refund` |

\* `lunas` hanya bisa berubah ke `refund`, dan hanya bila transaksi yang melunasi sesi itu yang di-refund. Bila pembeli
terlanjur membayar dua kali, transaksi kedua tidak mengubah status (admin VDC mengembalikan dananya), dan refund atas
transaksi kedua itu juga tidak mengubah status. Refund **sebagian** tidak mengubah status (tetap `lunas`) dan tidak
mengirim webhook; diurus admin VDC.
\** Transaksi lama yang ternyata dibayar sesudah sesi ditutup tetap menjadi `lunas` (dana sudah masuk), dan
`checkout.lunas` tetap dikirim. Klien wajib menerima `checkout.lunas` walau sebelumnya menerima `checkout.kedaluwarsa`.

`berlaku_sampai` adalah batas *mulai* membayar. Bila pembeli sudah mendapat VA, VA itu berlaku sampai 23 jam dan
statusnya mengikuti Midtrans, jadi `lunas` bisa datang sesudah `berlaku_sampai`.

## 5. Webhook

`POST <url_webhook>` dengan body JSON:

```json
{
  "id": "evt_bewzfaof8zxnqbudfr7yzfotmzkg72cu",
  "jenis": "checkout.lunas",
  "dibuat": "2026-11-24T09:37:41+07:00",
  "data": { "id": "cs_…", "ref": "VCM-2026-0001", "status": "lunas", "jumlah": 149000, "metode": "bank_transfer",
            "dibayar_pada": "2026-11-24T09:37:41+07:00", "metadata": {"tenant_id": "5", "plan_id": "3"}, "…": "…" }
}
```

Header:

| Header | Isi |
|---|---|
| `VDC-Event-Id` | Sama dengan `id` di body |
| `VDC-Signature` | `t=<unix detik>,v1=<hex HMAC-SHA256("<t>.<body mentah>", rahasia webhook)>` |

**Klien wajib:**
1. Verifikasi tanda tangan memakai **body mentah** (jangan di-decode lalu di-encode ulang) dan `hash_equals`.
2. Tolak bila `|sekarang − t| > 300` detik (cegah kiriman lama diputar ulang). Tanda tangan dibuat ulang tiap percobaan.
3. Simpan `id` event; event yang sudah pernah diproses dibalas `200` tanpa diproses lagi (idempoten).
4. Balas `2xx` secepatnya (< 15 detik). Proses berat taruh di antrean.

**Kirim ulang:** selain `2xx` (atau timeout 15 detik) dianggap gagal dan diulang 7 kali dengan jeda 1 mnt, 5 mnt,
15 mnt, 1 jam, 3 jam, 6 jam, 12 jam (±22 jam total). Sesudah itu event berstatus `gagal` dan admin VDC bisa
mengirim ulang dari Admin › Pembayaran. Satu event bisa saja terkirim lebih dari sekali (mis. sesudah gangguan
server VDC), jadi idempotensi per `id` event wajib. Urutan event tidak dijamin; pakai `data.status` sebagai status terbaru.

### Contoh penerima (Laravel)

```php
// routes/api.php (tanpa sesi/CSRF; bila di web.php, kecualikan dari middleware CSRF)
Route::post('vdcpay/webhook', VdcPayWebhookController::class);

class VdcPayWebhookController
{
    public function __invoke(Request $request)
    {
        $body = $request->getContent();
        parse_str(str_replace(',', '&', (string) $request->header('VDC-Signature')), $sig);
        $sah = isset($sig['t'], $sig['v1'])
            && abs(time() - (int) $sig['t']) <= 300
            && hash_equals(hash_hmac('sha256', $sig['t'].'.'.$body, config('services.vdcpay.webhook_secret')), $sig['v1']);
        abort_unless($sah, 400);

        $event = json_decode($body, true);
        // Idempoten: tabel vdcpay_events dengan kolom event_id UNIQUE
        if (! DB::table('vdcpay_events')->insertOrIgnore(['event_id' => $event['id'], 'created_at' => now()])) {
            return response()->noContent(); // sudah diproses
        }
        match ($event['jenis']) {
            'checkout.lunas' => AktifkanPaket::dispatch($event['data']['ref'], $event['data']['metadata']),
            'checkout.refund' => CabutPaket::dispatch($event['data']['ref']),
            default => null, // kedaluwarsa/batal/ditolak: tandai order gagal
        };

        return response()->noContent();
    }
}
```

Saat mengaktifkan paket, cocokkan juga `data.jumlah` dengan harga order di database klien.

## 6. Uji

- Mode sandbox/produksi mengikuti pengaturan Midtrans di Admin › Pembayaran (satu akun untuk tagihan VDC & VDC Pay).
- Di sandbox, bayar dengan simulator Midtrans: https://simulator.sandbox.midtrans.com
- Order Midtrans VDC Pay berformat `CS-<kode>-<id>-<waktu>`, terpisah dari tagihan VDC (`INV-…`) di Notification URL yang sama.
