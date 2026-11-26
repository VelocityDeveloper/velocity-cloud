<script setup lang="ts">
// Analitik pemakaian token AI pelanggan (admin /admin/paket/ai). Data dari App\Services\TokenAi\LaporanToken.
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import GrafikTren from '@/components/analitik/GrafikTren.vue';
import PetaJam from '@/components/analitik/PetaJam.vue';
import TabelBatang from '@/components/analitik/TabelBatang.vue';
import { angka, persen, selisih } from '@/lib/analitik';
import { rupiah, tanggal } from '@/lib/format';
import admin from '@/routes/admin';

type Ringkasan = {
    request: number;
    token: number;
    prompt: number;
    completion: number;
    cache_hit: number;
    cache_rate: number;
    durasi: number;
    gagal: number;
    gagal_rate: number;
    taksiran: number;
    pelanggan: number;
    nilai: number;
};
type Hari = { label: string; request: number; token: number; cache_hit: number; nilai: number };
export type LaporanToken = {
    dari: string;
    sampai: string;
    periode: string;
    pilihan: Record<string, string>;
    ringkasan: Ringkasan;
    ringkasan_lalu: Ringkasan;
    tren: Hari[];
    tren_lalu: Hari[];
    pelanggan: {
        layanan_id: number;
        user_id: number;
        pengguna: string;
        layanan: string;
        nilai: number;
        token: number;
        request: number;
        cache_rate: number;
        gagal: number;
        sisa: number;
        kuota_terpakai: number;
        terakhir: string;
    }[];
    model: { nama: string; token: number; nilai: number; request: number; cache_rate: number }[];
    gagal: { nama: string; request: number }[];
    jam: [number, number, number][];
};
const props = defineProps<{ laporan: LaporanToken }>();
const r = computed(() => props.laporan.ringkasan);
const l = computed(() => props.laporan.ringkasan_lalu);

const ms = (n: number) => (n >= 1000 ? (n / 1000).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' dtk' : n + ' ms');
type Kpi = { label: string; kunci: keyof Ringkasan; f: (n: number) => string; turunBagus?: boolean; ket?: string };
// Angka utama tampil besar; sisanya baris ringkas di bawahnya.
const kpiUtama: Kpi[] = [
    { label: 'Nilai pemakaian', kunci: 'nilai', f: rupiah, ket: 'Saldo terpotong × harga output paket' },
    { label: 'Token terpakai', kunci: 'token', f: angka },
    { label: 'Request', kunci: 'request', f: angka },
    { label: 'Pelanggan aktif', kunci: 'pelanggan', f: angka },
];
const kpiLain: Kpi[] = [
    { label: 'Cache hit rate', kunci: 'cache_rate', f: persen },
    { label: 'Token input', kunci: 'prompt', f: angka },
    { label: 'Token output', kunci: 'completion', f: angka },
    { label: 'Durasi rata-rata', kunci: 'durasi', f: ms, turunBagus: true },
    { label: 'Request gagal', kunci: 'gagal_rate', f: persen, turunBagus: true },
    { label: 'Usage ditaksir', kunci: 'taksiran', f: angka, turunBagus: true },
];
// Warna & teks perbandingan dengan periode lalu.
function banding(k: Kpi) {
    const d = selisih(r.value[k.kunci], l.value[k.kunci]);
    if (d === null) return { teks: '– vs periode lalu', kelas: 'text-muted-foreground' };
    return {
        teks: `${d >= 0 ? '▲' : '▼'} ${persen(Math.abs(d))} vs ${k.f(l.value[k.kunci])}`,
        kelas: d === 0 ? 'text-muted-foreground' : (d > 0) !== !!k.turunBagus ? 'text-volt-700' : 'text-red-700',
    };
}

const ukuran = ref<'nilai' | 'token' | 'request' | 'cache_hit'>('nilai');
const namaUkuran = { nilai: 'Nilai (Rp)', token: 'Token', request: 'Request', cache_hit: 'Cache hit' };

// Tabel per pelanggan: urut berdasarkan kolom yang diklik, bisa dicari nama/layanan.
type Pelanggan = LaporanToken['pelanggan'][number];
const kolomUrut = { nilai: 'Nilai', token: 'Token', request: 'Request', kuota_terpakai: 'Kuota', terakhir: 'Terakhir' } as const;
const urut = ref<keyof typeof kolomUrut>('nilai');
const cari = ref('');
const daftarPelanggan = computed(() => {
    const q = cari.value.trim().toLowerCase();
    return props.laporan.pelanggan
        .filter((p) => !q || `${p.pengguna} ${p.layanan}`.toLowerCase().includes(q))
        .sort((a: Pelanggan, b: Pelanggan) => (a[urut.value] < b[urut.value] ? 1 : a[urut.value] > b[urut.value] ? -1 : 0));
});
const hampirHabis = computed(() => props.laporan.pelanggan.filter((p) => p.kuota_terpakai >= 0.9).length);

