<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import MarkdownIt from 'markdown-it';
import { computed, ref, watch } from 'vue';
import AgenController from '@/actions/App/Http/Controllers/Admin/AgenController';
import PageHeader from '@/components/cloud/PageHeader.vue';
import StatusBadge from '@/components/cloud/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import admin from '@/routes/admin';

type MetrikSnapshot = {
    path: string;
    dari: string;
    sampai: string;
    tampilan: number;
    pengguna: number;
    event?: Record<string, number>;
    gsc?: { klik: number; tayangan: number; ctr: number; posisi: number | null } | null;
};
type Catatan = {
    id: number;
    jenis: 'laporan' | 'tindakan' | 'review' | 'masalah';
    judul: string;
    isi: string;
    status: string | null;
    target: string | null;
    agen: string;
    created_at: string;
    metrik_awal: MetrikSnapshot | null;
    hasil: { putusan: 'berhasil' | 'netral' | 'gagal'; catatan: string; metrik?: MetrikSnapshot | null } | null;
    dievaluasi_pada: string | null;
    diskusi_url: string | null;
    ringkasan: string | null;
};
type Pelajaran = { id: number; judul: string; isi: string; skor: number; status: 'aktif' | 'usang'; bukti: number[] | null; updated_at: string };
type ItemProgres = { aktual: number; target: number; persen: number; status: 'tercapai' | 'mendekati' | 'merah' };
type Progres = {
    mingguan: { periode: string; item: Record<'sesi_asli' | 'klik_pesan' | 'klik_daftar' | 'order_lunas', ItemProgres> };
    bulanan: { periode: string; item: Record<'sesi_asli' | 'klik_pesan' | 'klik_daftar' | 'order_lunas' | 'omzet_lunas', ItemProgres> };
};
type TargetAngka = {
    mingguan: { sesi_asli: number; klik_pesan: number; klik_daftar: number; order_lunas: number };
    bulanan: { sesi_asli: number; klik_pesan: number; klik_daftar: number; order_lunas: number; omzet_lunas: number };
};
type Siklus = {
    tindakan_diterapkan: number;
    siap_dievaluasi: number;
    evaluasi: { berhasil: number; netral: number; gagal: number };
    laporan_terakhir: { agen: string; judul: string; waktu: string } | null;
    artikel_terakhir: { judul: string; target: string | null; waktu: string } | null;
};

const props = defineProps<{
    jenis: 'laporan' | 'tindakan' | 'review' | 'masalah' | 'pelajaran';
    bulan: string | null;
    menunggu: number;
    terbuka: number;
    review_terbuka: number;
    progres: Progres;
    target: TargetAngka;
    arahan: { teks: string; diperbarui_pada: string | null };
    siklus: Siklus;
    daftar_agen: string[];
    filter: { agen: string; status: string; putusan: string; q: string };
    fokus: number | null;
    catatan: Catatan[];
    pelajaran?: Pelajaran[];
}>();

defineOptions({
    layout: { breadcrumbs: [{ title: 'Admin · Agent', href: admin.agen.index() }] },
});

// Render markdown laporan & catatan agen secara aman (tanpa HTML mentah).
const md = new MarkdownIt({ html: false, breaks: true, linkify: true });
const aturanLink = md.renderer.rules.link_open ?? ((tokens, idx, opts, _env, self) => self.renderToken(tokens, idx, opts));
md.renderer.rules.link_open = (tokens, idx, opts, env, self) => {
    tokens[idx].attrSet('target', '_blank');
    tokens[idx].attrSet('rel', 'noopener noreferrer');
    return aturanLink(tokens, idx, opts, env, self);
};
const renderMd = (teks: string) => md.render(teks || '');

const ZONA = 'Asia/Jakarta';
const LANDING_BASE = 'https://' + 'velocitydatacenter.com';
const tanggal = (iso: string) => new Date(iso).toLocaleDateString('id-ID', { timeZone: ZONA, dateStyle: 'medium' });
const waktu = (iso: string) => new Date(iso).toLocaleString('id-ID', { timeZone: ZONA, dateStyle: 'medium', timeStyle: 'short' });
const jatuhTempo = (iso: string) => tanggal(new Date(new Date(iso).getTime() + 7 * 864e5).toISOString());
const rupiah = (n: number) => 'Rp' + new Intl.NumberFormat('id-ID').format(n);

// ── Panel Progres Target, Arahan Pemilik & Edit Target ──
const modeTarget = ref<'mingguan' | 'bulanan'>('mingguan');
const bukaArahan = ref(false);
const bukaTarget = ref(false);

