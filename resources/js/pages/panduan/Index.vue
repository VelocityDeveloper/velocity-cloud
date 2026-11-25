<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '@/components/cloud/PageHeader.vue';
import { Button } from '@/components/ui/button';
import customer from '@/routes/customer';
import layanan from '@/routes/layanan';
import tiket from '@/routes/tiket';

defineOptions({ layout: { breadcrumbs: [{ title: 'Panduan', href: '/panduan' }] } });

const bagian = [
    { id: 'umum', judul: 'Mulai di sini' },
    { id: 'server', judul: 'Cloud Server' },
    { id: 'app', judul: 'App 1-Klik' },
    { id: 'token', judul: 'Token AI' },
    { id: 'masalah', judul: 'Ada masalah?' },
];
const gambar = (n: string) => `/images/panduan/${n}.png`;
</script>

<template>
    <Head title="Panduan" />
    <div class="space-y-10 p-4 md:p-6">
        <PageHeader title="Panduan" description="Cara mengelola server, app, dan token AI. Semua dari satu halaman, tanpa perlu bertanya dulu." />

        <nav aria-label="Daftar isi" class="flex flex-wrap gap-2 lg:hidden">
            <Button v-for="b in bagian" :key="b.id" as-child variant="outline" size="sm"><a :href="`#${b.id}`">{{ b.judul }}</a></Button>
        </nav>

        <div class="gap-10 lg:grid lg:grid-cols-[minmax(0,1fr)_13rem]">
        <div class="min-w-0 space-y-10">
        <!-- UMUM -->
        <section id="umum" class="max-w-3xl scroll-mt-20 space-y-4">
            <h2 class="text-xl font-semibold text-ink">Mulai di sini</h2>
            <p class="text-muted-foreground">Semua layanan Anda ada di menu <b class="text-ink">Layanan Saya</b>. Klik nama layanan untuk membuka halaman kelolanya. Aturannya sama untuk semua jenis layanan:</p>
            <ol class="list-decimal space-y-1 pl-5">
                <li>Buka <Link :href="layanan.index()" class="font-semibold text-brand-700">Layanan Saya</Link>.</li>
                <li>Klik layanan yang ingin dikelola.</li>
                <li>Data login ada di kotak <b>Akses</b>. Klik ikon mata untuk menampilkan, klik ikon salin untuk menyalin.</li>
            </ol>
            <img :src="gambar('layanan-saya')" alt="Halaman Layanan Saya berisi daftar layanan" class="rounded-lg border border-border" loading="lazy" />
            <p class="text-sm text-muted-foreground">Status <b class="text-ink">Aktif</b> berarti layanan siap dipakai. Tagihan dan jatuh tempo ada di menu <b class="text-ink">Tagihan</b>.</p>
        </section>

        <!-- SERVER -->
        <section id="server" class="max-w-3xl scroll-mt-20 space-y-5">
            <h2 class="text-xl font-semibold text-ink">Cloud Server</h2>

            <div class="space-y-3">
                <h3 class="font-semibold text-ink">1. Masuk ke server</h3>
                <p class="text-muted-foreground">Server siap dipakai sekitar 1–3 menit setelah status Aktif. Buka halaman layanan, lihat kotak <b class="text-ink">Akses</b> dan kotak <b class="text-ink">Paket</b>.</p>
                <img :src="gambar('vps-akses')" alt="Kotak Akses dan Paket pada halaman Cloud Server" class="rounded-lg border border-border" loading="lazy" />
                <ol class="list-decimal space-y-1 pl-5">
                    <li>Klik ikon salin di baris <b>Perintah SSH</b>.</li>
                    <li>Buka Terminal (Mac/Linux) atau PowerShell (Windows), tempel, tekan Enter.</li>
                    <li>Saat diminta sandi, tempel sandi dari baris <b>Sandi / API key</b> (klik ikon mata untuk melihatnya). Sandi tidak tampil saat diketik, itu normal.</li>
                </ol>
                <p class="text-sm text-muted-foreground">Ingin sandi sendiri? Setelah masuk ketik <code class="rounded bg-muted px-1">passwd</code>. Port <b>40020–40029</b> bisa dipakai untuk aplikasi Anda (web, game, dsb.), sudah diteruskan ke server.</p>
            </div>

            <div class="space-y-3">
                <h3 class="font-semibold text-ink">2. Nyalakan, matikan, restart</h3>
                <img :src="gambar('vps-kontrol')" alt="Kotak Kontrol VPS dengan saklar On, tombol Restart dan Segarkan" class="rounded-lg border border-border" loading="lazy" />
                <ul class="list-disc space-y-1 pl-5">
                    <li><b>Saklar On/Off</b>: menyalakan atau mematikan server.</li>
                    <li><b>Restart</b>: nyalakan ulang. Pakai ini jika server lambat atau tidak merespons.</li>
                    <li><b>Segarkan</b>: muat ulang status terbaru. Tidak mengubah apa pun.</li>
                </ul>
            </div>

            <div class="space-y-3">
                <h3 class="font-semibold text-ink">3. Pantau pemakaian</h3>
                <img :src="gambar('vps-analitik')" alt="Grafik analitik penggunaan CPU, RAM, proses dan jaringan" class="rounded-lg border border-border" loading="lazy" />
                <p class="text-muted-foreground">Kotak <b class="text-ink">Analitik penggunaan</b> menampilkan CPU, RAM, jumlah proses, dan jaringan. Pilih <b>Live</b>, 1 jam, 6 jam, 24 jam, atau 7 hari. RAM hampir penuh terus-menerus? Pertimbangkan paket yang lebih besar.</p>
            </div>
        </section>

        <!-- APP -->
        <section id="app" class="max-w-3xl scroll-mt-20 space-y-5">
            <h2 class="text-xl font-semibold text-ink">App 1-Klik (n8n, WordPress, WAHA, dll.)</h2>

            <div class="space-y-3">
                <h3 class="font-semibold text-ink">1. Buka app dan login</h3>
                <img :src="gambar('app-akses')" alt="Kotak Akses pada halaman app berisi alamat, pengguna dan sandi" class="rounded-lg border border-border" loading="lazy" />
                <ol class="list-decimal space-y-1 pl-5">
                    <li>Klik <b>Alamat</b> untuk membuka app di tab baru.</li>
                    <li>Salin <b>Pengguna</b> dan <b>Sandi</b> dari kotak Akses, lalu tempel di halaman login app.</li>
                    <li>Beberapa app punya data tambahan, misalnya <b>Sandi database</b>. Simpan di tempat aman.</li>
                </ol>
            </div>

            <div class="space-y-3">
                <h3 class="font-semibold text-ink">2. Kontrol app</h3>
                <img :src="gambar('app-kontrol')" alt="Kotak Kontrol app dengan saklar On, Restart, Segarkan dan tabel CPU RAM" class="rounded-lg border border-border" loading="lazy" />
                <ul class="list-disc space-y-1 pl-5">
                    <li><b>Saklar On/Off</b>: app yang dimatikan tetap mati sampai Anda menyalakannya lagi.</li>
                    <li><b>Restart</b>: langkah pertama jika app macet atau error. Tunggu 1–2 menit.</li>
                    <li><b>Tabel Komponen</b>: CPU dan RAM tiap bagian app (misalnya web dan db).</li>
                    <li><b>Lihat log</b>: catatan kerja app. Berguna untuk mencari penyebab error; log bisa memuat data sensitif, jadi jangan dibagikan sembarangan.</li>
                </ul>
            </div>

            <div class="space-y-3">
                <h3 class="font-semibold text-ink">3. Pakai domain sendiri</h3>
                <img :src="gambar('app-domain')" alt="Kotak Custom domain" class="rounded-lg border border-border" loading="lazy" />
                <ol class="list-decimal space-y-1 pl-5">
                    <li>Isi <b>Domain Anda</b> (contoh <code class="rounded bg-muted px-1">toko.contoh.com</code>), klik <b>Simpan</b>.</li>
                    <li>Di penyedia domain Anda, buat <b>A record</b> ke IP yang tampil di halaman (ada tombol salin).</li>
                    <li>Jika memakai Cloudflare, matikan proxy (awan abu-abu).</li>
                    <li>Klik <b>Periksa DNS</b>. Status berubah dari <i>Menunggu DNS</i> menjadi <i>DNS terhubung</i>, SSL gratis terbit otomatis.</li>
                </ol>
                <p class="text-sm text-muted-foreground">Perubahan DNS bisa butuh beberapa menit sampai beberapa jam. Untuk melepas domain, kosongkan kolom lalu Simpan. Sebagian app (misalnya WordPress) menyimpan alamat situs sendiri; ubah alamat itu di pengaturan app ke domain Anda.</p>
            </div>

            <div class="space-y-2">
                <h3 class="font-semibold text-ink">Khusus OpenClaw: menyetujui browser baru</h3>
                <p class="text-muted-foreground">Buka alamat app, tempel sandi di kolom <b class="text-ink">Gateway Token</b>, klik <b class="text-ink">Connect</b>. Kembali ke halaman layanan, di kotak <b class="text-ink">Perangkat</b> klik <b>Cek permintaan</b>, lalu <b>Setujui</b>.</p>
            </div>

            <p class="rounded-md bg-muted/50 p-3 text-sm text-muted-foreground"><b class="text-ink">Backup:</b> data app tidak otomatis dibackup. Ekspor data penting secara berkala lewat fitur backup/ekspor di dalam app-nya.</p>
        </section>

        <!-- TOKEN -->
        <section id="token" class="max-w-3xl scroll-mt-20 space-y-5">
            <h2 class="text-xl font-semibold text-ink">Token AI</h2>

            <div class="space-y-3">
                <h3 class="font-semibold text-ink">1. Ambil API key</h3>
                <img :src="gambar('token-akses')" alt="Kotak Akses Token AI berisi API key, Base URL dan Model" class="rounded-lg border border-border" loading="lazy" />
                <p class="text-muted-foreground">Token AI kompatibel dengan OpenAI. Di aplikasi atau SDK apa pun yang mendukung OpenAI, isi tiga hal ini dari kotak Akses:</p>
                <ul class="list-disc space-y-1 pl-5">
                    <li><b>API key</b> (klik mata untuk melihat, ikon salin untuk menyalin)</li>
                    <li><b>Base URL</b></li>
                    <li><b>Model</b></li>
                </ul>
                <p class="text-sm text-muted-foreground">API key itu rahasia. Jangan ditempel di kode yang dipublikasikan atau dikirim ke orang lain.</p>
            </div>

            <div class="space-y-3">
                <h3 class="font-semibold text-ink">2. Cek saldo dan isi ulang</h3>
                <img :src="gambar('token-saldo')" alt="Kotak Token AI berisi sisa saldo, top-up, contoh perintah dan pemakaian terakhir" class="rounded-lg border border-border" loading="lazy" />
                <ul class="list-disc space-y-1 pl-5">
                    <li><b>Sisa saldo</b> dan <b>Terpakai</b> dihitung dalam token. Input dan output dihitung sama.</li>
                    <li>Saldo prabayar dan tidak punya masa berlaku.</li>
                    <li><b>Top-up</b>: isi jumlah (dalam juta token), klik <b>Buat tagihan</b>, bayar lewat menu <Link :href="'/tagihan'" class="font-semibold text-brand-700">Tagihan</Link>. Saldo bertambah setelah tagihan lunas.</li>
                    <li><b>Pemakaian terakhir</b> menunjukkan tiap permintaan. Angka saldo merah berarti saldo habis.</li>
                </ul>
            </div>

            <div class="space-y-3">
                <h3 class="font-semibold text-ink">3. Coba cepat</h3>
                <p class="text-muted-foreground">Tanpa coding: buka menu <Link :href="'/chat'" class="font-semibold text-brand-700">Chat AI</Link>, pilih model, lalu ketik. Dengan kode, contoh Python:</p>
                <pre class="overflow-x-auto rounded-lg bg-ink p-4 text-xs text-white"><code>from openai import OpenAI

