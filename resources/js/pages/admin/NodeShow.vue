<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Activity, Cpu, Gauge, HardDrive, MemoryStick, Network } from '@lucide/vue';
import { computed, ref } from 'vue';
import GrafikGaris from '@/components/cloud/GrafikGaris.vue';
import PageHeader from '@/components/cloud/PageHeader.vue';
import StatusBadge from '@/components/cloud/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import admin from '@/routes/admin';

type Titik = {
    t: number;
    cpu: number | null;
    ram: number | null;
    ram_total: number | null;
    disk: number | null;
    disk_total: number | null;
    load: number | null;
    rx: number | null;
    tx: number | null;
};
const props = defineProps<{
    node: {
        id: number;
        nama: string;
        jenis: string;
        jenis_nama: string;
        agent_url: string;
        kapasitas_pod: number;
        ram_pod_mb: number | null;
        aktif: boolean;
        layanan: number;
    };
    terkini: {
        waktu: number;
        cpu: number | null;
        inti: number | null;
        ram: number | null;
        ram_total: number | null;
        disk: number | null;
        disk_total: number | null;
        load: number | null;
        uptime: number | null;
    } | null;
    riwayat: { rentang: string; titik: Titik[] };
}>();
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Admin · Node', href: admin.node.index() }],
    },
});

const PILIHAN = [
    ['1j', '1 jam'],
    ['6j', '6 jam'],
    ['24j', '24 jam'],
    ['7h', '7 hari'],
];
const memuat = ref(false);
function pilih(r: string) {
    memuat.value = true;
    router.reload({ only: ['riwayat'], data: { rentang: r }, onFinish: () => (memuat.value = false) });
}

const gib = (b: number) => (b >= 1073741824 ? `${(b / 1073741824).toFixed(1)} GiB` : `${Math.round(b / 1048576)} MiB`);
const laju = (b: number) => (b >= 1048576 ? `${(b / 1048576).toFixed(2)} MiB/s` : `${(b / 1024).toFixed(1)} KiB/s`);
const persen = (a: number | null, b: number | null) => (a !== null && b ? ` (${Math.round((a / b) * 100)}%)` : '');
const lama = (d: number) => (d >= 86400 ? `${Math.floor(d / 86400)} hari ${Math.floor((d % 86400) / 3600)} jam` : `${Math.floor(d / 3600)} jam ${Math.floor((d % 3600) / 60)} menit`);
const waktu = computed(() => props.riwayat.titik.map((p) => p.t));
const kolom = (k: keyof Titik) => props.riwayat.titik.map((p) => p[k]);
const akhir = (k: keyof Titik) => [...props.riwayat.titik].reverse().find((p) => p[k] !== null)?.[k] ?? null;
const t = computed(() => props.terkini);
const kartu = computed(() => [
    { nama: 'CPU', ikon: Cpu, nilai: t.value?.cpu != null ? `${t.value.cpu.toFixed(1)}%` : '-', ket: t.value?.inti ? `${t.value.inti} inti` : '' },
    { nama: 'RAM', ikon: MemoryStick, nilai: t.value?.ram != null ? gib(t.value.ram) : '-', ket: t.value?.ram_total ? `dari ${gib(t.value.ram_total)}${persen(t.value.ram, t.value.ram_total)}` : '' },
    { nama: 'Disk', ikon: HardDrive, nilai: t.value?.disk != null ? gib(t.value.disk) : '-', ket: t.value?.disk_total ? `dari ${gib(t.value.disk_total)}${persen(t.value.disk, t.value.disk_total)}` : '' },
    { nama: 'Load (1 mnt)', ikon: Gauge, nilai: t.value?.load != null ? t.value.load.toFixed(2) : '-', ket: t.value?.uptime ? `Uptime ${lama(t.value.uptime)}` : '' },
    { nama: 'Jaringan', ikon: Network, nilai: akhir('rx') !== null ? `↓ ${laju(akhir('rx')!)}` : '-', ket: akhir('tx') !== null ? `↑ ${laju(akhir('tx')!)}` : '' },
]);
const WARNA = { a: 'var(--color-brand-700, #2563eb)', b: 'var(--color-volt, #16a34a)' };
</script>

