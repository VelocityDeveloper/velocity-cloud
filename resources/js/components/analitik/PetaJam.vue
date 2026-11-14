<script setup lang="ts">
// Peta panas tampilan per hari x jam: satu hue (navy), terang = sedikit, gelap = banyak.
import { computed, ref } from 'vue';
import { angka } from '@/lib/analitik';

const props = withDefaults(defineProps<{ data: [number, number, number][]; satuan?: string }>(), { satuan: 'tampilan' });
const HARI = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
const urutan = [1, 2, 3, 4, 5, 6, 0]; // %w SQLite: 0 = Minggu
const nilai = computed(() => {
    const m = new Map(props.data.map(([h, j, n]) => [`${h}-${j}`, n]));
    return (h: number, j: number) => m.get(`${h}-${j}`) ?? 0;
});
const maks = computed(() => Math.max(1, ...props.data.map((d) => d[2])));
const TINGKAT = ['#eef4f8', '#c9dbe7', '#93b6cc', '#4f86a6', '#165a82', '#0b2a3f'];
const warna = (n: number) => (n === 0 ? TINGKAT[0] : TINGKAT[Math.min(5, 1 + Math.floor((n / maks.value) * 4.999))]);
const aktif = ref<string>('');
</script>

<template>
    <div>
        <div class="overflow-x-auto">
            <table class="border-separate border-spacing-0.5 text-[10px] text-muted-foreground">
                <thead>
                    <tr>
                        <th />
                        <th v-for="j in 24" :key="j" class="w-5 font-normal">{{ (j - 1) % 3 === 0 ? j - 1 : '' }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(h, i) in urutan" :key="h">
                        <th class="pr-1 text-right font-normal">{{ HARI[i] }}</th>
                        <td
                            v-for="j in 24"
                            :key="j"
                            class="h-5 w-5 rounded-sm"
                            :style="{ background: warna(nilai(h, j - 1)) }"
                            :title="`${HARI[i]} ${String(j - 1).padStart(2, '0')}:00 · ${angka(nilai(h, j - 1))} ${props.satuan}`"
                            @pointerenter="aktif = `${HARI[i]} ${String(j - 1).padStart(2, '0')}:00 · ${angka(nilai(h, j - 1))} ${props.satuan}`"
                        />
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-3 text-xs text-muted-foreground">
            <span class="tabular-nums">{{ aktif || 'Arahkan kursor ke kotak untuk detail' }}</span>
            <span class="flex items-center gap-1">Sedikit <span v-for="t in TINGKAT" :key="t" class="size-3 rounded-sm" :style="{ background: t }" /> Banyak</span>
        </div>
    </div>
</template>