client = OpenAI(base_url="BASE_URL_ANDA", api_key="API_KEY_ANDA")
r = client.chat.completions.create(
    model="MODEL_ANDA",
    messages=[{"role": "user", "content": "Halo"}],
)
print(r.choices[0].message.content)</code></pre>
                <p class="text-sm text-muted-foreground">Contoh <code class="rounded bg-muted px-1">curl</code> siap salin sudah tersedia di halaman layanan Token AI.</p>
            </div>
        </section>

        <!-- MASALAH -->
        <section id="masalah" class="max-w-3xl scroll-mt-20 space-y-4">
            <h2 class="text-xl font-semibold text-ink">Ada masalah?</h2>
            <dl class="divide-y divide-border rounded-lg border border-border text-sm">
                <div class="px-5 py-3"><dt class="font-semibold text-ink">Lupa sandi / data login</dt><dd class="mt-1 text-muted-foreground">Buka halaman layanan, kotak Akses, klik ikon mata. Data selalu tersimpan di sana.</dd></div>
                <div class="px-5 py-3"><dt class="font-semibold text-ink">App atau server tidak bisa dibuka</dt><dd class="mt-1 text-muted-foreground">Klik <b>Segarkan</b> dan pastikan status <b>Menyala</b>. Jika mati, nyalakan lewat saklar. Jika masih gagal, klik <b>Restart</b> dan tunggu 1–2 menit.</dd></div>
                <div class="px-5 py-3"><dt class="font-semibold text-ink">Layanan Off setelah jatuh tempo</dt><dd class="mt-1 text-muted-foreground">Bayar tagihan di menu Tagihan. Layanan menyala kembali setelah tagihan lunas.</dd></div>
                <div class="px-5 py-3"><dt class="font-semibold text-ink">Saldo token tidak bertambah</dt><dd class="mt-1 text-muted-foreground">Saldo masuk setelah tagihan top-up berstatus Lunas. Cek status di menu Tagihan.</dd></div>
            </dl>
            <p class="text-muted-foreground">Masih belum beres? Buat tiket di <Link :href="tiket.index()" class="font-semibold text-brand-700">Support Ticket</Link>. Sertakan <b class="text-ink">ID layanan</b> (tertera di kotak Paket, contoh #8), apa yang Anda lakukan, dan tangkapan layar jika ada. Dengan begitu tim kami bisa langsung menangani tanpa tanya balik.</p>
            <Button as-child variant="outline"><Link :href="customer.dashboard()">Kembali ke Dashboard</Link></Button>
        </section>
        </div>

        <aside class="hidden lg:block">
            <nav aria-label="Daftar isi" class="sticky top-6 border-l border-border pl-4 text-sm">
                <p class="mb-2 font-semibold text-ink">Di halaman ini</p>
                <ul class="space-y-2">
                    <li v-for="b in bagian" :key="b.id"><a :href="`#${b.id}`" class="text-muted-foreground hover:text-brand-700">{{ b.judul }}</a></li>
                </ul>
            </nav>
        </aside>
        </div>
    </div>
</template>