const LABEL_METRIK: Record<string, { judul: string; rupiah?: boolean }> = {
    sesi_asli: { judul: 'Sesi asli (organik/ID)' },
    klik_pesan: { judul: 'Klik Pesan' },
    klik_daftar: { judul: 'Klik Daftar' },
    order_lunas: { judul: 'Order lunas' },
    omzet_lunas: { judul: 'Omzet lunas', rupiah: true },
};
const itemProgresAktif = computed(() => {
    const sumber = modeTarget.value === 'mingguan' ? props.progres.mingguan.item : props.progres.bulanan.item;
    return Object.entries(sumber).map(([kunci, data]) => ({
        kunci,
        judul: LABEL_METRIK[kunci]?.judul ?? kunci,
        rupiah: !!LABEL_METRIK[kunci]?.rupiah,
        ...data,
    }));
});
const formatNilai = (n: number, pakaiRupiah = false) => (pakaiRupiah ? rupiah(n) : new Intl.NumberFormat('id-ID').format(n));
const warnaBar = (s: ItemProgres['status']) => (s === 'tercapai' ? 'bg-volt' : s === 'mendekati' ? 'bg-amber-500' : 'bg-red-500');
const warnaStatusTeks = (s: ItemProgres['status']) => (s === 'tercapai' ? 'text-volt-700' : s === 'mendekati' ? 'text-amber-700' : 'text-red-700');

const formArahan = useForm({ teks: props.arahan.teks });
watch(() => props.arahan.teks, (v) => { formArahan.teks = v; });
function simpanArahan() {
    formArahan.post(AgenController.simpanArahan().url, {
        preserveScroll: true,
        onSuccess: () => { bukaArahan.value = false; },
    });
}

const formTarget = useForm({
    mingguan: { ...props.target.mingguan },
    bulanan: { ...props.target.bulanan },
});
watch(() => props.target, (t) => {
    formTarget.mingguan = { ...t.mingguan };
    formTarget.bulanan = { ...t.bulanan };
});
function simpanTarget() {
    formTarget.put(AgenController.simpanTarget().url, {
        preserveScroll: true,
        onSuccess: () => { bukaTarget.value = false; },
    });
}

// ── Kalender laporan (zona WIB) ──
const hariWib = (iso: string) => new Date(iso).toLocaleDateString('sv-SE', { timeZone: ZONA });
const jamWib = (iso: string) => new Date(iso).toLocaleTimeString('id-ID', { timeZone: ZONA, hour: '2-digit', minute: '2-digit' });
const hariIni = hariWib(new Date().toISOString());
const perHari = computed(() => {
    const m = new Map<string, Catatan[]>();
    for (const c of [...props.catatan].reverse()) {
        const k = hariWib(c.created_at);
        m.set(k, [...(m.get(k) ?? []), c]);
    }
    return m;
});
const sel = computed(() => {
    if (!props.bulan) return [];
    const [y, mo] = props.bulan.split('-').map(Number);
    const jumlah = new Date(Date.UTC(y, mo, 0)).getUTCDate();
    const geser = (new Date(Date.UTC(y, mo - 1, 1)).getUTCDay() + 6) % 7;
    const hari = Array.from({ length: jumlah }, (_, i) => `${props.bulan}-${String(i + 1).padStart(2, '0')}`);
    return [...Array(geser).fill(null), ...hari, ...Array((7 - ((geser + jumlah) % 7)) % 7).fill(null)] as (string | null)[];
});
const judulBulan = computed(() => (props.bulan ? new Date(`${props.bulan}-01T12:00:00Z`).toLocaleDateString('id-ID', { month: 'long', year: 'numeric', timeZone: 'UTC' }) : ''));
const terakhir = () => [...perHari.value.keys()].at(-1) ?? null;
const dipilih = ref<string | null>(null);
watch(() => props.bulan, () => {
    const bulanIni = !!props.bulan && hariIni.startsWith(props.bulan);
    dipilih.value = bulanIni && perHari.value.has(hariIni) ? hariIni : terakhir() ?? (bulanIni ? hariIni : null);
}, { immediate: true });
const timeline = computed(() => (dipilih.value ? perHari.value.get(dipilih.value) ?? [] : []));
const judulHari = computed(() => (dipilih.value ? new Date(`${dipilih.value}T12:00:00Z`).toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }) : ''));
function keBulan(delta: number | null) {
    let bulan = hariIni.slice(0, 7);
    if (delta !== null && props.bulan) {
        const [y, m] = props.bulan.split('-').map(Number);
        const d = new Date(Date.UTC(y, m - 1 + delta, 1));
        bulan = `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}`;
    }
    router.get(admin.agen.index().url, { jenis: 'laporan', bulan }, { preserveScroll: true, replace: true });
}

// ── Filter daftar (Tindakan, Review, Masalah) ──
const fAgen = ref(props.filter.agen);
const fStatus = ref(props.filter.status);
const fPutusan = ref(props.filter.putusan);
const fCari = ref(props.filter.q);
watch(() => props.filter, (f) => {
    fAgen.value = f.agen;
    fStatus.value = f.status;
    fPutusan.value = f.putusan;
    fCari.value = f.q;
});
const adaFilter = computed(() => !!(fAgen.value || fStatus.value || fPutusan.value || fCari.value));
function terapkanFilter() {
    const q: Record<string, string> = { jenis: props.jenis };
    if (fAgen.value) q.agen = fAgen.value;
    if (fStatus.value) q.status = fStatus.value;
    if (props.jenis === 'tindakan' && fPutusan.value) q.putusan = fPutusan.value;
    if (fCari.value.trim()) q.q = fCari.value.trim();
    router.get(admin.agen.index().url, q, { preserveScroll: true, replace: true });
}
function resetFilter() {
    fAgen.value = '';
    fStatus.value = '';
    fPutusan.value = '';
    fCari.value = '';
    router.get(admin.agen.index().url, { jenis: props.jenis }, { preserveScroll: true, replace: true });
}

