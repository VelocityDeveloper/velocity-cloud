<script setup lang="ts">
// Tren analitik landing dalam satu grafik: Visitor, Sesi, Tampilan (satu sumbu y, semuanya hitungan).
// Konversi beda skala → strip batang sendiri di bawah (bukan sumbu kedua). Periode lalu & visitor baru di tooltip/tabel.
// Warna = slot 1–3 palet kategori dataviz (lolos validator all-pairs; aqua < 3:1 → label langsung + tabel).
import { useElementSize } from '@vueuse/core';
import { computed, ref } from 'vue';
import { angka } from '@/lib/analitik';

type Tren = { label: string; pengguna: number | null; sesi: number | null; tampilan: number | null; baru?: number | null; konversi?: number | null };
const props = defineProps<{ tren: Tren[]; lalu: Tren[] }>();

type Kunci = 'pengguna' | 'sesi' | 'tampilan';
const SERI: { kunci: Kunci; nama: string; warna: string }[] = [
    { kunci: 'pengguna', nama: 'Visitor', warna: '#2a78d6' },
    { kunci: 'sesi', nama: 'Sesi', warna: '#eb6834' },
    { kunci: 'tampilan', nama: 'Tampilan', warna: '#1baf7a' },
];
const tampil = ref<Record<Kunci, boolean>>({ pengguna: true, sesi: true, tampilan: true });
const seri = computed(() => SERI.filter((s) => tampil.value[s.kunci]));
function alih(k: Kunci) {
    if (tampil.value[k] && seri.value.length === 1) return; // minimal satu garis tetap tampil
    tampil.value[k] = !tampil.value[k];
}

const wadah = ref<HTMLElement | null>(null);
const { width } = useElementSize(wadah);
const T = 240;
const TK = 56; // tinggi strip konversi (judul + batang)
const pad = { kiri: 40, kanan: 84, atas: 12, bawah: 28 };
const lebar = computed(() => Math.max(width.value, 280));
const n = computed(() => props.tren.length);
const label = computed(() => props.tren.map((t) => t.label));
const pendek = (l: string) => (l.length === 10 ? l.slice(8) + '/' + l.slice(5, 7) : l);

// 4 garis bantu dengan langkah "bulat" (1, 2, 5 × 10^n), dihitung dari seri yang tampil saja
const langkah = computed(() => {
    const kasar = Math.max(1, ...seri.value.flatMap((s) => props.tren.map((t) => Number(t[s.kunci] ?? 0)))) / 4;
    const p = 10 ** Math.floor(Math.log10(Math.max(kasar, 1)));
    return [1, 2, 5, 10].map((k) => k * p).find((k) => k >= kasar) ?? 10 * p;
});
const maks = computed(() => langkah.value * 4);
const x = (i: number) => pad.kiri + (i * (lebar.value - pad.kiri - pad.kanan)) / Math.max(n.value - 1, 1);
const y = (v: number) => pad.atas + (1 - v / maks.value) * (T - pad.atas - pad.bawah);
// null (waktu yang belum terjadi) memutus garis
const garis = (k: Kunci) =>
    props.tren.map((t, i) => (t[k] === null ? '' : `${i && props.tren[i - 1][k] !== null ? 'L' : 'M'}${x(i).toFixed(1)},${y(t[k]!).toFixed(1)}`)).join('');
const gridY = computed(() => [0, 1, 2, 3, 4].map((i) => i * langkah.value));
const tiapLabel = computed(() => Math.ceil(n.value / Math.max(Math.floor(lebar.value / 70), 1)));

// Label langsung di ujung tiap garis (titik terakhir yang sudah terjadi), digeser agar tidak bertumpuk
const ujung = computed(() => {
    let i = n.value - 1;
    while (i > 0 && props.tren[i].pengguna === null) i--;
    const daftar = seri.value.map((s) => ({ ...s, i, ty: y(props.tren[i]?.[s.kunci] ?? 0) })).sort((a, b) => a.ty - b.ty);
    for (let j = 1; j < daftar.length; j++) daftar[j].ty = Math.max(daftar[j].ty, daftar[j - 1].ty + 13);
    return daftar;
});

