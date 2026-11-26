<script setup lang="ts">
// Grafik garis satu ukuran: periode ini (navy, 2px) vs periode lalu (abu-abu putus-putus). Satu sumbu y.
// Hover: garis bantu + tooltip berisi kedua nilai.
import { useElementSize } from '@vueuse/core';
import { computed, ref } from 'vue';
import { angka } from '@/lib/analitik';

const props = defineProps<{
    label: string[];
    kini: (number | null)[];
    lalu: (number | null)[];
    nama: string;
}>();

const wadah = ref<HTMLElement | null>(null);
const { width } = useElementSize(wadah);
const T = 240;
// Label sumbu Y ringkas (20 rb, 1,5 jt) agar angka besar tidak terpotong di kiri.
const ringkas = (n: number) => n.toLocaleString('id-ID', { notation: 'compact', maximumFractionDigits: 1 });
const pad = { kiri: 40, kanan: 12, atas: 12, bawah: 28 };
const lebar = computed(() => Math.max(width.value, 280));
// 4 garis bantu dengan langkah "bulat" (1, 2, 5 × 10^n)
const langkah = computed(() => {
    const kasar = Math.max(1, Math.max(...props.kini.map(Number), ...props.lalu.map(Number)) / 4); // nilai = hitungan, langkah min 1
    const p = 10 ** Math.floor(Math.log10(kasar));
    return [1, 2, 5, 10].map((k) => k * p).find((k) => k >= kasar) ?? 10 * p;
});
const maks = computed(() => langkah.value * 4);
const x = (i: number) =>
    pad.kiri + (i * (lebar.value - pad.kiri - pad.kanan)) / Math.max(props.label.length - 1, 1);
const y = (v: number) => pad.atas + (1 - v / maks.value) * (T - pad.atas - pad.bawah);
// null (waktu yang belum terjadi) memutus garis
const garis = (d: (number | null)[]) =>
    d.map((v, i) => (v === null ? '' : `${i && d[i - 1] !== null ? 'L' : 'M'}${x(i).toFixed(1)},${y(v).toFixed(1)}`)).join('');
const gridY = computed(() => [0, 1, 2, 3, 4].map((i) => i * langkah.value));
const tiapLabel = computed(() => Math.ceil(props.label.length / Math.max(Math.floor(lebar.value / 70), 1)));

const aktif = ref<number | null>(null);
function gerak(e: PointerEvent) {
    const r = (e.currentTarget as SVGElement).getBoundingClientRect();
    const rel = (e.clientX - r.left - pad.kiri) / (lebar.value - pad.kiri - pad.kanan);
    aktif.value = Math.min(Math.max(Math.round(rel * (props.label.length - 1)), 0), props.label.length - 1);
}
</script>

<template>
    <div ref="wadah" class="relative">
        <div class="mb-3 flex flex-wrap gap-4 text-xs text-muted-foreground">
            <span class="flex items-center gap-1.5"><span class="h-0.5 w-4 rounded bg-brand-700" />{{ nama }} periode ini</span>
            <span class="flex items-center gap-1.5"><span class="w-4 border-t-2 border-dashed border-slate-400" />Periode sebelumnya</span>
        </div>
        <svg
            :width="lebar"
            :height="T"
            class="block touch-none"
            role="img"
            :aria-label="`Grafik ${nama}`"
            @pointermove="gerak"
            @pointerleave="aktif = null"
        >
            <g class="text-[10px] fill-muted-foreground">
                <template v-for="g in gridY" :key="g">
                    <line :x1="pad.kiri" :x2="lebar - pad.kanan" :y1="y(g)" :y2="y(g)" class="stroke-border" stroke-width="1" />
                    <text :x="pad.kiri - 6" :y="y(g) + 3" text-anchor="end">{{ ringkas(g) }}</text>
                </template>
                <template v-for="(l, i) in label" :key="l">
                    <text v-if="i % tiapLabel === 0" :x="x(i)" :y="T - 8" text-anchor="middle">{{ l.length === 10 ? l.slice(8) + '/' + l.slice(5, 7) : l }}</text>
                </template>
            </g>
            <path :d="garis(lalu)" fill="none" class="stroke-slate-400" stroke-width="2" stroke-dasharray="4 4" stroke-linejoin="round" />
            <path :d="garis(kini)" fill="none" class="stroke-brand-700" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
            <g v-if="aktif !== null">
                <line :x1="x(aktif)" :x2="x(aktif)" :y1="pad.atas" :y2="T - pad.bawah" class="stroke-slate-400" stroke-width="1" />
                <circle v-if="kini[aktif] !== null" :cx="x(aktif)" :cy="y(kini[aktif]!)" r="4.5" class="fill-brand-700 stroke-white" stroke-width="2" />
            </g>
        </svg>
        <div
            v-if="aktif !== null"
            class="pointer-events-none absolute top-8 z-10 rounded-md border border-border bg-white px-3 py-2 text-xs shadow-sm"
            :style="{ left: Math.min(x(aktif) + 12, lebar - 170) + 'px' }"
        >
            <p class="font-semibold text-ink">{{ label[aktif] }}</p>
            <p class="mt-1 flex justify-between gap-4"><span class="text-muted-foreground">Periode ini</span><span class="font-semibold tabular-nums">{{ kini[aktif] === null ? '-' : angka(kini[aktif]!) }}</span></p>
            <p class="flex justify-between gap-4"><span class="text-muted-foreground">Sebelumnya</span><span class="tabular-nums">{{ lalu[aktif] === null ? '-' : angka(lalu[aktif] ?? 0) }}</span></p>
        </div>
    </div>
</template>
