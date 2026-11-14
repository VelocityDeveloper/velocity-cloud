<script setup lang="ts">
// Analitik pemakaian token AI pelanggan (admin /admin/paket/ai). Data dari App\Services\TokenAi\LaporanToken.
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import GrafikTren from '@/components/analitik/GrafikTren.vue';
import PetaJam from '@/components/analitik/PetaJam.vue';
import TabelBatang from '@/components/analitik/TabelBatang.vue';
import { angka, persen, selisih } from '@/lib/analitik';
import { tanggal } from '@/lib/format';

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
};
type Hari = { label: string; request: number; token: number; cache_hit: number };
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
        nama: string;
        token: number;
        request: number;
        cache_rate: number;
        gagal: number;
        sisa: number;
        kuota_terpakai: number;
        terakhir: string;
    }[];
    model: { nama: string; token: number; request: number; cache_rate: number }[];
    gagal: { nama: string; request: number }[];
    jam: [number, number, number][];
};
const props = defineProps<{ laporan: LaporanToken }>();
const r = computed(() => props.laporan.ringkasan);
const l = computed(() => props.laporan.ringkasan_lalu);

const ms = (n: number) => (n >= 1000 ? (n / 1000).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' dtk' : n + ' ms');
const kpi = [
    { label: 'Token terpakai', kunci: 'token', f: angka },
    { label: 'Request', kunci: 'request', f: angka },
    { label: 'Cache hit (token input)', kunci: 'cache_hit', f: angka },
    { label: 'Cache hit rate', kunci: 'cache_rate', f: persen },
    { label: 'Pelanggan aktif', kunci: 'pelanggan', f: angka },
    { label: 'Token input', kunci: 'prompt', f: angka },
    { label: 'Token output', kunci: 'completion', f: angka },
    { label: 'Durasi rata-rata', kunci: 'durasi', f: ms, turunBagus: true },
    { label: 'Request gagal', kunci: 'gagal_rate', f: persen, turunBagus: true },
    { label: 'Usage ditaksir', kunci: 'taksiran', f: angka, turunBagus: true },
] as { label: string; kunci: keyof Ringkasan; f: (n: number) => string; turunBagus?: boolean }[];

const ukuran = ref<'token' | 'request' | 'cache_hit'>('token');
const namaUkuran = { token: 'Token', request: 'Request', cache_hit: 'Cache hit' };

function ganti(e: Event) {
    router.get(window.location.pathname, { periode: (e.target as HTMLSelectElement).value }, { preserveScroll: true, preserveState: true, replace: true });
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

        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
            <div v-for="k in kpi" :key="k.kunci" class="rounded-lg border border-border bg-white p-4">
                <p class="text-xs text-muted-foreground">{{ k.label }}</p>
                <p class="mt-1 font-heading text-2xl font-bold text-ink tabular-nums">{{ k.f(r[k.kunci]) }}</p>
                <p
                    class="mt-1 text-xs tabular-nums"
                    :class="
                        selisih(r[k.kunci], l[k.kunci]) === null
                            ? 'text-muted-foreground'
                            : (selisih(r[k.kunci], l[k.kunci])! >= 0) !== !!k.turunBagus
                              ? 'text-volt-700'
                              : 'text-red-700'
                    "
                >
                    <template v-if="selisih(r[k.kunci], l[k.kunci]) === null">– vs periode lalu</template>
                    <template v-else
                        >{{ selisih(r[k.kunci], l[k.kunci])! >= 0 ? '▲' : '▼' }} {{ persen(Math.abs(selisih(r[k.kunci], l[k.kunci])!)) }} vs
                        {{ k.f(l[k.kunci]) }}</template
                    >
                </p>
            </div>
        </div>

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

        <section class="overflow-x-auto rounded-lg border border-border bg-white">
            <header class="border-b border-border px-4 py-3">
                <h3 class="font-semibold text-ink">Per pelanggan</h3>
                <p class="mt-0.5 text-xs text-muted-foreground">Kuota = sisa saldo + total terpakai sejak beli. Merah = kuota terpakai ≥ 90%.</p>
            </header>
            <table v-if="laporan.pelanggan.length" class="w-full text-sm tabular-nums">
                <thead class="text-xs text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium">Pelanggan · layanan</th>
                        <th class="px-4 py-2 text-right font-medium">Token</th>
                        <th class="px-4 py-2 text-right font-medium">Request</th>
                        <th class="px-4 py-2 text-right font-medium">Cache</th>
                        <th class="px-4 py-2 text-right font-medium">Gagal</th>
                        <th class="px-4 py-2 text-right font-medium">Sisa saldo</th>
                        <th class="px-4 py-2 text-right font-medium">Kuota</th>
                        <th class="px-4 py-2 text-right font-medium whitespace-nowrap">Terakhir</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in laporan.pelanggan" :key="p.layanan_id" class="border-t border-border">
                        <td class="px-4 py-2">
                            <span class="font-medium text-ink">{{ p.nama }}</span>
                        </td>
                        <td class="px-4 py-2 text-right">{{ angka(p.token) }}</td>
                        <td class="px-4 py-2 text-right">{{ angka(p.request) }}</td>
                        <td class="px-4 py-2 text-right">{{ persen(p.cache_rate) }}</td>
                        <td class="px-4 py-2 text-right" :class="p.gagal ? 'text-red-700' : ''">{{ angka(p.gagal) }}</td>
                        <td class="px-4 py-2 text-right">{{ angka(p.sisa) }}</td>
                        <td class="px-4 py-2 text-right" :class="p.kuota_terpakai >= 0.9 ? 'font-semibold text-red-700' : ''">
                            {{ persen(p.kuota_terpakai) }}
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap text-muted-foreground">{{ p.terakhir }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="px-4 py-8 text-center text-sm text-muted-foreground">Belum ada pemakaian di periode ini.</p>
        </section>

        <div class="grid gap-4 xl:grid-cols-2">
            <TabelBatang
                judul="Per model"
                nama="Model"
                :baris="laporan.model"
                :kolom="[
                    { kunci: 'token', label: 'Token', format: angka },
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