const konversi = computed(() => props.tren.map((t) => t.konversi ?? null));
const maksKonversi = computed(() => Math.max(1, ...konversi.value.map(Number)));
const lebarBatang = computed(() => Math.max(2, Math.min(14, ((lebar.value - pad.kiri - pad.kanan) / Math.max(n.value, 1)) * 0.6)));
const totalKonversi = computed(() => konversi.value.reduce<number>((a, v) => a + (v ?? 0), 0));

const aktif = ref<number | null>(null);
function gerak(e: PointerEvent) {
    const r = (e.currentTarget as Element).getBoundingClientRect();
    const rel = (e.clientX - r.left - pad.kiri) / (lebar.value - pad.kiri - pad.kanan);
    aktif.value = Math.min(Math.max(Math.round(rel * (n.value - 1)), 0), n.value - 1);
}
const nilai = (v: number | null | undefined) => (v === null || v === undefined ? '-' : angka(v));
const rasio = (t?: Tren) => (t?.sesi ? (Number(t.tampilan) / t.sesi).toLocaleString('id-ID', { maximumFractionDigits: 2 }) : '-');
</script>

<template>
    <div ref="wadah" class="relative">
        <div class="mb-3 flex flex-wrap items-center gap-2 text-xs">
            <button
                v-for="s in SERI"
                :key="s.kunci"
                type="button"
                :aria-pressed="tampil[s.kunci]"
                class="flex items-center gap-1.5 rounded-md border px-2 py-1"
                :class="tampil[s.kunci] ? 'border-border text-ink' : 'border-dashed border-border text-muted-foreground'"
                :title="tampil[s.kunci] ? `Sembunyikan ${s.nama}` : `Tampilkan ${s.nama}`"
                @click="alih(s.kunci)"
            >
                <span class="h-0.5 w-4 rounded" :style="{ background: s.warna, opacity: tampil[s.kunci] ? 1 : 0.3 }" />{{ s.nama }}
            </button>
            <span class="ml-1 text-muted-foreground">Klik nama untuk sembunyikan/tampilkan.</span>
        </div>

        <svg :width="lebar" :height="T + TK" class="block touch-none" role="img" :aria-label="`Grafik tren ${seri.map((s) => s.nama).join(', ')} dan konversi`" @pointermove="gerak" @pointerleave="aktif = null">
            <g class="fill-muted-foreground text-[10px]">
                <template v-for="g in gridY" :key="g">
                    <line :x1="pad.kiri" :x2="lebar - pad.kanan" :y1="y(g)" :y2="y(g)" class="stroke-border" stroke-width="1" />
                    <text :x="pad.kiri - 6" :y="y(g) + 3" text-anchor="end">{{ angka(g) }}</text>
                </template>
                <template v-for="(l, i) in label" :key="l">
                    <text v-if="i % tiapLabel === 0" :x="x(i)" :y="T - 8" text-anchor="middle">{{ pendek(l) }}</text>
                </template>
            </g>
            <!-- Visitor digambar paling atas; cincin putih 2px memisahkan garis yang berimpit -->
            <template v-for="s in [...seri].reverse()" :key="s.kunci">
                <path :d="garis(s.kunci)" fill="none" stroke="#fff" stroke-width="6" stroke-linejoin="round" stroke-linecap="round" />
                <path :d="garis(s.kunci)" fill="none" :stroke="s.warna" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
            </template>
            <g class="text-[11px] font-medium">
                <text v-for="u in ujung" :key="u.kunci" :x="x(u.i) + 8" :y="u.ty + 4" class="fill-ink">{{ u.nama }}</text>
            </g>

            <!-- Strip konversi: skala sendiri, batang dari garis dasar -->
            <g :transform="`translate(0, ${T})`">
                <text :x="pad.kiri" y="12" class="fill-muted-foreground text-[11px]">Konversi (klik Pesan, WA, Daftar) · total {{ angka(totalKonversi) }}</text>
                <line :x1="pad.kiri" :x2="lebar - pad.kanan" :y1="TK - 6" :y2="TK - 6" class="stroke-border" stroke-width="1" />
                <template v-for="(v, i) in konversi" :key="i">
                    <rect v-if="v" :x="x(i) - lebarBatang / 2" :y="TK - 6 - (v / maksKonversi) * (TK - 26)" :width="lebarBatang" :height="(v / maksKonversi) * (TK - 26)" rx="2" class="fill-volt-600" />
                </template>
            </g>

            <g v-if="aktif !== null">
                <line :x1="x(aktif)" :x2="x(aktif)" :y1="pad.atas" :y2="T + TK - 6" class="stroke-slate-400" stroke-width="1" />
                <template v-for="s in [...seri].reverse()" :key="s.kunci">
                    <circle v-if="tren[aktif][s.kunci] !== null" :cx="x(aktif)" :cy="y(tren[aktif][s.kunci]!)" r="4.5" :fill="s.warna" stroke="#fff" stroke-width="2" />
                </template>
            </g>
        </svg>

        <div
            v-if="aktif !== null"
            class="pointer-events-none absolute top-10 z-10 w-64 rounded-md border border-border bg-white px-3 py-2 text-xs shadow-sm"
            :style="{ left: Math.max(0, Math.min(x(aktif) + 268 > lebar ? x(aktif) - 268 : x(aktif) + 12, lebar - 256)) + 'px' }"
        >
            <p class="font-semibold text-ink">{{ label[aktif] }}</p>
            <div class="mt-1 grid grid-cols-[1fr_auto_auto] gap-x-3 gap-y-0.5 tabular-nums">
                <span />
                <span class="text-right text-muted-foreground">Ini</span>
                <span class="text-right text-muted-foreground">Sebelumnya</span>
                <template v-for="s in SERI" :key="s.kunci">
                    <span class="flex items-center gap-1.5 text-muted-foreground"><span class="size-2 rounded-full" :style="{ background: s.warna }" />{{ s.nama }}</span>
                    <span class="text-right font-semibold text-ink">{{ nilai(tren[aktif][s.kunci]) }}</span>
                    <span class="text-right text-muted-foreground">{{ nilai(lalu[aktif]?.[s.kunci]) }}</span>
                </template>
                <span class="pl-3.5 text-muted-foreground">Visitor baru</span>
                <span class="text-right text-ink">{{ nilai(tren[aktif].baru) }}</span>
                <span class="text-right text-muted-foreground">{{ nilai(lalu[aktif]?.baru) }}</span>
                <span class="pl-3.5 text-muted-foreground">Tampilan/sesi</span>
                <span class="text-right text-ink">{{ rasio(tren[aktif]) }}</span>
                <span class="text-right text-muted-foreground">{{ rasio(lalu[aktif]) }}</span>
                <span class="flex items-center gap-1.5 text-muted-foreground"><span class="size-2 rounded-sm bg-volt-600" />Konversi</span>
                <span class="text-right font-semibold text-ink">{{ nilai(tren[aktif].konversi) }}</span>
                <span class="text-right text-muted-foreground">{{ nilai(lalu[aktif]?.konversi) }}</span>
            </div>
        </div>

        <details class="mt-3 text-xs">
            <summary class="cursor-pointer text-muted-foreground hover:text-ink">Lihat sebagai tabel</summary>
            <div class="mt-2 max-h-72 overflow-auto">
                <table class="w-full text-right tabular-nums">
                    <thead class="sticky top-0 bg-white text-muted-foreground">
                        <tr><th class="py-1 text-left font-medium">Waktu</th><th class="font-medium">Visitor</th><th class="font-medium">Baru</th><th class="font-medium">Sesi</th><th class="font-medium">Tampilan</th><th class="font-medium">Konversi</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="t in tren.filter((t) => t.pengguna !== null)" :key="t.label" class="border-t border-border">
                            <td class="py-1 text-left">{{ t.label }}</td><td>{{ nilai(t.pengguna) }}</td><td>{{ nilai(t.baru) }}</td><td>{{ nilai(t.sesi) }}</td><td>{{ nilai(t.tampilan) }}</td><td>{{ nilai(t.konversi) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </details>
    </div>
</template>
