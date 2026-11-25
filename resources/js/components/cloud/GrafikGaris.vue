<script setup lang="ts">
import { computed, ref } from 'vue';

// Grafik garis SVG ringan: banyak seri satu skala, area di bawah garis pertama, tooltip saat disorot.
export type Seri = { nama: string; warna: string; data: (number | null)[] };
const props = defineProps<{
    waktu: number[]; // epoch detik, sejajar dengan data tiap seri
    seri: Seri[];
    format: (n: number) => string;
    maks?: number; // batas atas tetap (mis. 100 untuk persen)
    tinggi?: number;
}>();

const W = 600;
const H = computed(() => props.tinggi ?? 140);
const P = { kiri: 4, kanan: 4, atas: 8, bawah: 18 };
const ymaks = computed(() => {
    if (props.maks) return props.maks;
    const m = Math.max(0, ...props.seri.flatMap((s) => s.data.filter((v): v is number => v !== null)));
    return m > 0 ? m * 1.15 : 1;
});
const x = (i: number) => P.kiri + (props.waktu.length > 1 ? (i / (props.waktu.length - 1)) * (W - P.kiri - P.kanan) : 0);
const y = (v: number) => P.atas + (1 - Math.min(v, ymaks.value) / ymaks.value) * (H.value - P.atas - P.bawah);

// Putus garis di titik kosong (data belum ada / penghitung di-reset).
const garis = computed(() =>
    props.seri.map((s) => {
        const ruas: string[] = [];
        let cur = '';
        s.data.forEach((v, i) => {
            if (v === null) {
                if (cur) ruas.push(cur);
                cur = '';
            } else cur += `${cur ? 'L' : 'M'}${x(i).toFixed(1)},${y(v).toFixed(1)}`;
        });
        if (cur) ruas.push(cur);
        return ruas;
    }),
);
const area = computed(() => {
    const d = props.seri[0]?.data ?? [];
    if (d.filter((v) => v !== null).length < 2) return '';
    const alas = H.value - P.bawah;
    let s = '';
    let awal = -1;
    d.forEach((v, i) => {
        if (v !== null && awal < 0) awal = i;
        if ((v === null || i === d.length - 1) && awal >= 0) {
            const akhir = v === null ? i - 1 : i;
            if (akhir > awal) {
                s += `M${x(awal).toFixed(1)},${alas}`;
                for (let j = awal; j <= akhir; j++) s += `L${x(j).toFixed(1)},${y(d[j] as number).toFixed(1)}`;
                s += `L${x(akhir).toFixed(1)},${alas}Z`;
            }
            awal = -1;
        }
    });
    return s;
});

const jam = (t: number) => new Date(t * 1000).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
const tgl = (t: number) => new Date(t * 1000).toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
const rentangHari = computed(() => (props.waktu.length > 1 ? props.waktu[props.waktu.length - 1] - props.waktu[0] > 86400 : false));
const label = (t: number) => (rentangHari.value ? tgl(t) : jam(t));

const sorot = ref<number | null>(null);
function gerak(e: PointerEvent) {
    const r = (e.currentTarget as SVGElement).getBoundingClientRect();
    const rel = ((e.clientX - r.left) / r.width) * W;
    sorot.value = props.waktu.length
        ? Math.min(props.waktu.length - 1, Math.max(0, Math.round(((rel - P.kiri) / (W - P.kiri - P.kanan)) * (props.waktu.length - 1))))
        : null;
}
</script>

<template>
    <div class="relative">
        <svg
            :viewBox="`0 0 ${W} ${H}`"
            class="block w-full touch-pan-y"
            role="img"
            :aria-label="seri.map((s) => s.nama).join(', ')"
            @pointermove="gerak"
            @pointerleave="sorot = null"
        >
            <line v-for="g in [0, 0.5, 1]" :key="g" :x1="P.kiri" :x2="W - P.kanan" :y1="y(ymaks * g)" :y2="y(ymaks * g)" class="stroke-border" stroke-width="1" :stroke-dasharray="g === 0 ? '' : '3 3'" />
            <path v-if="area" :d="area" :fill="seri[0].warna" fill-opacity="0.12" />
            <template v-for="(s, si) in seri" :key="s.nama">
                <path v-for="(d, di) in garis[si]" :key="di" :d="d" fill="none" :stroke="s.warna" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
            </template>
            <line v-if="sorot !== null" :x1="x(sorot)" :x2="x(sorot)" :y1="P.atas" :y2="H - P.bawah" class="stroke-muted-foreground" stroke-width="1" />
            <template v-if="sorot !== null">
                <circle v-for="s in seri.filter((s) => s.data[sorot!] !== null)" :key="s.nama" :cx="x(sorot)" :cy="y(s.data[sorot] as number)" r="3.5" :fill="s.warna" class="stroke-background" stroke-width="1.5" />
            </template>
            <text v-if="waktu.length" :x="P.kiri" :y="H - 4" class="fill-muted-foreground" font-size="10">{{ label(waktu[0]) }}</text>
            <text v-if="waktu.length > 1" :x="W - P.kanan" :y="H - 4" text-anchor="end" class="fill-muted-foreground" font-size="10">{{ label(waktu[waktu.length - 1]) }}</text>
        </svg>
        <p class="pointer-events-none absolute top-0 right-0 text-[10px] text-muted-foreground tabular-nums">maks {{ format(ymaks) }}</p>
        <div
            v-if="sorot !== null"
            class="pointer-events-none absolute top-1 z-10 rounded-md border border-border bg-background px-2 py-1 text-xs shadow-sm tabular-nums"
            :style="{ left: `clamp(0px, calc(${(x(sorot) / W) * 100}% - 4rem), calc(100% - 8rem))` }"
        >
            <p class="text-muted-foreground">{{ tgl(waktu[sorot]) }}</p>
            <p v-for="s in seri" :key="s.nama" class="flex items-center gap-1.5">
                <span class="size-2 rounded-full" :style="{ background: s.warna }" />
                {{ s.nama }}: <b>{{ s.data[sorot] === null ? '-' : format(s.data[sorot] as number) }}</b>
            </p>
        </div>
    </div>
</template>
