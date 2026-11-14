<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AgenController from '@/actions/App/Http/Controllers/Admin/AgenController';
import PageHeader from '@/components/cloud/PageHeader.vue';
import StatusBadge from '@/components/cloud/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import admin from '@/routes/admin';

type Catatan = {
    id: number;
    jenis: 'laporan' | 'tindakan' | 'masalah';
    judul: string;
    isi: string;
    status: string | null;
    target: string | null;
    agen: string;
    created_at: string;
    metrik_awal: { path: string } | null;
    hasil: { putusan: 'berhasil' | 'netral' | 'gagal'; catatan: string } | null;
    dievaluasi_pada: string | null;
    diskusi_url: string | null;
    ringkasan: string | null;
};
type Pelajaran = { id: number; judul: string; isi: string; skor: number; status: 'aktif' | 'usang'; bukti: number[] | null; updated_at: string };
const PUTUSAN: Record<string, { status: string; label: string }> = {
    berhasil: { status: 'aktif', label: 'Hasil: berhasil' },
    netral: { status: 'netral', label: 'Hasil: netral' }, // abu-abu
    gagal: { status: 'gagal', label: 'Hasil: gagal' },
};
const tanggal = (iso: string) => new Date(iso).toLocaleDateString('id-ID', { timeZone: 'Asia/Jakarta', dateStyle: 'medium' });
const jatuhTempo = (iso: string) => tanggal(new Date(new Date(iso).getTime() + 7 * 864e5).toISOString());
defineOptions({
    layout: { breadcrumbs: [{ title: 'Admin · Agent', href: admin.agen.index() }] },
});
const props = defineProps<{ jenis: 'laporan' | 'tindakan' | 'pelajaran' | 'masalah'; bulan: string | null; menunggu: number; terbuka: number; catatan: Catatan[]; pelajaran?: Pelajaran[] }>();

// ── Kalender laporan (zona WIB, sama dengan filter server) ──
const ZONA = 'Asia/Jakarta';
const hariWib = (iso: string) => new Date(iso).toLocaleDateString('sv-SE', { timeZone: ZONA }); // YYYY-MM-DD
const jamWib = (iso: string) => new Date(iso).toLocaleTimeString('id-ID', { timeZone: ZONA, hour: '2-digit', minute: '2-digit' });
const hariIni = hariWib(new Date().toISOString());
const perHari = computed(() => {
    const m = new Map<string, Catatan[]>();
    for (const c of [...props.catatan].reverse()) m.set(hariWib(c.created_at), [...(m.get(hariWib(c.created_at)) ?? []), c]);
    return m;
});
const sel = computed(() => {
    if (!props.bulan) return [];
    const [y, mo] = props.bulan.split('-').map(Number);
    const jumlah = new Date(Date.UTC(y, mo, 0)).getUTCDate();
    const geser = (new Date(Date.UTC(y, mo - 1, 1)).getUTCDay() + 6) % 7; // minggu mulai Senin
    const hari = Array.from({ length: jumlah }, (_, i) => `${props.bulan}-${String(i + 1).padStart(2, '0')}`);
    return [...Array(geser).fill(null), ...hari, ...Array((7 - ((geser + jumlah) % 7)) % 7).fill(null)] as (string | null)[];
});
const judulBulan = computed(() => props.bulan ? new Date(`${props.bulan}-01T12:00:00Z`).toLocaleDateString('id-ID', { month: 'long', year: 'numeric', timeZone: 'UTC' }) : '');
const terakhir = () => [...perHari.value.keys()].at(-1) ?? null;
const dipilih = ref<string | null>(null);
// Default: hari ini bila ada laporan, selain itu hari terakhir yang punya laporan.
watch(() => props.bulan, () => {
    const bulanIni = !!props.bulan && hariIni.startsWith(props.bulan);
    dipilih.value = bulanIni && perHari.value.has(hariIni) ? hariIni : terakhir() ?? (bulanIni ? hariIni : null);
}, { immediate: true });
const timeline = computed(() => (dipilih.value ? perHari.value.get(dipilih.value) ?? [] : []));
const judulHari = computed(() => dipilih.value ? new Date(`${dipilih.value}T12:00:00Z`).toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }) : '');
function keBulan(delta: number | null) {
    let bulan = hariIni.slice(0, 7);
    if (delta !== null && props.bulan) {
        const [y, m] = props.bulan.split('-').map(Number);
        const d = new Date(Date.UTC(y, m - 1 + delta, 1));
        bulan = `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}`;
    }
    router.get(admin.agen.index().url, { jenis: 'laporan', bulan }, { preserveScroll: true, replace: true });
}

