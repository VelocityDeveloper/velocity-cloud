<script setup lang="ts">
// Tabel dimensi ala GA: kolom nama + metrik, batang tipis (proporsi metrik utama) di belakang nama.
import { computed, ref } from 'vue';

type Kolom = { kunci: string; label: string; format?: (v: any) => string };
const props = defineProps<{
    judul: string;
    keterangan?: string;
    baris: Record<string, any>[];
    nama: string;
    kolom: Kolom[];
    kosong?: string;
}>();

const semua = ref(false);
const utama = computed(() => props.kolom[0].kunci);
const maks = computed(() => Math.max(1, ...props.baris.map((b) => Number(b[utama.value]) || 0)));
const tampil = computed(() => (semua.value ? props.baris : props.baris.slice(0, 8)));
</script>

<template>
    <section class="overflow-x-auto rounded-lg border border-border bg-white">
        <header class="border-b border-border px-4 py-3">
            <h3 class="font-semibold text-ink">{{ judul }}</h3>
            <p v-if="keterangan" class="mt-0.5 text-xs text-muted-foreground">{{ keterangan }}</p>
        </header>
        <table v-if="baris.length" class="w-full text-sm tabular-nums">
            <thead class="text-xs text-muted-foreground">
                <tr>
                    <th class="px-4 py-2 text-left font-medium">{{ nama }}</th>
                    <th v-for="k in kolom" :key="k.kunci" class="px-4 py-2 text-right font-medium whitespace-nowrap">{{ k.label }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(b, i) in tampil" :key="i" class="border-t border-border">
                    <td class="relative max-w-0 min-w-40 px-4 py-2">
                        <span class="absolute inset-y-1 left-1 rounded-sm bg-brand-50" :style="{ width: `calc(${((Number(b[utama]) || 0) / maks) * 100}% - 8px)` }" aria-hidden="true" />
                        <span class="relative block truncate text-ink" :title="String(b.nama ?? b.path ?? '')"><slot name="nama" :baris="b">{{ b.nama ?? b.path }}</slot></span>
                    </td>
                    <td v-for="k in kolom" :key="k.kunci" class="px-4 py-2 text-right whitespace-nowrap">
                        {{ k.format ? k.format(b[k.kunci]) : (b[k.kunci] ?? '-') }}
                    </td>
                </tr>
            </tbody>
        </table>
        <p v-else class="px-4 py-8 text-center text-sm text-muted-foreground">{{ kosong ?? 'Belum ada data.' }}</p>
        <button v-if="baris.length > 8" type="button" class="w-full border-t border-border px-4 py-2 text-sm font-semibold text-brand-700 hover:bg-brand-50" @click="semua = !semua">
            {{ semua ? 'Tampilkan lebih sedikit' : `Lihat semua (${baris.length})` }}
        </button>
    </section>
</template>
