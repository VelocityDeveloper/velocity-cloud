<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { usePoll } from '@inertiajs/vue3';
import { computed } from 'vue';
import GrafikTrenGabung from '@/components/analitik/GrafikTrenGabung.vue';
import PetaJam from '@/components/analitik/PetaJam.vue';
import TabelBatang from '@/components/analitik/TabelBatang.vue';
import PageHeader from '@/components/cloud/PageHeader.vue';
import { angka, durasi, persen, selisih } from '@/lib/analitik';
import { tanggal } from '@/lib/format';
import admin from '@/routes/admin';

type Baris = Record<string, any>;
type Ringkasan = Record<'pengguna' | 'pengguna_baru' | 'sesi' | 'tampilan' | 'tampilan_per_sesi' | 'durasi_per_sesi' | 'engaged_rate' | 'bounce_rate' | 'konversi', number>;
type Tren = { label: string; pengguna: number | null; sesi: number | null; tampilan: number | null; baru: number | null; konversi: number | null };
const props = defineProps<{
    filter: { periode: string; internal: boolean };
    pilihanPeriode: Record<string, string>;
    laporan: {
        ringkasan: Ringkasan;
        ringkasan_lalu: Ringkasan;
        tren: Tren[];
        tren_lalu: Tren[];
        realtime: { aktif_30: number; aktif_5: number; per_menit: { label: string; n: number }[]; halaman: Baris[] };
        jam: [number, number, number][];
        dari: string;
        sampai: string;
    } & Record<'halaman' | 'halaman_landing' | 'halaman_keluar' | 'sumber' | 'kampanye' | 'referrer' | 'negara' | 'kota' | 'perangkat' | 'browser' | 'os' | 'layar' | 'bahasa' | 'event', Baris[]>;
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Admin · Analitik', href: admin.analitik.index() }] },
});

// data diimpor dari log tiap menit; muat ulang laporan tiap 60 dtk agar realtime ikut segar
usePoll(60_000, { only: ['laporan'] });

function saring(ubah: Partial<typeof props.filter>) {
    const f = { ...props.filter, ...ubah };
    router.get(admin.analitik.index().url, { periode: f.periode, internal: f.internal ? 1 : 0 }, { preserveScroll: true, replace: true });
}

const r = computed(() => props.laporan.ringkasan);
const l = computed(() => props.laporan.ringkasan_lalu);
const kpi = computed(() => [
    { label: 'Visitor', kunci: 'pengguna', f: angka },
    { label: 'Visitor baru', kunci: 'pengguna_baru', f: angka },
    { label: 'Sesi', kunci: 'sesi', f: angka },
    { label: 'Tampilan halaman', kunci: 'tampilan', f: angka },
    { label: 'Tampilan / sesi', kunci: 'tampilan_per_sesi', f: (n: number) => n.toLocaleString('id-ID') },
    { label: 'Durasi rata-rata / sesi', kunci: 'durasi_per_sesi', f: durasi },
    { label: 'Engagement rate', kunci: 'engaged_rate', f: persen },
    { label: 'Bounce rate', kunci: 'bounce_rate', f: persen, turunBagus: true },
    { label: 'Konversi (klik Pesan, WA, Daftar)', kunci: 'konversi', f: angka },
] as { label: string; kunci: keyof Ringkasan; f: (n: number) => string; turunBagus?: boolean }[]);

const maksMenit = computed(() => Math.max(1, ...props.laporan.realtime.per_menit.map((m) => m.n)));

const NAMA_EVENT: Record<string, string> = { klik_pesan: 'Klik Pesan', klik_wa: 'Klik WhatsApp', klik_daftar: 'Klik Daftar', klik_masuk: 'Klik Masuk', tautan_keluar: 'Tautan keluar' };
const PERANGKAT: Record<string, string> = { mobile: 'HP', desktop: 'Desktop', tablet: 'Tablet' };
const navigasi = [
    ['ringkasan', 'Ringkasan'], ['realtime', 'Realtime'], ['akuisisi', 'Akuisisi'], ['konten', 'Konten'], ['pengguna', 'Visitor & teknologi'], ['event', 'Event'],
];
const kolomPengguna = [
    { kunci: 'pengguna', label: 'Visitor', format: angka },
    { kunci: 'sesi', label: 'Sesi', format: angka },
    { kunci: 'tampilan', label: 'Tampilan', format: angka },
];
</script>