function ganti(e: Event) {
    // query lain (mis. ?tab=laporan di Admin › Paket) tetap dibawa
    const q = { ...Object.fromEntries(new URLSearchParams(window.location.search)), periode: (e.target as HTMLSelectElement).value };
    router.get(window.location.pathname, q, { preserveScroll: true, preserveState: true, replace: true });
}
</script>

<template>
    <section class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-ink">Pemakaian token pelanggan</h2>
                <p class="text-sm text-muted-foreground">{{ tanggal(laporan.dari) }} – {{ tanggal(laporan.sampai) }}, dibanding periode sebelumnya.</p>
            </div>
            <select :value="laporan.periode" class="h-9 rounded-md border border-input bg-white px-3 text-sm" aria-label="Periode" @change="ganti">
                <option v-for="(nama, k) in laporan.pilihan" :key="k" :value="k">{{ nama }}</option>
            </select>
        </div>

        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            <div v-for="(k, i) in kpiUtama" :key="k.kunci" class="rounded-lg border p-4" :class="i === 0 ? 'border-brand-100 bg-brand-50' : 'border-border bg-white'">
                <p class="text-xs text-muted-foreground">{{ k.label }}</p>
                <p class="mt-1 font-heading text-2xl font-bold text-ink tabular-nums md:text-3xl">{{ k.f(r[k.kunci]) }}</p>
                <p class="mt-1 text-xs tabular-nums" :class="banding(k).kelas">{{ banding(k).teks }}</p>
                <p v-if="k.ket" class="mt-1 text-xs text-muted-foreground">{{ k.ket }}</p>
            </div>
        </div>

        <dl class="grid grid-cols-2 divide-border rounded-lg border border-border bg-white sm:grid-cols-3 xl:grid-cols-6 xl:divide-x">
            <div v-for="k in kpiLain" :key="k.kunci" class="px-4 py-3">
                <dt class="text-xs text-muted-foreground">{{ k.label }}</dt>
                <dd class="mt-0.5 font-semibold text-ink tabular-nums">{{ k.f(r[k.kunci]) }}</dd>
                <dd class="text-xs tabular-nums" :class="banding(k).kelas">{{ banding(k).teks }}</dd>
            </div>
        </dl>

        <div class="rounded-lg border border-border bg-white p-4">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h3 class="font-semibold text-ink">Tren harian</h3>
                <div class="flex rounded-md border border-border p-0.5 text-sm" role="tablist">
                    <button
                        v-for="(nama, k) in namaUkuran"
                        :key="k"
                        type="button"
                        role="tab"
                        :aria-selected="ukuran === k"
                        class="rounded px-3 py-1"
                        :class="ukuran === k ? 'bg-brand-700 text-white' : 'text-muted-foreground hover:text-ink'"
                        @click="ukuran = k"
                    >
                        {{ nama }}
                    </button>
                </div>
            </div>
            <GrafikTren
                :label="laporan.tren.map((t) => t.label)"
                :kini="laporan.tren.map((t) => t[ukuran])"
                :lalu="laporan.tren_lalu.map((t) => t[ukuran])"
                :nama="namaUkuran[ukuran]"
            />
        </div>

        <section class="rounded-lg border border-border bg-white">
            <header class="flex flex-wrap items-end justify-between gap-3 border-b border-border px-4 py-3">
                <div>
                    <h3 class="font-semibold text-ink">Per pelanggan</h3>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        Kuota = sisa saldo + total terpakai sejak beli. Klik judul kolom untuk mengurutkan.
                        <span v-if="hampirHabis" class="font-semibold text-red-700">{{ hampirHabis }} layanan kuotanya ≥ 90%.</span>
                    </p>
                </div>
                <input
                    v-if="laporan.pelanggan.length > 5"
                    v-model="cari"
                    type="search"
                    placeholder="Cari pelanggan / layanan"
                    aria-label="Cari pelanggan"
                    class="h-9 w-full rounded-md border border-input bg-white px-3 text-sm sm:w-64"
                />
            </header>
            <div v-if="daftarPelanggan.length" class="overflow-x-auto">
                <table class="w-full text-sm tabular-nums">
                    <thead class="text-xs text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium">Pelanggan · layanan</th>
                            <th
                                v-for="(nama, k) in { nilai: kolomUrut.nilai, token: kolomUrut.token, request: kolomUrut.request }"
                                :key="k"
                                class="px-4 py-2 text-right font-medium"
                                :aria-sort="urut === k ? 'descending' : 'none'"
                            >
                                <button type="button" class="hover:text-ink" :class="urut === k ? 'font-semibold text-ink' : ''" @click="urut = k">
                                    {{ nama }}{{ urut === k ? ' ↓' : '' }}
                                </button>
                            </th>
                            <th class="px-4 py-2 text-right font-medium">Cache</th>
                            <th class="px-4 py-2 text-right font-medium">Gagal</th>
                            <th class="px-4 py-2 text-right font-medium whitespace-nowrap">Sisa saldo</th>
                            <th v-for="(nama, k) in { kuota_terpakai: kolomUrut.kuota_terpakai, terakhir: kolomUrut.terakhir }" :key="k" class="px-4 py-2 text-right font-medium" :aria-sort="urut === k ? 'descending' : 'none'">
                                <button type="button" class="hover:text-ink" :class="urut === k ? 'font-semibold text-ink' : ''" @click="urut = k">
                                    {{ nama }}{{ urut === k ? ' ↓' : '' }}
                                </button>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in daftarPelanggan" :key="p.layanan_id" class="border-t border-border">
                            <td class="px-4 py-2">
                                <a :href="admin.pengguna.show(p.user_id).url" class="font-medium text-ink hover:text-brand-700 hover:underline">{{ p.pengguna }}</a>
                                <span class="block text-xs text-muted-foreground">{{ p.layanan }}</span>
                            </td>
                            <td class="px-4 py-2 text-right font-semibold text-ink whitespace-nowrap">{{ rupiah(p.nilai) }}</td>
                            <td class="px-4 py-2 text-right">{{ angka(p.token) }}</td>
                            <td class="px-4 py-2 text-right">{{ angka(p.request) }}</td>
                            <td class="px-4 py-2 text-right">{{ persen(p.cache_rate) }}</td>
                            <td class="px-4 py-2 text-right" :class="p.gagal ? 'text-red-700' : ''">{{ angka(p.gagal) }}</td>
                            <td class="px-4 py-2 text-right">{{ angka(p.sisa) }}</td>
                            <td class="px-4 py-2 text-right">
                                <span :class="p.kuota_terpakai >= 0.9 ? 'font-semibold text-red-700' : ''">{{ persen(p.kuota_terpakai) }}</span>
                                <span class="mt-1 ml-auto block h-1 w-16 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                                    <span
                                        class="block h-full rounded-full"
                                        :class="p.kuota_terpakai >= 0.9 ? 'bg-red-600' : 'bg-brand-600'"
                                        :style="{ width: Math.min(100, p.kuota_terpakai * 100) + '%' }"
                                    />
                                </span>
                            </td>
                            <td class="px-4 py-2 text-right whitespace-nowrap text-muted-foreground">{{ p.terakhir }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="px-4 py-8 text-center text-sm text-muted-foreground">
                {{ laporan.pelanggan.length ? 'Tidak ada pelanggan yang cocok.' : 'Belum ada pemakaian di periode ini.' }}
            </p>
        </section>

        <div class="grid gap-4 xl:grid-cols-[3fr_2fr]">
            <TabelBatang
                judul="Per model"
                nama="Model"
                :baris="laporan.model"
                :kolom="[
                    { kunci: 'token', label: 'Token', format: angka },
                    { kunci: 'nilai', label: 'Nilai', format: rupiah },
                    { kunci: 'request', label: 'Request', format: angka },
                    { kunci: 'cache_rate', label: 'Cache', format: persen },
                ]"
            />
            <TabelBatang
                judul="Request gagal"
                keterangan="Tidak memotong saldo pelanggan."
                nama="Status"
                :baris="laporan.gagal"
                :kolom="[{ kunci: 'request', label: 'Request', format: angka }]"
                kosong="Tidak ada request gagal."
            />
        </div>

        <div class="rounded-lg border border-border bg-white p-4">
            <h3 class="mb-3 font-semibold text-ink">Request per hari & jam</h3>
            <PetaJam :data="laporan.jam" satuan="request" />
        </div>
    </section>
</template>
