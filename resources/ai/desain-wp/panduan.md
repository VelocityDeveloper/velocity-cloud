# Panduan desain WordPress FSE (Chat AI Velocity Data Center)

Target: landing page modern, rapi di desktop & HP, dan **mudah diedit pelanggan** dari editor blok / Site Editor.
Caranya: child theme blok dari Twenty Twenty-Five + isi halaman dari seksi blok core yang sudah teruji.
JANGAN menulis HTML polos (`<p>`, `<h2>`) langsung sebagai isi halaman — itu yang membuat hasil jelek.

## Alur (ikuti berurutan)
1. **Cek kondisi**: `wp theme list`, `wp post list --post_type=page --fields=ID,post_title,post_status`,
   `wp user list --role=administrator --fields=ID,user_login`. Jangan menghapus konten pelanggan tanpa izin.
2. **Data usaha**: nama usaha, 3 produk/layanan (+harga bila ada), nomor WA, email, kota, warna brand.
   Data yang belum diberikan boleh diisi contoh yang masuk akal, lalu sebutkan di laporan mana yang contoh.
3. **Foto**: `cari_foto` (kata kunci bahasa Inggris, spesifik) untuk hero, tiap produk, dan seksi keunggulan.
   Impor yang paling cocok: `wp media import <url> --title=<judul> --caption=<Foto: fotografer, Pexels> --porcelain`
   (hero pakai `url_besar`, lainnya `url_sedang`) → dapat ID. Ambil URL-nya: `wp eval 'echo wp_get_attachment_url(<ID>);'`.
   Impor beberapa foto bisa dalam satu putaran (panggil alat sekaligus).
4. **Child theme** — slug = nama usaha huruf kecil tanpa spasi (mis. `velocitycoco`). Tulis dengan `tulis_berkas`
   persis seperti contoh di bagian "Berkas tema" (ganti `{…}`):
   `themes/<slug>/style.css`, `themes/<slug>/theme.json`, `themes/<slug>/templates/front-page.html`,
   `themes/<slug>/parts/header.html`, `themes/<slug>/parts/footer.html`. Lalu `wp theme activate <slug>`.
5. **Isi Beranda**: ambil `contoh_seksi_wp` untuk seksi yang dipakai, ganti SEMUA placeholder `{…}`, lalu tulis ke
   `uploads/velocity-ai/beranda.html` — satu seksi per panggilan: seksi pertama `tambah=false`, berikutnya `tambah=true`
   (agar jawaban tidak terpotong). Urutan saran: hero, angka, produk, keunggulan, cara-pesan, testimoni, faq, kontak.
   Minimal: hero, produk, keunggulan, kontak.
6. **Pasang**: `wp post create wp-content/uploads/velocity-ai/beranda.html --post_type=page --post_status=publish --post_title=Beranda --user=<ID admin> --porcelain`
   (halaman Beranda sudah ada → `wp post update <ID> wp-content/uploads/velocity-ai/beranda.html --user=<ID admin>`).
   Lalu `wp option update show_on_front page` dan `wp option update page_on_front <ID>`.
   Bila ada versi template dari Site Editor yang menimpa (cek `wp post list --post_type=wp_template,wp_template_part --post_status=any`),
   tanyakan pelanggan sebelum menghapusnya.
7. **Laporan**: URL situs, daftar yang masih contoh (harga, testimoni, kontak), dan cara edit:
   isi halaman → Halaman › Beranda (editor blok); header/footer & warna → Tampilan › Editor (Site Editor) › Gaya.

## Aturan markup (WAJIB, kalau dilanggar blok jadi "tidak valid" di editor)
- Pakai hanya blok core dari contoh. Jangan `core/html`, `<style>`, `<script>`, atau atribut `style="…"` buatan sendiri.
- Ubah **teks, URL gambar/tautan, dan alt** saja. Jangan mengubah JSON di komentar `<!-- wp:… {…} -->`
  tanpa mengubah class/style HTML pasangannya. Mau warna lain → ganti palet di theme.json, bukan di blok.
- Karakter `&` di teks ditulis `&amp;`, `<` ditulis `&lt;`. Kutip ganda di dalam JSON komentar jangan dipakai di teks alt (pakai kata biasa).
- Mengulang kartu/kolom: salin satu blok `core/column` utuh (dari `<!-- wp:column -->` sampai `<!-- /wp:column -->`).
- Link WhatsApp: `https://wa.me/62xxxxxxxxxx?text=<pesan di-URL-encode>` (hanya angka, awalan 62).
- Testimoni tanpa data asli: nama diawali "Contoh:". Jangan mengarang klaim sertifikasi/penghargaan.
- Hasil `tulis_berkas` memuat hasil cek blok. Bila ada `tidak_valid`, perbaiki bagian itu lalu tulis ulang
  (untuk berkas beranda: tulis ulang semua seksi mulai dari yang pertama dengan `tambah=false`).

## Palet (slug tetap, ganti nilai warnanya di theme.json sesuai brand)
`base` latar putih · `contrast` teks/footer gelap · `accent-1` warna utama (tombol, judul angka) ·
`accent-2` versi sangat muda warna utama (latar seksi) · `accent-3` aksen hangat (label kecil, nomor langkah) ·
`accent-4` teks lembut (abu kehijauan/kebiruan, kontras ≥ 4.5 di atas putih) · `accent-5` permukaan krem/abu sangat muda · `accent-6` garis tipis.
Contoh: kuliner/hasil bumi hijau tua #2F5D3A + emas #D9A23B; kesehatan/klinik biru #1E5AA8 + toska #19A7A0;
fashion/kecantikan plum #6B2D5C + rose #E3A6B8; teknologi/jasa biru tua #1D3557 + oranye #F28C28;
otomotif/industri arang #222831 + kuning #F2B705. `accent-1` harus cukup gelap agar teks putih di tombol terbaca.

## Berkas tema (contoh siap pakai)