// ── Label status & perbandingan metrik ──
const PUTUSAN: Record<string, { status: string; label: string }> = {
    berhasil: { status: 'aktif', label: 'Hasil: berhasil' },
    netral: { status: 'netral', label: 'Hasil: netral' },
    gagal: { status: 'gagal', label: 'Hasil: gagal' },
};
const LABEL: Record<string, { status: string; label: string }> = {
    usulan: { status: 'menunggu_pembayaran', label: 'Menunggu persetujuan' },
    disetujui: { status: 'disiapkan', label: 'Disetujui' },
    ditolak: { status: 'dibatalkan', label: 'Ditolak' },
    diterapkan: { status: 'aktif', label: 'Diterapkan' },
    dibatalkan: { status: 'gagal', label: 'Dibatalkan' },
    terbuka: { status: 'menunggu_pembayaran', label: 'Didiskusikan' },
    fix: { status: 'aktif', label: 'Selesai' },
};

const urlTarget = (target: string | null) => (target && target.startsWith('/') ? LANDING_BASE + target : null);
const hitungDelta = (awal: number | null | undefined, kini: number | null | undefined, balik = false) => {
    if (awal == null || kini == null) return null;
    const selisih = kini - awal;
    if (selisih === 0) return { teks: '0', positif: null as boolean | null };
    const tanda = selisih > 0 ? '+' : '';
    const persen = awal > 0 ? ` (${tanda}${Math.round((selisih / awal) * 100)}%)` : '';
    return { teks: `${tanda}${Number(selisih.toFixed(1))}${persen}`, positif: balik ? selisih < 0 : selisih > 0 };
};
function barisMetrik(c: Catatan) {
    const a = c.metrik_awal;
    if (!a) return [];
    const k = c.hasil?.metrik ?? null;
    const ev = (m: MetrikSnapshot | null, nama: string) => m?.event?.[nama] ?? 0;
    const rows = [
        { label: 'Tampilan', awal: a.tampilan, kini: k?.tampilan ?? null, balik: false },
        { label: 'Pengguna', awal: a.pengguna, kini: k?.pengguna ?? null, balik: false },
        { label: 'Klik Pesan', awal: ev(a, 'klik_pesan'), kini: k ? ev(k, 'klik_pesan') : null, balik: false },
        { label: 'Klik Daftar', awal: ev(a, 'klik_daftar'), kini: k ? ev(k, 'klik_daftar') : null, balik: false },
    ];
    if (a.gsc || k?.gsc) {
        rows.push(
            { label: 'GSC Tayangan', awal: a.gsc?.tayangan ?? 0, kini: k?.gsc ? k.gsc.tayangan : null, balik: false },
            { label: 'GSC Klik', awal: a.gsc?.klik ?? 0, kini: k?.gsc ? k.gsc.klik : null, balik: false },
            { label: 'GSC Posisi', awal: a.gsc?.posisi ?? null, kini: k?.gsc?.posisi ?? null, balik: true },
        );
    }
    return rows.map((r) => ({ ...r, delta: hitungDelta(r.awal, r.kini, r.balik) }));
}

// ── Aksi admin ──
const tutupId = ref<number | null>(null);
const ringkasanTutup = ref('');

