<script setup lang="ts">
// Beberapa ukuran beda satuan dalam satu grafik (pola Search Console): tiap garis diskalakan ke rentangnya
// sendiri (0..maks), jadi sumbu y tanpa angka; nilai asli tampil di tooltip. Garis `balik` (posisi) dibalik: kecil = atas.
// Legenda = tombol tampil/sembunyi.
import { useElementSize } from '@vueuse/core';
import { computed, ref } from 'vue';

export type Seri = { nama: string; warna: string; data: (number | null)[]; format: (n: number) => string; balik?: boolean };
const props = defineProps<{ label: string[]; seri: Seri[] }>();

const wadah = ref<HTMLElement | null>(null);
const { width } = useElementSize(wadah);
const T = 260;
const pad = { kiri: 12, kanan: 12, atas: 12, bawah: 28 };
const lebar = computed(() => Math.max(width.value, 280));
const sembunyi = ref<Set<string>>(new Set());
const tampil = computed(() => props.seri.filter((s) => !sembunyi.value.has(s.nama)));
function alih(nama: string) {
    const s = new Set(sembunyi.value);
    s.has(nama) ? s.delete(nama) : s.add(nama);
    sembunyi.value = s;
}

const x = (i: number) => pad.kiri + (i * (lebar.value - pad.kiri - pad.kanan)) / Math.max(props.label.length - 1, 1);
// rasio 0..1 → koordinat; 0 = garis dasar
const y = (r: number) => pad.atas + (1 - r) * (T - pad.atas - pad.bawah);
function rasio(s: Seri): (number | null)[] {
    const ada = s.data.filter((v): v is number => v !== null);
    if (!s.balik) {
        const maks = Math.max(...ada, 0) || 1;
        return s.data.map((v) => (v === null ? null : v / maks));
    }
    const [min, maks] = [Math.min(...ada), Math.max(...ada)];
    return s.data.map((v) => (v === null ? null : maks === min ? 0.5 : 0.05 + (0.95 * (maks - v)) / (maks - min)));
}
const garis = computed(() =>
    tampil.value.map((s) => {
        const r = rasio(s);
        return { s, r, d: r.map((v, i) => (v === null ? '' : `${i && r[i - 1] !== null ? 'L' : 'M'}${x(i).toFixed(1)},${y(v).toFixed(1)}`)).join('') };
    }),
);
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
        <div class="mb-3 flex flex-wrap gap-2 text-xs">
            <button
                v-for="s in seri"
                :key="s.nama"
                type="button"
                :aria-pressed="!sembunyi.has(s.nama)"
                class="flex items-center gap-1.5 rounded-md border px-2.5 py-1"
                :class="sembunyi.has(s.nama) ? 'border-border text-muted-foreground line-through' : 'border-border text-ink'"
                @click="alih(s.nama)"
            >
                <span class="h-0.5 w-4 rounded" :style="{ background: sembunyi.has(s.nama) ? '#cbd5e1' : s.warna }" />{{ s.nama }}
            </button>
        </div>
        <svg :width="lebar" :height="T" class="block touch-none" role="img" :aria-label="`Grafik ${seri.map((s) => s.nama).join(', ')}`" @pointermove="gerak" @pointerleave="aktif = null">
            <g class="text-[10px] fill-muted-foreground">
                <line v-for="g in [0, 0.25, 0.5, 0.75, 1]" :key="g" :x1="pad.kiri" :x2="lebar - pad.kanan" :y1="y(g)" :y2="y(g)" class="stroke-border" stroke-width="1" />
                <template v-for="(l, i) in label" :key="l + i">
                    <text v-if="i % tiapLabel === 0" :x="x(i)" :y="T - 8" text-anchor="middle">{{ l }}</text>
                </template>
            </g>
            <path v-for="g in garis" :key="g.s.nama" :d="g.d" fill="none" :stroke="g.s.warna" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
            <g v-if="aktif !== null">
                <line :x1="x(aktif)" :x2="x(aktif)" :y1="pad.atas" :y2="T - pad.bawah" class="stroke-slate-400" stroke-width="1" />
                <template v-for="g in garis" :key="g.s.nama">
                    <circle v-if="g.r[aktif] !== null" :cx="x(aktif)" :cy="y(g.r[aktif]!)" r="4.5" :fill="g.s.warna" stroke="white" stroke-width="2" />
                </template>
            </g>
        </svg>
        <div
            v-if="aktif !== null && tampil.length"
            class="pointer-events-none absolute top-12 z-10 rounded-md border border-border bg-white px-3 py-2 text-xs shadow-sm"
            :style="{ left: (x(aktif) > lebar / 2 ? x(aktif) - 192 : x(aktif) + 12) + 'px' }"
        >
            <p class="font-semibold text-ink">{{ label[aktif] }}</p>
            <p v-for="s in tampil" :key="s.nama" class="mt-1 flex w-40 items-center justify-between gap-4">
                <span class="flex items-center gap-1.5 text-muted-foreground"><span class="size-2 rounded-full" :style="{ background: s.warna }" />{{ s.nama }}</span>
                <span class="font-semibold tabular-nums text-ink">{{ s.data[aktif] === null ? '-' : s.format(s.data[aktif]!) }}</span>
            </p>
        </div>
    </div>
</template>
