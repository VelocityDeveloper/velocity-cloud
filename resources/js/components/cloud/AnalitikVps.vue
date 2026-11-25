<script setup lang="ts">
import { Activity, Cpu, ListTree, MemoryStick, Network } from '@lucide/vue';
import { computed } from 'vue';
import GrafikGaris from '@/components/cloud/GrafikGaris.vue';
import { Button } from '@/components/ui/button';

// Titik grafik: cpu = persen, ram/ram_total = byte, proses = jumlah, rx/tx = byte per detik.
export type TitikMetrik = { t: number; cpu: number | null; ram: number | null; ram_total: number | null; proses: number | null; rx: number | null; tx: number | null };
const props = defineProps<{ titik: TitikMetrik[]; rentang: string; memuat: boolean }>();
const emit = defineEmits<{ (e: 'rentang', r: string): void }>();

const PILIHAN = [
    ['live', 'Live'],
    ['1j', '1 jam'],
    ['6j', '6 jam'],
    ['24j', '24 jam'],
    ['7h', '7 hari'],
];
const waktu = computed(() => props.titik.map((p) => p.t));
const kolom = (k: keyof TitikMetrik) => props.titik.map((p) => p[k] as number | null);
const gib = (b: number) => (b >= 1073741824 ? `${(b / 1073741824).toFixed(2)} GiB` : `${Math.round(b / 1048576)} MiB`);
const laju = (b: number) => (b >= 1048576 ? `${(b / 1048576).toFixed(2)} MiB/s` : `${(b / 1024).toFixed(1)} KiB/s`);
const terakhir = (k: keyof TitikMetrik) => [...props.titik].reverse().find((p) => p[k] !== null)?.[k] as number | undefined;
const ramTotal = computed(() => terakhir('ram_total') ?? 0);
const kartu = computed(() => [
    { nama: 'CPU', nilai: terakhir('cpu') === undefined ? '-' : `${terakhir('cpu')!.toFixed(1)}%`, ikon: Cpu },
    { nama: 'RAM', nilai: terakhir('ram') === undefined ? '-' : `${gib(terakhir('ram')!)}${ramTotal.value ? ' / ' + gib(ramTotal.value) : ''}`, ikon: MemoryStick },
    { nama: 'Proses', nilai: terakhir('proses') === undefined ? '-' : String(Math.round(terakhir('proses')!)), ikon: ListTree },
    { nama: 'Jaringan', nilai: terakhir('rx') === undefined ? '-' : `↓ ${laju(terakhir('rx')!)} · ↑ ${laju(terakhir('tx') ?? 0)}`, ikon: Network },
]);
const WARNA = { a: 'var(--color-brand-700, #2563eb)', b: 'var(--color-volt, #16a34a)' };
</script>

<template>
    <section class="rounded-lg border border-border">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-3">
            <h2 class="flex items-center gap-2 font-semibold text-ink"><Activity class="size-4" aria-hidden="true" />Analitik penggunaan</h2>
            <div class="flex flex-wrap gap-1" role="group" aria-label="Rentang waktu">
                <Button
                    v-for="[k, nama] in PILIHAN"
                    :key="k"
                    size="sm"
                    :variant="rentang === k ? 'default' : 'outline'"
                    :aria-pressed="rentang === k"
                    :disabled="memuat && rentang !== k"
                    @click="emit('rentang', k)"
                >
                    {{ nama }}
                </Button>
            </div>
        </div>
        <div class="grid grid-cols-2 gap-3 px-5 pt-4 lg:grid-cols-4">
            <div v-for="k in kartu" :key="k.nama" class="rounded-md bg-muted/50 px-3 py-2">
                <p class="flex items-center gap-1.5 text-xs text-muted-foreground"><component :is="k.ikon" class="size-3.5" aria-hidden="true" />{{ k.nama }}</p>
                <p class="text-sm font-semibold text-ink tabular-nums">{{ k.nilai }}</p>
            </div>
        </div>
        <p v-if="titik.length < 2" class="px-5 py-8 text-center text-sm text-muted-foreground">
            {{ memuat ? 'Memuat grafik…' : rentang === 'live' ? 'Mengumpulkan data realtime…' : 'Belum ada data untuk rentang ini. Sampel diambil tiap menit.' }}
        </p>
        <div v-else class="grid gap-6 px-5 py-4 lg:grid-cols-2">
            <div>
                <h3 class="mb-1 flex items-center gap-1.5 text-xs font-semibold text-muted-foreground"><Cpu class="size-3.5" aria-hidden="true" />CPU (%)</h3>
                <GrafikGaris :waktu="waktu" :seri="[{ nama: 'CPU', warna: WARNA.a, data: kolom('cpu') }]" :format="(n) => `${n.toFixed(1)}%`" :maks="100" />
            </div>
            <div>
                <h3 class="mb-1 flex items-center gap-1.5 text-xs font-semibold text-muted-foreground"><MemoryStick class="size-3.5" aria-hidden="true" />RAM</h3>
                <GrafikGaris :waktu="waktu" :seri="[{ nama: 'RAM', warna: WARNA.a, data: kolom('ram') }]" :format="gib" :maks="ramTotal || undefined" />
            </div>
            <div>
                <h3 class="mb-1 flex items-center gap-1.5 text-xs font-semibold text-muted-foreground"><ListTree class="size-3.5" aria-hidden="true" />Proses</h3>
                <GrafikGaris :waktu="waktu" :seri="[{ nama: 'Proses', warna: WARNA.a, data: kolom('proses') }]" :format="(n) => String(Math.round(n))" />
            </div>
            <div>
                <h3 class="mb-1 flex items-center gap-1.5 text-xs font-semibold text-muted-foreground">
                    <Network class="size-3.5" aria-hidden="true" />Jaringan
                    <span class="ml-2 inline-flex items-center gap-1 font-normal"><span class="size-2 rounded-full" :style="{ background: WARNA.a }" />masuk</span>
                    <span class="inline-flex items-center gap-1 font-normal"><span class="size-2 rounded-full" :style="{ background: WARNA.b }" />keluar</span>
                </h3>
                <GrafikGaris :waktu="waktu" :seri="[{ nama: 'Masuk', warna: WARNA.a, data: kolom('rx') }, { nama: 'Keluar', warna: WARNA.b, data: kolom('tx') }]" :format="laju" />
            </div>
        </div>
    </section>
</template>