<template>
    <Head :title="`Admin · Node ${node.nama}`" />
    <div class="space-y-6 p-4 md:p-6">
        <PageHeader :title="node.nama" :description="`${node.jenis_nama} · ${node.agent_url} · ${node.layanan} / ${node.kapasitas_pod} layanan`">
            <StatusBadge :status="node.aktif ? 'aktif' : 'nonaktif'" :label="node.aktif ? 'Aktif' : 'Nonaktif'" />
        </PageHeader>

        <p v-if="node.jenis === 'directadmin'" class="rounded-lg border border-border px-5 py-8 text-center text-sm text-muted-foreground">
            Pemakaian resource tersedia untuk node pod dan Incus. Node DirectAdmin belum punya sumber data mesin.
        </p>

        <section v-else class="rounded-lg border border-border">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-3">
                <h2 class="flex items-center gap-2 font-semibold text-ink">
                    <Activity class="size-4" aria-hidden="true" />Pemakaian resource
                    <span v-if="terkini" class="text-xs font-normal text-muted-foreground">sampel {{ new Date(terkini.waktu * 1000).toLocaleTimeString('id-ID') }}</span>
                </h2>
                <div class="flex flex-wrap gap-1" role="group" aria-label="Rentang waktu">
                    <Button
                        v-for="[k, nama] in PILIHAN"
                        :key="k"
                        size="sm"
                        :variant="riwayat.rentang === k ? 'default' : 'outline'"
                        :aria-pressed="riwayat.rentang === k"
                        :disabled="memuat"
                        @click="pilih(k)"
                    >
                        {{ nama }}
                    </Button>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3 px-5 pt-4 md:grid-cols-3 lg:grid-cols-5">
                <div v-for="k in kartu" :key="k.nama" class="rounded-md bg-muted/50 px-3 py-2">
                    <p class="flex items-center gap-1.5 text-xs text-muted-foreground"><component :is="k.ikon" class="size-3.5" aria-hidden="true" />{{ k.nama }}</p>
                    <p class="text-sm font-semibold text-ink tabular-nums">{{ k.nilai }}</p>
                    <p v-if="k.ket" class="text-xs text-muted-foreground tabular-nums">{{ k.ket }}</p>
                </div>
            </div>
            <p v-if="riwayat.titik.length < 2" class="px-5 py-8 text-center text-sm text-muted-foreground">
                {{ memuat ? 'Memuat grafik…' : 'Belum ada data untuk rentang ini. Sampel diambil tiap menit.' }}
                <template v-if="!memuat && !terkini && node.jenis === 'incus'">
                    <br />Node Incus dibaca lewat SSH dari server app ke host agent_url: host itu perlu entri di /root/.ssh/config (user, port, kunci).
                </template>
            </p>
            <div v-else class="grid gap-6 px-5 py-4 lg:grid-cols-2">
                <div>
                    <h3 class="mb-1 flex items-center gap-1.5 text-xs font-semibold text-muted-foreground"><Cpu class="size-3.5" aria-hidden="true" />CPU (%)</h3>
                    <GrafikGaris :waktu="waktu" :seri="[{ nama: 'CPU', warna: WARNA.a, data: kolom('cpu') }]" :format="(n) => `${n.toFixed(1)}%`" :maks="100" />
                </div>
                <div>
                    <h3 class="mb-1 flex items-center gap-1.5 text-xs font-semibold text-muted-foreground"><MemoryStick class="size-3.5" aria-hidden="true" />RAM</h3>
                    <GrafikGaris :waktu="waktu" :seri="[{ nama: 'RAM', warna: WARNA.a, data: kolom('ram') }]" :format="gib" :maks="akhir('ram_total') ?? undefined" />
                </div>
                <div>
                    <h3 class="mb-1 flex items-center gap-1.5 text-xs font-semibold text-muted-foreground"><HardDrive class="size-3.5" aria-hidden="true" />Disk terpakai</h3>
                    <GrafikGaris :waktu="waktu" :seri="[{ nama: 'Disk', warna: WARNA.a, data: kolom('disk') }]" :format="gib" :maks="akhir('disk_total') ?? undefined" />
                </div>
                <div>
                    <h3 class="mb-1 flex items-center gap-1.5 text-xs font-semibold text-muted-foreground"><Gauge class="size-3.5" aria-hidden="true" />Load average (1 menit)</h3>
                    <GrafikGaris :waktu="waktu" :seri="[{ nama: 'Load', warna: WARNA.a, data: kolom('load') }]" :format="(n) => n.toFixed(2)" />
                </div>
                <div class="lg:col-span-2">
                    <h3 class="mb-1 flex items-center gap-1.5 text-xs font-semibold text-muted-foreground">
                        <Network class="size-3.5" aria-hidden="true" />Jaringan
                        <span class="ml-2 inline-flex items-center gap-1 font-normal"><span class="size-2 rounded-full" :style="{ background: WARNA.a }" />masuk</span>
                        <span class="inline-flex items-center gap-1 font-normal"><span class="size-2 rounded-full" :style="{ background: WARNA.b }" />keluar</span>
                    </h3>
                    <GrafikGaris :waktu="waktu" :seri="[{ nama: 'Masuk', warna: WARNA.a, data: kolom('rx') }, { nama: 'Keluar', warna: WARNA.b, data: kolom('tx') }]" :format="laju" />
                </div>
            </div>
        </section>
    </div>
</template>
