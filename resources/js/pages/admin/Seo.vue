<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CalendarIcon } from '@lucide/vue';
import { getLocalTimeZone, parseDate, today } from '@internationalized/date';
import type { DateRange } from 'reka-ui';
import { computed, ref } from 'vue';
import type { Ref } from 'vue';
import GrafikGabungan from '@/components/analitik/GrafikGabungan.vue';
import type { Seri } from '@/components/analitik/GrafikGabungan.vue';
import TabelBatang from '@/components/analitik/TabelBatang.vue';
import PageHeader from '@/components/cloud/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { RangeCalendar } from '@/components/ui/range-calendar';
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
    errors?: Record<string, string>;
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
              pengunjung?: number[];
          } & Record<'kueri' | 'halaman' | 'perangkat' | 'negara', Baris[]>);
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Admin · SEO', href: admin.seo.index() }] },
});

const ok = computed(() => (props.laporan.status === 'ok' ? props.laporan : null));
const hariIni = today(getLocalTimeZone());
const batasAwal = hariIni.subtract({ months: 16 }); // GSC menyimpan data ±16 bulan
const rentang = ref({
    start: ok.value ? parseDate(ok.value.dari) : undefined,
    end: ok.value ? parseDate(ok.value.sampai) : undefined,
}) as Ref<DateRange>;
const buka = ref(false);

// periode 'kustom' = rentang dari kalender
function muat(periode: string, segarkan = false) {
    const { start, end } = rentang.value;
    const isian = periode === 'kustom' ? { dari: start?.toString(), sampai: end?.toString() } : { periode };
    router.get(admin.seo.index().url, { ...isian, ...(segarkan ? { segarkan: 1 } : {}) }, { preserveScroll: true, replace: true });
}

function pilih(r: DateRange) {
    if (!r.start || !r.end) return;
    buka.value = false;
    muat('kustom');
}
const kpi = [
    { label: 'Klik', kunci: 'klik', f: angka },
    { label: 'Tayangan', kunci: 'tayangan', f: angka },
    { label: 'CTR rata-rata', kunci: 'ctr', f: persen },
    { label: 'Posisi rata-rata', kunci: 'posisi', f: (n: number) => n.toLocaleString('id-ID'), turunBagus: true },
] as { label: string; kunci: keyof Ringkasan; f: (n: number) => string; turunBagus?: boolean }[];

// Warna = palet kategorikal tetap (slot 1-5), urutan jangan diubah agar warna tiap ukuran konsisten
const seri = computed<Seri[]>(() => {
    if (!ok.value) return [];
    const t = ok.value.tren;
    const posisi = (n: number) => n.toLocaleString('id-ID');
    return [
        { nama: 'Klik', warna: '#2a78d6', data: t.map((r) => r.klik), format: angka },
        { nama: 'Tayangan', warna: '#eb6834', data: t.map((r) => r.tayangan), format: angka },
        { nama: 'CTR', warna: '#1baf7a', data: t.map((r) => r.ctr), format: persen },
        { nama: 'Posisi', warna: '#eda100', data: t.map((r) => r.posisi), format: posisi, balik: true },
        ...(ok.value.pengunjung ? [{ nama: 'Pengunjung situs', warna: '#e87ba4', data: ok.value.pengunjung, format: angka }] : []),
    ];
});

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
                    <option v-if="periode === 'kustom'" value="kustom">Rentang tanggal</option>
                </select>
                <Popover v-model:open="buka">
                    <PopoverTrigger as-child>
                        <Button variant="outline" size="sm" class="font-normal">
                            <CalendarIcon class="size-4" />
                            {{ rentang.start && rentang.end ? `${tanggal(rentang.start.toString())} – ${tanggal(rentang.end.toString())}` : 'Pilih tanggal' }}
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent class="w-auto p-0" align="end">
                        <RangeCalendar v-model="rentang" locale="id-ID" :number-of-months="2" :min-value="batasAwal" :max-value="hariIni" @update:model-value="pilih" />
                    </PopoverContent>
                </Popover>
                <Button variant="outline" size="sm" @click="muat(periode, true)">Segarkan</Button>
            </div>
        </PageHeader>

        <p v-if="errors?.dari || errors?.sampai" class="rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">{{ errors.dari ?? errors.sampai }}</p>
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
                    <h2 class="mb-4 font-semibold text-ink">Tren harian <span class="text-xs font-normal text-muted-foreground">{{ tanggal(ok.dari) }} – {{ tanggal(ok.sampai) }}</span></h2>
                    <GrafikGabungan :label="ok.tren.map((t) => t.label)" :seri="seri" />
                    <p class="mt-2 text-xs text-muted-foreground">Tiap garis memakai skalanya sendiri; arahkan kursor untuk angka asli. Posisi dibalik: makin atas makin baik (1 = teratas). Pengunjung situs = analitik landing (tanpa trafik kantor). Klik nama di legenda untuk sembunyikan garis.</p>
                </div>
            </section>

            <section class="grid gap-4 lg:grid-cols-2">
                <TabelBatang class="lg:col-span-2" judul="Kata kunci" nama="Kueri" :baris="ok.kueri" :kolom="kolom" kosong="Belum ada kata kunci. Situs perlu terindeks Google dulu." />
                <TabelBatang judul="Halaman" nama="Halaman" :baris="ok.halaman" :kolom="kolom" kosong="Belum ada halaman tampil di Google." />
                <TabelBatang judul="Perangkat" nama="Perangkat" :baris="ok.perangkat.map((b) => ({ ...b, nama: PERANGKAT[b.nama] ?? b.nama }))" :kolom="kolom" />
                <TabelBatang judul="Negara" nama="Negara" :baris="ok.negara.map((b) => ({ ...b, nama: b.nama.toUpperCase() }))" :kolom="kolom" />
            </section>
            <p class="text-xs text-muted-foreground">Diambil {{ new Date(ok.diambil).toLocaleString('id-ID') }}, disimpan 3 jam. Klik Segarkan untuk ambil ulang.</p>
        </template>
    </div>
</template>
