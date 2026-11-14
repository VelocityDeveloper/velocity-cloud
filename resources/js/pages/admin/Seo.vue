<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import GrafikTren from '@/components/analitik/GrafikTren.vue';
import TabelBatang from '@/components/analitik/TabelBatang.vue';
import PageHeader from '@/components/cloud/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { angka, persen, selisih } from '@/lib/analitik';
import { tanggal } from '@/lib/format';
import admin from '@/routes/admin';

type Ringkasan = { klik: number; tayangan: number; ctr: number; posisi: number };
type Tren = { label: string; klik: number; tayangan: number; ctr: number; posisi: number | null };
type Baris = { nama: string } & Ringkasan;
const props = defineProps<{
    periode: string;
    pilihanPeriode: Record<string, string>;
    situs: string;
    laporan:
        | { status: 'belum_terhubung' }
        | { status: 'galat'; galat: string }
        | ({
              status: 'ok';
              diambil: string;
              dari: string;
              sampai: string;
              ringkasan: Ringkasan;
              ringkasan_lalu: Ringkasan;
              tren: Tren[];
              tren_lalu: Tren[];
          } & Record<'kueri' | 'halaman' | 'perangkat' | 'negara', Baris[]>);
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Admin · SEO', href: admin.seo.index() }] },
});

function muat(periode: string, segarkan = false) {
    router.get(admin.seo.index().url, { periode, ...(segarkan ? { segarkan: 1 } : {}) }, { preserveScroll: true, replace: true });
}

const ok = computed(() => (props.laporan.status === 'ok' ? props.laporan : null));
const kpi = [
    { label: 'Klik', kunci: 'klik', f: angka },
    { label: 'Tayangan', kunci: 'tayangan', f: angka },
    { label: 'CTR rata-rata', kunci: 'ctr', f: persen },
    { label: 'Posisi rata-rata', kunci: 'posisi', f: (n: number) => n.toLocaleString('id-ID'), turunBagus: true },
] as { label: string; kunci: keyof Ringkasan; f: (n: number) => string; turunBagus?: boolean }[];

const ukuran = ref<keyof Ringkasan>('klik');
const namaUkuran: Record<keyof Ringkasan, string> = { klik: 'Klik', tayangan: 'Tayangan', ctr: 'CTR (%)', posisi: 'Posisi' };
const nilai = (t: Tren) => (ukuran.value === 'ctr' ? Math.round(t.ctr * 1000) / 10 : t[ukuran.value]);

const PERANGKAT: Record<string, string> = { MOBILE: 'HP', DESKTOP: 'Desktop', TABLET: 'Tablet' };
const kolom = [
    { kunci: 'klik', label: 'Klik', format: angka },
    { kunci: 'tayangan', label: 'Tayangan', format: angka },
    { kunci: 'ctr', label: 'CTR', format: persen },
    { kunci: 'posisi', label: 'Posisi', format: (n: number) => n.toLocaleString('id-ID') },
];
</script>

<template>
    <Head title="Admin · SEO" />
    <div class="space-y-8 p-4 md:p-6">
        <PageHeader title="SEO" :description="`Google Search Console · ${situs}. Data Google terlambat 2-3 hari.`">
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <select :value="periode" class="h-9 rounded-md border border-input bg-white px-3" aria-label="Periode" @change="muat(($event.target as HTMLSelectElement).value)">
                    <option v-for="(nama, k) in pilihanPeriode" :key="k" :value="k">{{ nama }}</option>
                </select>
                <Button variant="outline" size="sm" @click="muat(periode, true)">Segarkan</Button>
            </div>
        </PageHeader>

        <div v-if="laporan.status === 'belum_terhubung'" class="rounded-lg border border-border bg-white p-6 text-sm">
            Kredensial service account GSC belum ada di server (config <code>cloud.gsc.kredensial</code>).
        </div>
        <div v-else-if="laporan.status === 'galat'" class="rounded-lg border border-red-200 bg-red-50 p-6 text-sm text-red-800">
            Gagal mengambil data Search Console: {{ laporan.galat }}
        </div>

        <template v-if="ok">
            <section class="space-y-4">
                <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
                    <div v-for="k in kpi" :key="k.kunci" class="rounded-lg border border-border bg-white p-4">
                        <p class="text-xs text-muted-foreground">{{ k.label }}</p>
                        <p class="mt-1 font-heading text-2xl font-bold text-ink tabular-nums">{{ k.f(ok.ringkasan[k.kunci]) }}</p>
                        <p class="mt-1 text-xs tabular-nums" :class="selisih(ok.ringkasan[k.kunci], ok.ringkasan_lalu[k.kunci]) === null ? 'text-muted-foreground' : (selisih(ok.ringkasan[k.kunci], ok.ringkasan_lalu[k.kunci])! >= 0) !== !!k.turunBagus ? 'text-volt-700' : 'text-red-700'">
                            <template v-if="selisih(ok.ringkasan[k.kunci], ok.ringkasan_lalu[k.kunci]) === null">– vs periode lalu</template>
                            <template v-else>{{ selisih(ok.ringkasan[k.kunci], ok.ringkasan_lalu[k.kunci])! >= 0 ? '▲' : '▼' }} {{ persen(Math.abs(selisih(ok.ringkasan[k.kunci], ok.ringkasan_lalu[k.kunci])!)) }} vs {{ k.f(ok.ringkasan_lalu[k.kunci]) }}</template>
                        </p>
                    </div>
                </div>
                <div class="rounded-lg border border-border bg-white p-4">
                    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                        <h2 class="font-semibold text-ink">Tren harian <span class="text-xs font-normal text-muted-foreground">{{ tanggal(ok.dari) }} – {{ tanggal(ok.sampai) }}</span></h2>
                        <div class="flex rounded-md border border-border p-0.5 text-sm" role="tablist">
                            <button v-for="(nama, k) in namaUkuran" :key="k" type="button" role="tab" :aria-selected="ukuran === k" class="rounded px-3 py-1" :class="ukuran === k ? 'bg-brand-700 text-white' : 'text-muted-foreground hover:text-ink'" @click="ukuran = k">{{ nama }}</button>
                        </div>
                    </div>
                    <GrafikTren :label="ok.tren.map((t) => t.label)" :kini="ok.tren.map(nilai)" :lalu="ok.tren_lalu.map(nilai)" :nama="namaUkuran[ukuran]" />
                    <p v-if="ukuran === 'posisi'" class="mt-2 text-xs text-muted-foreground">Posisi: makin kecil makin baik (1 = teratas).</p>
                </div>
            </section>

            <section class="grid gap-4 lg:grid-cols-2">
                <TabelBatang judul="Kata kunci" nama="Kueri" :baris="ok.kueri" :kolom="kolom" kosong="Belum ada kata kunci. Situs perlu terindeks Google dulu." />
                <TabelBatang judul="Halaman" nama="Halaman" :baris="ok.halaman" :kolom="kolom" kosong="Belum ada halaman tampil di Google." />
                <TabelBatang judul="Perangkat" nama="Perangkat" :baris="ok.perangkat.map((b) => ({ ...b, nama: PERANGKAT[b.nama] ?? b.nama }))" :kolom="kolom" />
                <TabelBatang judul="Negara" nama="Negara" :baris="ok.negara.map((b) => ({ ...b, nama: b.nama.toUpperCase() }))" :kolom="kolom" />
            </section>
            <p class="text-xs text-muted-foreground">Diambil {{ new Date(ok.diambil).toLocaleString('id-ID') }}, disimpan 3 jam. Klik Segarkan untuk ambil ulang.</p>
        </template>
    </div>
</template>