const LABEL: Record<string, { status: string; label: string }> = {
    usulan: { status: 'menunggu_pembayaran', label: 'Menunggu persetujuan' },
    disetujui: { status: 'disiapkan', label: 'Disetujui' },
    ditolak: { status: 'dibatalkan', label: 'Ditolak' },
    diterapkan: { status: 'aktif', label: 'Diterapkan' },
    dibatalkan: { status: 'gagal', label: 'Dibatalkan' },
    terbuka: { status: 'menunggu_pembayaran', label: 'Didiskusikan' },
    fix: { status: 'aktif', label: 'Fix' },
};
const waktu = (iso: string) => new Date(iso).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' });

function ganti(jenis: string) {
    router.get(admin.agen.index().url, { jenis }, { preserveScroll: true, replace: true });
}
function putuskan(c: Catatan, status: 'disetujui' | 'ditolak') {
    router.post(AgenController.putuskan(c.id).url, { status }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Admin · Agent" />
    <div class="space-y-6 p-4 md:p-6">
        <PageHeader
            title="Agent"
            description="Laporan dan tindakan agen (bot Dudung lewat MCP velocity-cloud). Usulan yang butuh persetujuan muncul di tab Tindakan."
        />

        <div class="flex gap-2" role="tablist">
            <Button role="tab" size="sm" :aria-selected="jenis === 'laporan'" :variant="jenis === 'laporan' ? 'default' : 'outline'" @click="ganti('laporan')">Laporan</Button>
            <Button role="tab" size="sm" :aria-selected="jenis === 'tindakan'" :variant="jenis === 'tindakan' ? 'default' : 'outline'" @click="ganti('tindakan')">
                Tindakan<span v-if="menunggu" class="ml-1.5 rounded-full bg-red-600 px-1.5 text-xs text-white">{{ menunggu }}</span>
            </Button>
            <Button role="tab" size="sm" :aria-selected="jenis === 'masalah'" :variant="jenis === 'masalah' ? 'default' : 'outline'" @click="ganti('masalah')">
                Masalah<span v-if="terbuka" class="ml-1.5 rounded-full bg-red-600 px-1.5 text-xs text-white">{{ terbuka }}</span>
            </Button>
            <Button role="tab" size="sm" :aria-selected="jenis === 'pelajaran'" :variant="jenis === 'pelajaran' ? 'default' : 'outline'" @click="ganti('pelajaran')">Pelajaran</Button>
        </div>

        <div v-if="jenis === 'laporan'" class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <section class="rounded-lg border border-border bg-white">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3">
                    <h2 class="font-semibold text-ink capitalize">{{ judulBulan }}</h2>
                    <div class="flex items-center gap-1.5">
                        <Button size="sm" variant="outline" aria-label="Bulan sebelumnya" @click="keBulan(-1)">‹</Button>
                        <Button size="sm" variant="outline" @click="keBulan(null)">Bulan ini</Button>
                        <Button size="sm" variant="outline" aria-label="Bulan berikutnya" @click="keBulan(1)">›</Button>
                    </div>
                </div>
                <div class="grid grid-cols-7 border-b border-border text-center text-xs font-medium text-muted-foreground">
                    <span v-for="h in ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min']" :key="h" class="py-2">{{ h }}</span>
                </div>
                <div class="grid grid-cols-7">
                    <template v-for="(h, i) in sel" :key="h ?? `k${i}`">
                        <div v-if="!h" class="min-h-16 border-r border-b border-border bg-muted/30 md:min-h-24" />
                        <button
                            v-else
                            type="button"
                            class="flex min-h-16 flex-col items-stretch gap-1 border-r border-b border-border p-1.5 text-left transition-colors hover:bg-brand-50 md:min-h-24 md:p-2"
                            :class="[dipilih === h ? 'bg-brand-50 ring-2 ring-brand-700 ring-inset' : '', (i + 1) % 7 === 0 ? 'border-r-0' : '']"
                            :aria-pressed="dipilih === h"
                            :aria-label="`${h}: ${perHari.get(h)?.length ?? 0} laporan`"
                            @click="dipilih = h"
                        >
                            <span class="text-xs tabular-nums" :class="h === hariIni ? 'inline-flex size-6 items-center justify-center self-start rounded-full bg-brand-700 font-semibold text-white' : 'text-muted-foreground'">{{ Number(h.slice(8)) }}</span>
                            <template v-if="perHari.get(h)">
                                <span v-for="c in perHari.get(h)!.slice(0, 2)" :key="c.id" class="hidden truncate rounded bg-brand-700/10 px-1.5 py-0.5 text-xs text-brand-700 md:block">{{ c.judul }}</span>
                                <span v-if="perHari.get(h)!.length > 2" class="hidden text-xs text-muted-foreground md:block">+{{ perHari.get(h)!.length - 2 }} lagi</span>
                                <span class="mt-auto flex gap-0.5 md:hidden"><span v-for="c in perHari.get(h)!.slice(0, 3)" :key="c.id" class="size-1.5 rounded-full bg-brand-700" /></span>
                            </template>
                        </button>
                    </template>
                </div>
                <p class="px-4 py-3 text-xs text-muted-foreground">{{ catatan.length }} laporan bulan ini · waktu WIB</p>
            </section>

            <section class="rounded-lg border border-border bg-white" aria-live="polite">
                <h2 class="border-b border-border px-4 py-3 font-semibold text-ink capitalize">{{ judulHari || 'Pilih tanggal' }}</h2>
                <p v-if="!timeline.length" class="px-4 py-10 text-center text-sm text-muted-foreground">
                    {{ catatan.length ? 'Tidak ada laporan di tanggal ini.' : 'Belum ada laporan bulan ini. Agen mencatat lewat MCP catat-laporan.' }}
                </p>
                <ol v-else class="relative px-4 py-4">
                    <li v-for="c in timeline" :key="c.id" class="relative border-l-2 border-brand-100 pb-5 pl-5 last:pb-0">
                        <span class="absolute top-1 -left-[7px] size-3 rounded-full border-2 border-white bg-brand-700" aria-hidden="true" />
                        <p class="text-xs text-muted-foreground tabular-nums">{{ jamWib(c.created_at) }} · {{ c.agen }}</p>
                        <details class="mt-1">
                            <summary class="cursor-pointer font-semibold text-ink">{{ c.judul }}</summary>
                            <p v-if="c.target" class="mt-1 font-mono text-xs text-muted-foreground">{{ c.target }}</p>
                            <div class="mt-2 text-sm whitespace-pre-wrap text-ink [overflow-wrap:anywhere]">{{ c.isi }}</div>
                        </details>
                    </li>
                </ol>
            </section>
        </div>

        <template v-else-if="jenis === 'pelajaran'">
            <p class="text-sm text-muted-foreground">Playbook yang dipelajari agen dari tindakan yang sudah dievaluasi. Skor naik tiap kali pola terbukti lagi, turun bila dibantah; skor −2 = usang.</p>
            <p v-if="!pelajaran?.length" class="rounded-lg border border-border bg-white px-4 py-10 text-center text-sm text-muted-foreground">
                Belum ada pelajaran. Pelajaran pertama muncul setelah tindakan berumur 7 hari dievaluasi.
            </p>
            <article v-for="p in pelajaran" :key="p.id" class="rounded-lg border border-border bg-white px-4 py-3" :class="p.status === 'usang' ? 'opacity-60' : ''">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span class="rounded-md bg-brand-700/10 px-2 py-0.5 text-sm font-semibold text-brand-700 tabular-nums">{{ p.skor > 0 ? '+' : '' }}{{ p.skor }}</span>
                    <span class="font-semibold text-ink">{{ p.judul }}</span>
                    <StatusBadge v-if="p.status === 'usang'" status="nonaktif" label="Usang" />
                    <span class="ml-auto text-xs text-muted-foreground">diperbarui {{ tanggal(p.updated_at) }}</span>
                </div>
                <p class="mt-2 text-sm whitespace-pre-wrap text-ink">{{ p.isi }}</p>
                <p v-if="p.bukti?.length" class="mt-2 text-xs text-muted-foreground">Bukti: tindakan {{ p.bukti.map((b) => `#${b}`).join(', ') }}</p>
            </article>
        </template>

        <p v-else-if="!catatan.length" class="rounded-lg border border-border bg-white px-4 py-10 text-center text-sm text-muted-foreground">
            <template v-if="jenis === 'masalah'">Belum ada masalah. Agen melapor lewat alat <code>lapor_grup</code> di bot.velocity.ai.id.</template>
            <template v-else>Belum ada {{ jenis }}. Agen mencatat lewat MCP <code>catat-{{ jenis }}</code>.</template>
        </p>

        <p v-if="jenis === 'masalah'" class="text-sm text-muted-foreground">Masalah yang dilaporkan agen ke grup tim bot. Tim berdiskusi otomatis di percakapan grup tersendiri sampai salah satu menandai fix.</p>
        <article v-for="c in jenis === 'tindakan' || jenis === 'masalah' ? catatan : []" :key="c.id" class="rounded-lg border border-border bg-white">
            <details :open="c === catatan[0] || c.status === 'usulan' || c.status === 'terbuka'">
                <summary class="flex cursor-pointer flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3">
                    <span class="font-semibold text-ink">{{ c.judul }}</span>
                    <StatusBadge v-if="c.status" v-bind="LABEL[c.status] ?? { status: c.status, label: c.status }" />
                    <StatusBadge v-if="c.hasil" v-bind="PUTUSAN[c.hasil.putusan]" />
                    <span v-else-if="c.metrik_awal && c.status === 'diterapkan'" class="text-xs text-muted-foreground">dievaluasi mulai {{ jatuhTempo(c.created_at) }}</span>
                    <span class="font-mono text-xs text-muted-foreground">#{{ c.id }}</span>
                    <span v-if="c.target" class="font-mono text-xs text-muted-foreground">{{ c.target }}</span>
                    <span class="ml-auto text-xs text-muted-foreground">{{ c.agen }} · {{ waktu(c.created_at) }}</span>
                </summary>
                <div class="space-y-3 border-t border-border px-4 py-3">
                    <div class="text-sm whitespace-pre-wrap text-ink [overflow-wrap:anywhere]">{{ c.isi }}</div>
                    <p v-if="c.ringkasan" class="rounded-md bg-muted/40 px-3 py-2 text-sm whitespace-pre-wrap text-ink">Penyelesaian: {{ c.ringkasan }}</p>
                    <a v-if="c.diskusi_url" :href="c.diskusi_url" target="_blank" rel="noopener" class="inline-block text-sm font-medium text-brand-700 underline underline-offset-2">Buka diskusi di grup bot</a>
                    <p v-if="c.hasil" class="rounded-md bg-muted/40 px-3 py-2 text-sm text-ink">Evaluasi {{ c.dievaluasi_pada ? tanggal(c.dievaluasi_pada) : '' }}: {{ c.hasil.catatan }}</p>
                    <div v-if="c.status === 'usulan'" class="flex gap-2">
                        <Button size="sm" @click="putuskan(c, 'disetujui')">Setujui</Button>
                        <Button size="sm" variant="outline" @click="putuskan(c, 'ditolak')">Tolak</Button>
                    </div>
                </div>
            </details>
        </article>
    </div>
</template>