function ganti(jenis: string) {
    router.get(admin.agen.index().url, { jenis }, { preserveScroll: true, replace: true });
}
function bukaTindakan(id: number) {
    router.get(admin.agen.index().url, { jenis: 'tindakan', fokus: id }, { preserveScroll: false });
}
function putuskan(c: Catatan, status: 'disetujui' | 'ditolak') {
    router.post(AgenController.putuskan(c.id).url, { status }, { preserveScroll: true });
}
function konfirmasiTutup(c: Catatan) {
    router.post(
        AgenController.selesai(c.id).url,
        { ringkasan: ringkasanTutup.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                tutupId.value = null;
                ringkasanTutup.value = '';
            },
        },
    );
}
function ubahStatusPelajaran(p: Pelajaran, status: 'aktif' | 'usang') {
    router.post(AgenController.ubahPelajaran(p.id).url, { status }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Admin · Agent" />
    <div class="space-y-6 p-4 md:p-6">
        <PageHeader
            title="Agent"
            description="Pantau target pertumbuhan, laporan harian, tindakan, diskusi tim, dan playbook agen Dudung serta Kang SEO."
        >
            <div class="flex flex-wrap items-center gap-2">
                <Button size="sm" :variant="arahan.teks ? 'default' : 'outline'" @click="bukaArahan = !bukaArahan; if (bukaArahan) bukaTarget = false">
                    Arahan Pemilik<span v-if="arahan.teks" class="ml-1.5 rounded bg-white/20 px-1.5 py-0.5 text-[11px]">Aktif</span>
                </Button>
                <Button size="sm" variant="outline" @click="bukaTarget = !bukaTarget; if (bukaTarget) bukaArahan = false">Atur Target</Button>
            </div>
        </PageHeader>

        <!-- Panel Arahan Pemilik -->
        <section v-if="bukaArahan" class="rounded-lg border border-brand-700/30 bg-white p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-sm font-semibold text-ink">Arahan Pemilik untuk Siklus Agen</h2>
                    <p class="text-xs text-muted-foreground">Otomatis dibaca Dudung di awal setiap siklus lewat MCP <code>mulai-siklus</code>.</p>
                </div>
                <span v-if="arahan.diperbarui_pada" class="text-xs text-muted-foreground">Diperbarui {{ waktu(arahan.diperbarui_pada) }}</span>
            </div>
            <form class="mt-3 space-y-3" @submit.prevent="simpanArahan">
                <textarea
                    v-model="formArahan.teks"
                    rows="3"
                    placeholder="Contoh: Fokus minggu ini perbaiki konversi /token-ai/ dan rapikan tautan daftar di semua halaman app."
                    class="w-full rounded-md border border-border bg-white px-3 py-2 text-sm text-ink focus:border-brand-700 focus:ring-2 focus:ring-brand-700/20 focus:outline-none"
                />
                <p v-if="formArahan.errors.teks" class="text-xs text-red-600">{{ formArahan.errors.teks }}</p>
                <div class="flex flex-wrap items-center gap-2">
                    <Button size="sm" type="submit" :disabled="formArahan.processing">Simpan arahan</Button>
                    <Button v-if="arahan.teks" size="sm" type="button" variant="outline" :disabled="formArahan.processing" @click="formArahan.teks = ''; simpanArahan()">Kosongkan</Button>
                    <Button size="sm" type="button" variant="ghost" @click="bukaArahan = false">Tutup</Button>
                </div>
            </form>
        </section>

        <!-- Panel Edit Target -->
        <section v-if="bukaTarget" class="rounded-lg border border-border bg-white p-4">
            <h2 class="text-sm font-semibold text-ink">Atur Target Mingguan &amp; Bulanan</h2>
            <p class="mt-0.5 text-xs text-muted-foreground">Dipakai sebagai patokan prioritas agen di MCP <code>mulai-siklus</code> dan progress bar di halaman ini.</p>
            <form class="mt-4 space-y-4" @submit.prevent="simpanTarget">
                <div class="grid gap-4 md:grid-cols-2">
                    <fieldset class="space-y-2.5 rounded-md border border-border p-3">
                        <legend class="px-1 text-xs font-semibold text-ink">Target Mingguan (7 hari)</legend>
                        <div class="grid grid-cols-2 gap-3 text-xs">
                            <label class="space-y-1">
                                <span class="text-muted-foreground">Sesi asli (organik/ID)</span>
                                <input v-model.number="formTarget.mingguan.sesi_asli" type="number" min="1" class="w-full rounded-md border border-border px-2.5 py-1.5 text-sm text-ink tabular-nums" />
                            </label>
                            <label class="space-y-1">
                                <span class="text-muted-foreground">Klik Pesan</span>
                                <input v-model.number="formTarget.mingguan.klik_pesan" type="number" min="1" class="w-full rounded-md border border-border px-2.5 py-1.5 text-sm text-ink tabular-nums" />
                            </label>
                            <label class="space-y-1">
                                <span class="text-muted-foreground">Klik Daftar</span>
                                <input v-model.number="formTarget.mingguan.klik_daftar" type="number" min="1" class="w-full rounded-md border border-border px-2.5 py-1.5 text-sm text-ink tabular-nums" />
                            </label>
                            <label class="space-y-1">
                                <span class="text-muted-foreground">Order lunas</span>
                                <input v-model.number="formTarget.mingguan.order_lunas" type="number" min="1" class="w-full rounded-md border border-border px-2.5 py-1.5 text-sm text-ink tabular-nums" />
                            </label>
                        </div>
                    </fieldset>
                    <fieldset class="space-y-2.5 rounded-md border border-border p-3">
                        <legend class="px-1 text-xs font-semibold text-ink">Target Bulanan</legend>
                        <div class="grid grid-cols-2 gap-3 text-xs">
                            <label class="space-y-1">
                                <span class="text-muted-foreground">Sesi asli (organik/ID)</span>
                                <input v-model.number="formTarget.bulanan.sesi_asli" type="number" min="1" class="w-full rounded-md border border-border px-2.5 py-1.5 text-sm text-ink tabular-nums" />
                            </label>
                            <label class="space-y-1">
                                <span class="text-muted-foreground">Klik Pesan</span>
                                <input v-model.number="formTarget.bulanan.klik_pesan" type="number" min="1" class="w-full rounded-md border border-border px-2.5 py-1.5 text-sm text-ink tabular-nums" />
                            </label>
                            <label class="space-y-1">
                                <span class="text-muted-foreground">Klik Daftar</span>
                                <input v-model.number="formTarget.bulanan.klik_daftar" type="number" min="1" class="w-full rounded-md border border-border px-2.5 py-1.5 text-sm text-ink tabular-nums" />
                            </label>
                            <label class="space-y-1">
                                <span class="text-muted-foreground">Order lunas</span>
                                <input v-model.number="formTarget.bulanan.order_lunas" type="number" min="1" class="w-full rounded-md border border-border px-2.5 py-1.5 text-sm text-ink tabular-nums" />
                            </label>
                            <label class="col-span-2 space-y-1">
                                <span class="text-muted-foreground">Omzet lunas (Rp)</span>
                                <input v-model.number="formTarget.bulanan.omzet_lunas" type="number" min="0" step="100000" class="w-full rounded-md border border-border px-2.5 py-1.5 text-sm text-ink tabular-nums" />
                            </label>
                        </div>
                    </fieldset>
                </div>
                <div class="flex items-center gap-2">
                    <Button size="sm" type="submit" :disabled="formTarget.processing">Simpan target</Button>
                    <Button size="sm" type="button" variant="ghost" @click="bukaTarget = false">Batal</Button>
                </div>
            </form>
        </section>

        <!-- Ringkasan Progres Target + Status Siklus -->
        <section class="rounded-lg border border-border bg-white p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-ink">Progres Target Pertumbuhan</h2>
                    <p class="text-xs text-muted-foreground">
                        Periode: {{ modeTarget === 'mingguan' ? progres.mingguan.periode : progres.bulanan.periode }} · hanya pengunjung non-kantor
                    </p>
                </div>
                <div class="inline-flex rounded-md border border-border p-0.5 text-xs">
                    <button
                        type="button"
                        class="rounded px-2.5 py-1 font-medium transition-colors"
                        :class="modeTarget === 'mingguan' ? 'bg-brand-700 text-white' : 'text-muted-foreground hover:text-ink'"
                        @click="modeTarget = 'mingguan'"
                    >
                        Mingguan
                    </button>
                    <button
                        type="button"
                        class="rounded px-2.5 py-1 font-medium transition-colors"
                        :class="modeTarget === 'bulanan' ? 'bg-brand-700 text-white' : 'text-muted-foreground hover:text-ink'"
                        @click="modeTarget = 'bulanan'"
                    >
                        Bulanan
                    </button>
                </div>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" :class="modeTarget === 'bulanan' ? 'xl:grid-cols-5' : ''">
                <div v-for="m in itemProgresAktif" :key="m.kunci" class="rounded-md border border-border/80 bg-muted/20 p-3">
                    <div class="flex items-baseline justify-between gap-2">
                        <span class="text-xs text-muted-foreground">{{ m.judul }}</span>
                        <span class="text-xs font-semibold tabular-nums" :class="warnaStatusTeks(m.status)">{{ m.persen }}%</span>
                    </div>
                    <p class="mt-1 text-base font-bold text-ink tabular-nums">
                        {{ formatNilai(m.aktual, m.rupiah) }}
                        <span class="text-xs font-normal text-muted-foreground">/ {{ formatNilai(m.target, m.rupiah) }}</span>
                    </p>
                    <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-border/60">
                        <div class="h-full rounded-full transition-all" :class="warnaBar(m.status)" :style="{ width: `${Math.min(100, m.persen)}%` }" />
                    </div>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-1.5 border-t border-border pt-3 text-xs text-muted-foreground">
                <span>
                    Evaluasi tindakan:
                    <strong class="text-volt-700">{{ siklus.evaluasi.berhasil }} berhasil</strong>,
                    <strong class="text-ink">{{ siklus.evaluasi.netral }} netral</strong>,
                    <strong class="text-red-700">{{ siklus.evaluasi.gagal }} gagal</strong>
                    <template v-if="siklus.siap_dievaluasi > 0"> · <strong class="text-amber-700">{{ siklus.siap_dievaluasi }} siap dievaluasi</strong></template>
                </span>
                <span v-if="siklus.laporan_terakhir">
                    Laporan terakhir: <strong class="text-ink">{{ siklus.laporan_terakhir.agen }}</strong> ({{ waktu(siklus.laporan_terakhir.waktu) }})
                </span>
                <span v-if="siklus.artikel_terakhir">
                    Artikel terakhir Kang SEO: <strong class="text-ink">{{ waktu(siklus.artikel_terakhir.waktu) }}</strong>
                </span>
            </div>

            <div v-if="arahan.teks && !bukaArahan" class="mt-3 rounded-md border border-brand-700/20 bg-brand-50/50 px-3 py-2 text-xs text-ink">
                <span class="font-semibold text-brand-700">Arahan aktif:</span> {{ arahan.teks }}
            </div>
        </section>

        <!-- Tab Navigasi -->
        <div class="flex flex-wrap gap-2" role="tablist">
            <Button role="tab" size="sm" :aria-selected="jenis === 'laporan'" :variant="jenis === 'laporan' ? 'default' : 'outline'" @click="ganti('laporan')">Laporan</Button>
            <Button role="tab" size="sm" :aria-selected="jenis === 'tindakan'" :variant="jenis === 'tindakan' ? 'default' : 'outline'" @click="ganti('tindakan')">
                Tindakan<span v-if="menunggu" class="ml-1.5 rounded-full bg-red-600 px-1.5 text-xs text-white">{{ menunggu }}</span>
            </Button>
            <Button role="tab" size="sm" :aria-selected="jenis === 'review'" :variant="jenis === 'review' ? 'default' : 'outline'" @click="ganti('review')">
                Review<span v-if="review_terbuka" class="ml-1.5 rounded-full bg-amber-600 px-1.5 text-xs text-white">{{ review_terbuka }}</span>
            </Button>
            <Button role="tab" size="sm" :aria-selected="jenis === 'masalah'" :variant="jenis === 'masalah' ? 'default' : 'outline'" @click="ganti('masalah')">
                Masalah<span v-if="terbuka" class="ml-1.5 rounded-full bg-red-600 px-1.5 text-xs text-white">{{ terbuka }}</span>
            </Button>
            <Button role="tab" size="sm" :aria-selected="jenis === 'pelajaran'" :variant="jenis === 'pelajaran' ? 'default' : 'outline'" @click="ganti('pelajaran')">Pelajaran</Button>
        </div>

        <!-- TAB: LAPORAN (Kalender + Timeline Markdown) -->
        <div v-if="jenis === 'laporan'" class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_26rem]">
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
                    {{ catatan.length ? 'Tidak ada laporan di tanggal ini. Pilih tanggal bertanda biru pada kalender.' : 'Belum ada laporan bulan ini. Agen mencatat lewat MCP catat-laporan.' }}
                </p>
                <ol v-else class="relative px-4 py-4">
                    <li v-for="(c, idx) in timeline" :key="c.id" class="relative border-l-2 border-brand-100 pb-5 pl-5 last:pb-0">
                        <span class="absolute top-1 -left-[7px] size-3 rounded-full border-2 border-white bg-brand-700" aria-hidden="true" />
                        <p class="text-xs text-muted-foreground tabular-nums">{{ jamWib(c.created_at) }} · {{ c.agen }} · #{{ c.id }}</p>
                        <details class="mt-1" :open="idx === timeline.length - 1">
                            <summary class="cursor-pointer font-semibold text-ink">{{ c.judul }}</summary>
                            <p v-if="c.target" class="mt-1 font-mono text-xs text-muted-foreground">{{ c.target }}</p>
                            <div class="md mt-2 text-sm text-ink [overflow-wrap:anywhere]" v-html="renderMd(c.isi)" />
                        </details>
                    </li>
                </ol>
            </section>
        </div>

        <!-- TAB: PELAJARAN -->
        <template v-else-if="jenis === 'pelajaran'">
            <p class="text-sm text-muted-foreground">Playbook yang dipelajari agen dari tindakan yang sudah dievaluasi. Skor naik tiap kali pola terbukti lagi, turun bila dibantah; skor −2 otomatis usang.</p>
            <p v-if="!pelajaran?.length" class="rounded-lg border border-border bg-white px-4 py-10 text-center text-sm text-muted-foreground">
                Belum ada pelajaran di playbook. Pelajaran muncul setelah tindakan dievaluasi lewat MCP <code>evaluasi-tindakan</code> dan dicatat lewat <code>catat-pelajaran</code>.
            </p>
            <article v-for="p in pelajaran" :key="p.id" class="rounded-lg border border-border bg-white px-4 py-3" :class="p.status === 'usang' ? 'opacity-65' : ''">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span class="rounded-md bg-brand-700/10 px-2 py-0.5 text-sm font-semibold text-brand-700 tabular-nums">{{ p.skor > 0 ? '+' : '' }}{{ p.skor }}</span>
                    <span class="font-semibold text-ink">{{ p.judul }}</span>
                    <StatusBadge v-if="p.status === 'usang'" status="nonaktif" label="Usang" />
                    <span class="ml-auto text-xs text-muted-foreground">diperbarui {{ tanggal(p.updated_at) }}</span>
                </div>
                <div class="md mt-2 text-sm text-ink" v-html="renderMd(p.isi)" />
                <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-border/60 pt-2 text-xs">
                    <div v-if="p.bukti?.length" class="flex flex-wrap items-center gap-1.5 text-muted-foreground">
                        <span>Bukti tindakan:</span>
                        <button
                            v-for="b in p.bukti"
                            :key="b"
                            type="button"
                            class="rounded bg-muted px-1.5 py-0.5 font-mono text-brand-700 underline-offset-2 hover:underline"
                            @click="bukaTindakan(b)"
                        >
                            #{{ b }}
                        </button>
                    </div>
                    <span v-else class="text-muted-foreground">Belum ada tautan bukti</span>
                    <Button
                        size="sm"
                        variant="outline"
                        @click="ubahStatusPelajaran(p, p.status === 'usang' ? 'aktif' : 'usang')"
                    >
                        {{ p.status === 'usang' ? 'Aktifkan lagi' : 'Tandai usang' }}
                    </Button>
                </div>
            </article>
        </template>

        <!-- TAB: TINDAKAN / REVIEW / MASALAH -->
        <template v-else>
            <p v-if="jenis === 'review'" class="text-sm text-muted-foreground">Review harian grup tim vdpods (23:00 WIB) yang dipisahkan dari daftar masalah/bug agar lebih rapi.</p>
            <p v-else-if="jenis === 'masalah'" class="text-sm text-muted-foreground">Bug, kendala teknis, atau bloker nyata yang dilaporkan agen ke grup tim bot lewat alat <code>lapor_grup</code>.</p>

            <!-- Baris Filter & Pencarian -->
            <form class="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-white p-3" @submit.prevent="terapkanFilter">
                <input
                    v-model="fCari"
                    type="search"
                    placeholder="Cari judul, isi, atau path..."
                    class="min-w-48 flex-1 rounded-md border border-border bg-white px-3 py-1.5 text-sm text-ink focus:border-brand-700 focus:outline-none"
                />
                <select v-model="fAgen" aria-label="Saring agen" class="rounded-md border border-border bg-white px-2.5 py-1.5 text-sm text-ink" @change="terapkanFilter">
                    <option value="">Semua agen</option>
                    <option v-for="a in daftar_agen" :key="a" :value="a">{{ a }}</option>
                </select>
                <select v-model="fStatus" aria-label="Saring status" class="rounded-md border border-border bg-white px-2.5 py-1.5 text-sm text-ink" @change="terapkanFilter">
                    <option value="">Semua status</option>
                    <template v-if="jenis === 'tindakan'">
                        <option value="diterapkan">Diterapkan</option>
                        <option value="usulan">Menunggu persetujuan</option>
                        <option value="disetujui">Disetujui</option>
                        <option value="dibatalkan">Dibatalkan</option>
                        <option value="ditolak">Ditolak</option>
                    </template>
                    <template v-else>
                        <option value="terbuka">Didiskusikan</option>
                        <option value="fix">Selesai</option>
                    </template>
                </select>
                <select v-if="jenis === 'tindakan'" v-model="fPutusan" aria-label="Saring evaluasi" class="rounded-md border border-border bg-white px-2.5 py-1.5 text-sm text-ink" @change="terapkanFilter">
                    <option value="">Semua evaluasi</option>
                    <option value="berhasil">Berhasil</option>
                    <option value="netral">Netral</option>
                    <option value="gagal">Gagal</option>
                    <option value="belum">Belum dievaluasi</option>
                </select>
                <Button size="sm" type="submit" variant="outline">Saring</Button>
                <Button v-if="adaFilter" size="sm" type="button" variant="ghost" @click="resetFilter">Reset</Button>
            </form>

            <p v-if="!catatan.length" class="rounded-lg border border-border bg-white px-4 py-10 text-center text-sm text-muted-foreground">
                <template v-if="adaFilter">Tidak ada catatan yang cocok dengan saringan saat ini. Klik Reset untuk melihat semua.</template>
                <template v-else-if="jenis === 'masalah'">Belum ada masalah terbuka atau tercatat. Masalah nyata dari <code>lapor_grup</code> akan muncul di sini.</template>
                <template v-else-if="jenis === 'review'">Belum ada review harian tercatat dari grup tim.</template>
                <template v-else>Belum ada tindakan. Agen mencatat lewat MCP <code>catat-tindakan</code>.</template>
            </p>

            <article
                v-for="c in catatan"
                :key="c.id"
                class="rounded-lg border bg-white"
                :class="fokus === c.id ? 'border-brand-700 ring-2 ring-brand-700/20' : 'border-border'"
            >
                <details :open="fokus === c.id || c === catatan[0] || c.status === 'usulan' || c.status === 'terbuka'">
                    <summary class="flex cursor-pointer flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3">
                        <span class="font-semibold text-ink">{{ c.judul }}</span>
                        <StatusBadge v-if="c.status" v-bind="LABEL[c.status] ?? { status: c.status, label: c.status }" />
                        <StatusBadge v-if="c.hasil" v-bind="PUTUSAN[c.hasil.putusan]" />
                        <span v-else-if="c.metrik_awal && c.status === 'diterapkan'" class="text-xs text-muted-foreground">dievaluasi mulai {{ jatuhTempo(c.created_at) }}</span>
                        <span class="font-mono text-xs text-muted-foreground">#{{ c.id }}</span>
                        <a
                            v-if="urlTarget(c.target)"
                            :href="urlTarget(c.target)!"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="font-mono text-xs text-brand-700 underline-offset-2 hover:underline"
                            @click.stop
                        >
                            {{ c.target }}
                        </a>
                        <span v-else-if="c.target" class="font-mono text-xs text-muted-foreground">{{ c.target }}</span>
                        <span class="ml-auto text-xs text-muted-foreground">{{ c.agen }} · {{ waktu(c.created_at) }}</span>
                    </summary>

                    <div class="space-y-3 border-t border-border px-4 py-3">
                        <div class="md text-sm text-ink [overflow-wrap:anywhere]" v-html="renderMd(c.isi)" />

                        <!-- Tabel Perbandingan Metrik Sebelum → Sesudah -->
                        <div v-if="c.metrik_awal" class="rounded-md border border-border/80 bg-muted/20 p-3">
                            <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                                <span class="font-semibold text-ink">Metrik halaman {{ c.metrik_awal.path }}</span>
                                <span>
                                    Awal: {{ c.metrik_awal.dari }} s.d. {{ c.metrik_awal.sampai }}
                                    <template v-if="c.hasil?.metrik"> · Evaluasi: {{ c.hasil.metrik.dari }} s.d. {{ c.hasil.metrik.sampai }}</template>
                                </span>
                            </div>
                            <div class="mt-2 overflow-x-auto">
                                <table class="w-full text-left text-xs tabular-nums">
                                    <thead>
                                        <tr class="border-b border-border text-muted-foreground">
                                            <th class="py-1.5 pr-3 font-medium">Metrik</th>
                                            <th class="py-1.5 pr-3 font-medium">Sebelum</th>
                                            <th v-if="c.hasil?.metrik" class="py-1.5 pr-3 font-medium">Sesudah</th>
                                            <th v-if="c.hasil?.metrik" class="py-1.5 font-medium">Perubahan</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="r in barisMetrik(c)" :key="r.label" class="border-b border-border/50 last:border-0">
                                            <td class="py-1.5 pr-3 text-ink">{{ r.label }}</td>
                                            <td class="py-1.5 pr-3 text-muted-foreground">{{ r.awal ?? '-' }}</td>
                                            <td v-if="c.hasil?.metrik" class="py-1.5 pr-3 font-medium text-ink">{{ r.kini ?? '-' }}</td>
                                            <td
                                                v-if="c.hasil?.metrik"
                                                class="py-1.5 font-medium"
                                                :class="r.delta?.positif === true ? 'text-volt-700' : r.delta?.positif === false ? 'text-red-700' : 'text-muted-foreground'"
                                            >
                                                {{ r.delta?.teks ?? '-' }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div v-if="c.ringkasan" class="rounded-md bg-muted/40 px-3 py-2 text-sm text-ink">
                            <p class="mb-1 text-xs font-semibold text-muted-foreground">Penyelesaian</p>
                            <div class="md" v-html="renderMd(c.ringkasan)" />
                        </div>

                        <p v-if="c.hasil" class="rounded-md bg-muted/40 px-3 py-2 text-sm text-ink">
                            <span class="font-semibold">Evaluasi {{ c.dievaluasi_pada ? tanggal(c.dievaluasi_pada) : '' }}:</span> {{ c.hasil.catatan }}
                        </p>

                        <div class="flex flex-wrap items-center gap-2">
                            <a v-if="c.diskusi_url" :href="c.diskusi_url" target="_blank" rel="noopener noreferrer" class="text-sm font-medium text-brand-700 underline underline-offset-2">
                                Buka diskusi di grup bot
                            </a>
                            <template v-if="c.status === 'usulan'">
                                <Button size="sm" @click="putuskan(c, 'disetujui')">Setujui</Button>
                                <Button size="sm" variant="outline" @click="putuskan(c, 'ditolak')">Tolak</Button>
                            </template>
                            <template v-if="(c.jenis === 'masalah' || c.jenis === 'review') && c.status === 'terbuka'">
                                <Button v-if="tutupId !== c.id" size="sm" variant="outline" @click="tutupId = c.id; ringkasanTutup = ''">
                                    Tandai selesai
                                </Button>
                                <form v-else class="flex w-full flex-wrap items-center gap-2 pt-1" @submit.prevent="konfirmasiTutup(c)">
                                    <input
                                        v-model="ringkasanTutup"
                                        type="text"
                                        placeholder="Catatan penyelesaian (opsional)"
                                        class="min-w-56 flex-1 rounded-md border border-border bg-white px-2.5 py-1.5 text-sm text-ink"
                                    />
                                    <Button size="sm" type="submit">Simpan selesai</Button>
                                    <Button size="sm" type="button" variant="ghost" @click="tutupId = null">Batal</Button>
                                </form>
                            </template>
                        </div>
                    </div>
                </details>
            </article>
        </template>
    </div>
</template>

<style scoped>
.md :deep(> * + *) {
    margin-top: 0.65em;
}
.md :deep(h1),
.md :deep(h2),
.md :deep(h3),
.md :deep(h4) {
    font-weight: 600;
    color: var(--ink, inherit);
    margin-top: 1.1em;
}
.md :deep(h1) {
    font-size: 1.15rem;
}
.md :deep(h2) {
    font-size: 1.02rem;
}
.md :deep(h3),
.md :deep(h4) {
    font-size: 0.95rem;
}
.md :deep(> :first-child) {
    margin-top: 0;
}
.md :deep(ul) {
    list-style: disc;
    padding-left: 1.4em;
}
.md :deep(ol) {
    list-style: decimal;
    padding-left: 1.4em;
}
.md :deep(li + li) {
    margin-top: 0.2em;
}
.md :deep(strong) {
    font-weight: 600;
}
.md :deep(a) {
    color: var(--brand-700, inherit);
    text-decoration: underline;
    text-underline-offset: 2px;
    word-break: break-word;
}
.md :deep(code) {
    font-size: 0.85em;
    background: var(--muted);
    border-radius: 0.25rem;
    padding: 0.1em 0.35em;
}
.md :deep(pre) {
    background: var(--muted);
    border: 1px solid var(--border);
    border-radius: 0.5rem;
    padding: 0.65rem 0.9rem;
    overflow-x: auto;
}
.md :deep(pre code) {
    background: none;
    padding: 0;
    font-size: 0.8rem;
}
.md :deep(table) {
    display: block;
    max-width: 100%;
    overflow-x: auto;
    border-collapse: collapse;
    font-size: 0.82rem;
}
.md :deep(th),
.md :deep(td) {
    border: 1px solid var(--border);
    padding: 0.3em 0.55em;
    text-align: left;
}
.md :deep(th) {
    background: var(--muted);
    font-weight: 600;
}
</style>