<template>
    <Head title="Admin · Analitik" />
    <div class="space-y-8 p-4 md:p-6">
        <PageHeader title="Analitik landing" :description="`velocitydatacenter.com · ${tanggal(laporan.dari)} – ${tanggal(laporan.sampai)}`">
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <select :value="filter.periode" class="h-9 rounded-md border border-input bg-white px-3" aria-label="Periode" @change="saring({ periode: ($event.target as HTMLSelectElement).value })">
                    <option v-for="(nama, k) in pilihanPeriode" :key="k" :value="k">{{ nama }}</option>
                </select>
                <label class="flex items-center gap-2">
                    <input type="checkbox" :checked="filter.internal" class="size-4 accent-brand-700" @change="saring({ internal: ($event.target as HTMLInputElement).checked })" />
                    Sertakan trafik kantor
                </label>
            </div>
        </PageHeader>

        <nav class="sticky top-0 z-10 -mx-4 flex gap-1 overflow-x-auto border-b border-border bg-background px-4 py-2 text-sm md:-mx-6 md:px-6" aria-label="Bagian laporan">
            <a v-for="[id, nama] in navigasi" :key="id" :href="`#${id}`" class="rounded-md px-3 py-1.5 whitespace-nowrap text-muted-foreground hover:bg-brand-50 hover:text-brand-700">{{ nama }}</a>
        </nav>

        <section id="ringkasan" class="scroll-mt-16 space-y-4">
            <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
                <div v-for="k in kpi" :key="k.kunci" class="rounded-lg border border-border bg-white p-4">
                    <p class="text-xs text-muted-foreground">{{ k.label }}</p>
                    <p class="mt-1 font-heading text-2xl font-bold text-ink tabular-nums">{{ k.f(r[k.kunci]) }}</p>
                    <p class="mt-1 text-xs tabular-nums" :class="selisih(r[k.kunci], l[k.kunci]) === null ? 'text-muted-foreground' : (selisih(r[k.kunci], l[k.kunci])! >= 0) !== !!k.turunBagus ? 'text-volt-700' : 'text-red-700'">
                        <template v-if="selisih(r[k.kunci], l[k.kunci]) === null">– vs periode lalu</template>
                        <template v-else>{{ selisih(r[k.kunci], l[k.kunci])! >= 0 ? '▲' : '▼' }} {{ persen(Math.abs(selisih(r[k.kunci], l[k.kunci])!)) }} vs {{ k.f(l[k.kunci]) }}</template>
                    </p>
                </div>
            </div>
            <div class="rounded-lg border border-border bg-white p-4">
                <h2 class="mb-3 font-semibold text-ink">Tren {{ filter.periode === 'hari_ini' || filter.periode === 'kemarin' ? 'per jam' : 'harian' }}</h2>
                <GrafikTrenGabung :tren="laporan.tren" :lalu="laporan.tren_lalu" />
            </div>
        </section>

        <section id="realtime" class="scroll-mt-16 grid gap-4 lg:grid-cols-[1fr_2fr]">
            <div class="rounded-lg border border-border bg-white p-4">
                <h2 class="font-semibold text-ink">Realtime</h2>
                <p class="mt-3 text-xs text-muted-foreground">Visitor aktif 30 menit terakhir</p>
                <p class="font-heading text-4xl font-bold text-ink tabular-nums">{{ angka(laporan.realtime.aktif_30) }}</p>
                <p class="mt-1 text-sm text-muted-foreground">{{ angka(laporan.realtime.aktif_5) }} dalam 5 menit terakhir</p>
                <div class="mt-4 flex h-16 items-end gap-0.5" role="img" aria-label="Visitor aktif per menit, 30 menit terakhir">
                    <span v-for="m in laporan.realtime.per_menit" :key="m.label" class="flex-1 rounded-t-sm bg-brand-700" :style="{ height: m.n ? `${(m.n / maksMenit) * 100}%` : '2px', opacity: m.n ? 1 : 0.15 }" :title="`${m.label} · ${m.n} visitor`" />
                </div>
                <p class="mt-1 flex justify-between text-[10px] text-muted-foreground"><span>-30 mnt</span><span>sekarang</span></p>
                <p class="mt-3 text-xs text-muted-foreground">Diperbarui tiap menit. Trafik kantor {{ filter.internal ? 'disertakan' : 'tidak dihitung' }}.</p>
            </div>
            <TabelBatang judul="Halaman dilihat, 30 menit terakhir" nama="Halaman" :baris="laporan.realtime.halaman" :kolom="[{ kunci: 'tampilan', label: 'Tampilan', format: angka }, { kunci: 'pengguna', label: 'Visitor', format: angka }]" kosong="Tidak ada pengunjung saat ini." />
        </section>

        <section id="akuisisi" class="scroll-mt-16 space-y-4">
            <h2 class="text-lg font-bold text-ink">Akuisisi</h2>
            <div class="grid gap-4 xl:grid-cols-2">
                <TabelBatang judul="Sumber / medium sesi" keterangan="Dari halaman pertama tiap sesi. UTM mengalahkan referrer." nama="Sumber / medium" :baris="laporan.sumber" :kolom="[{ kunci: 'sesi', label: 'Sesi', format: angka }, { kunci: 'pengguna', label: 'Visitor', format: angka }, { kunci: 'engaged_rate', label: 'Engaged', format: persen }, { kunci: 'event', label: 'Event', format: angka }]" />
                <TabelBatang judul="Kampanye (utm_campaign)" nama="Kampanye" :baris="laporan.kampanye" :kolom="[{ kunci: 'sesi', label: 'Sesi', format: angka }, { kunci: 'pengguna', label: 'Visitor', format: angka }]" kosong="Belum ada kunjungan ber-UTM." />
                <TabelBatang class="xl:col-span-2" judul="Referrer" keterangan="Alamat lengkap halaman asal pengunjung." nama="URL asal" :baris="laporan.referrer" :kolom="[{ kunci: 'tampilan', label: 'Tampilan', format: angka }, { kunci: 'pengguna', label: 'Visitor', format: angka }]" />
            </div>
        </section>

        <section id="konten" class="scroll-mt-16 space-y-4">
            <h2 class="text-lg font-bold text-ink">Konten</h2>
            <TabelBatang judul="Halaman" keterangan="Waktu aktif = tab terlihat. Scroll = rata-rata kedalaman gulir." nama="Halaman" :baris="laporan.halaman" :kolom="[{ kunci: 'tampilan', label: 'Tampilan', format: angka }, { kunci: 'pengguna', label: 'Visitor', format: angka }, { kunci: 'durasi', label: 'Waktu aktif', format: durasi }, { kunci: 'scroll', label: 'Scroll', format: (v: number | null) => (v === null ? '-' : v + '%') }]">
                <template #nama="{ baris }"><span class="font-medium">{{ baris.path }}</span><span v-if="baris.judul" class="ml-2 text-xs text-muted-foreground">{{ baris.judul }}</span></template>
            </TabelBatang>
            <div class="grid gap-4 xl:grid-cols-2">
                <TabelBatang judul="Halaman landing" keterangan="Halaman pertama yang dibuka di tiap sesi." nama="Halaman" :baris="laporan.halaman_landing" :kolom="[{ kunci: 'sesi', label: 'Sesi', format: angka }, { kunci: 'bounce_rate', label: 'Bounce', format: persen }, { kunci: 'durasi', label: 'Durasi', format: durasi }]" />
                <TabelBatang judul="Halaman keluar" keterangan="Halaman terakhir sebelum pengunjung pergi." nama="Halaman" :baris="laporan.halaman_keluar" :kolom="[{ kunci: 'keluar', label: 'Keluar', format: angka }]" />
            </div>
        </section>

        <section id="pengguna" class="scroll-mt-16 space-y-4">
            <h2 class="text-lg font-bold text-ink">Visitor & teknologi</h2>
            <div class="grid gap-4 xl:grid-cols-2">
                <TabelBatang judul="Negara" nama="Negara" :baris="laporan.negara" :kolom="kolomPengguna" />
                <TabelBatang judul="Kota" nama="Kota" :baris="laporan.kota" :kolom="kolomPengguna" kosong="Data kota belum tersedia (aktifkan header lokasi pengunjung di Cloudflare)." />
                <TabelBatang judul="Perangkat" nama="Kategori" :baris="laporan.perangkat" :kolom="kolomPengguna">
                    <template #nama="{ baris }">{{ PERANGKAT[baris.nama] ?? baris.nama }}</template>
                </TabelBatang>
                <TabelBatang judul="Browser" nama="Browser" :baris="laporan.browser" :kolom="kolomPengguna" />
                <TabelBatang judul="Sistem operasi" nama="OS" :baris="laporan.os" :kolom="kolomPengguna" />
                <TabelBatang judul="Resolusi layar" nama="Resolusi" :baris="laporan.layar" :kolom="kolomPengguna" />
                <TabelBatang judul="Bahasa browser" nama="Bahasa" :baris="laporan.bahasa" :kolom="kolomPengguna" />
                <div class="rounded-lg border border-border bg-white p-4">
                    <h3 class="font-semibold text-ink">Waktu kunjungan</h3>
                    <p class="mt-0.5 mb-3 text-xs text-muted-foreground">Tampilan halaman per hari dan jam (WIB).</p>
                    <PetaJam :data="laporan.jam" />
                </div>
            </div>
        </section>

        <section id="event" class="scroll-mt-16 space-y-4">
            <h2 class="text-lg font-bold text-ink">Event</h2>
            <TabelBatang judul="Event & konversi" keterangan="Label: Pesan = paket|halaman; Daftar dan Masuk = halaman|lokasi tombol (header, buat-akun, bawah, lain); WhatsApp = halaman; tautan keluar = host. Maks 200 baris." nama="Event" :baris="laporan.event" :kolom="[{ kunci: 'jumlah', label: 'Jumlah', format: angka }, { kunci: 'pengguna', label: 'Visitor', format: angka }]">
                <template #nama="{ baris }"><span class="font-medium">{{ NAMA_EVENT[baris.event] ?? baris.event }}</span><span v-if="baris.label" class="ml-2 text-xs text-muted-foreground">{{ baris.label }}</span></template>
            </TabelBatang>
        </section>
    </div>
</template>
