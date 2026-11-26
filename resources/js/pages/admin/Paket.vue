<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PaketController from '@/actions/App/Http/Controllers/Admin/PaketController';
import AnalitikToken from '@/components/analitik/AnalitikToken.vue';
import type { LaporanToken } from '@/components/analitik/AnalitikToken.vue';
import DataTable from '@/components/cloud/DataTable.vue';
import PageHeader from '@/components/cloud/PageHeader.vue';
import StatusBadge from '@/components/cloud/StatusBadge.vue';
import TanggalWaktu from '@/components/cloud/TanggalWaktu.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { rupiah } from '@/lib/format';
import admin from '@/routes/admin';

type PaketRow = {
    id: number;
    kategori: 'vps' | 'managed' | 'app' | 'ai' | 'hosting';
    kategori_label: string;
    nama: string;
    harga: number | null;
    aktif: boolean;
    stok: number | null;
    terpakai: number;
    layanan: number;
    bisa_dipesan: boolean;
    spek: Record<string, string> | null; // ai: harga_input/harga_cache aslinya angka
    promo_berlaku: boolean;
    pod: { cpu: number; ram_mb: number; disk_gb: number | null } | null;
    sumber: {
        url: string | null;
        model: string | null;
        ada_key: boolean;
        model_publik: string | null;
    } | null;
};
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin · Paket', href: admin.paket.index('vps') },
        ],
    },
});
const props = defineProps<{
    kategori: { value: PaketRow['kategori']; label: string };
    paket: PaketRow[];
    analitik: LaporanToken | null;
}>();
const daftar = computed(() => props.paket);
const adaSpek = computed(() =>
    ['vps', 'managed'].includes(props.kategori.value),
);
const isApp = computed(() => props.kategori.value === 'app');
const isAi = computed(() => props.kategori.value === 'ai');
// Paket AI: tab "Laporan" (pemakaian token, default) & "Model" (daftar paket); ?tab=model agar bisa dibagikan/di-refresh
const TAB_AI = { laporan: 'Laporan', model: 'List model' } as const;
const tab = ref<keyof typeof TAB_AI>(new URLSearchParams(window.location.search).get('tab') === 'model' ? 'model' : 'laporan');
function pilihTab(k: keyof typeof TAB_AI) {
    tab.value = k;
    const url = new URL(window.location.href);
    if (k === 'laporan') url.searchParams.delete('tab');
    else url.searchParams.set('tab', k);
    window.history.replaceState(window.history.state, '', url);
}
// Pilihan limit pod (dalam batas validasi server: CPU 0,25–16 kelipatan 0,25, RAM 128–65536 MB).
const PILIHAN_CPU = [0.25, 0.5, 1, 1.5, 2, 3, 4, 6, 8];
const PILIHAN_RAM = [
    256, 512, 1024, 1536, 2048, 3072, 4096, 6144, 8192, 12288, 16384,
];
// Nilai sekarang yang tak ada di daftar tetap ikut agar tidak berubah diam-diam.
const pilihan = (daftar: number[], sekarang: number) =>
    [...new Set([...daftar, sekarang])].sort((a, b) => a - b);
const PILIHAN_DISK = [1, 2, 5, 10, 20, 30, 50, 100];
const labelRam = (mb: number) =>
    mb >= 1024 ? `${String(mb / 1024).replace('.', ',')} GB` : `${mb} MB`;

function status(p: PaketRow): { status: string; label: string } {
    if (!p.aktif) return { status: 'nonaktif', label: 'Nonaktif' };
    if (p.stok !== null && p.terpakai >= p.stok)
        return { status: 'gagal', label: 'Stok habis' };
    if (!p.bisa_dipesan)
        return { status: 'disiapkan', label: 'Belum bisa dipesan' };
    return { status: 'aktif', label: 'Dijual' };
}

// Saklar on/off: kirim harga & stok apa adanya agar hanya status yang berubah (situs ikut diperbarui).
const menyimpan = ref<number | null>(null);
function saklar(p: PaketRow) {
    menyimpan.value = p.id;
    router.put(
        PaketController.update(p.id).url,
        { harga: p.harga, stok: p.stok, aktif: !p.aktif },
        { preserveScroll: true, onFinish: () => (menyimpan.value = null) },
    );
}

const edit = ref<PaketRow | null>(null);
// Input angka kosong = null (harga: "hubungi kami", stok: tanpa batas).
const f = useForm({
    harga: '',
    aktif: true,
    stok: '',
    cpu: '',
    ram_mb: '',
    disk_gb: '',
    ram: '',
    disk: '',
    bandwidth: '',
    harga_input: '',
    harga_cache: '',
    provider: '',
    harga_promo: '',
    promo_mulai: '',
    promo_selesai: '',
});
// Pilihan provider dari paket yang ada (datalist), tetap bisa diketik bebas
const daftarProvider = computed(() =>
    [
        ...new Set(props.paket.map((p) => p.spek?.provider).filter(Boolean)),
    ].sort(),
);
const angkaAtauKosong = (v: unknown) => (v == null ? '' : String(v));

function buka(p: PaketRow) {
    f.clearErrors();
    f.defaults({
        harga: p.harga === null ? '' : String(p.harga),
        aktif: p.aktif,
        stok: p.stok === null ? '' : String(p.stok),
        cpu: p.pod ? String(p.pod.cpu) : (p.spek?.cpu ?? ''),
        ram_mb: p.pod ? String(p.pod.ram_mb) : '',
        disk_gb: String(p.pod?.disk_gb ?? 5),
        ram: p.spek?.ram ?? '',
        disk: p.spek?.disk ?? '',
        bandwidth: p.spek?.bandwidth ?? '',
        harga_input: angkaAtauKosong(p.spek?.harga_input),
        harga_cache: angkaAtauKosong(p.spek?.harga_cache),
        provider: p.spek?.provider ?? '',
        harga_promo: angkaAtauKosong(p.spek?.harga_promo),
        promo_mulai: p.spek?.promo_mulai ?? '',
        promo_selesai: p.spek?.promo_selesai ?? '',
    });
    f.reset();
    edit.value = p;
}

const contohUrl = 'https://api.penyedia.com/v1'; // di script: vue-tsc salah baca // di atribut template

// Paket/model token AI baru (dibuat Off; uji sumber dulu lalu nyalakan)
const tambah = ref(false);
const fAi = useForm({
    nama: '',
    model: '',
    harga: '',
    harga_input: '',
    harga_cache: '',
    provider: '',
    ringkas: '',
    sumber_url: '',
    sumber_model: '',
    sumber_key: '',
});
function bukaTambah() {
    fAi.clearErrors();
    fAi.reset();
    tambah.value = true;
}
function simpanTambah() {
    fAi.submit(PaketController.storeAi(), {
        preserveScroll: true,
        onSuccess: () => (tambah.value = false),
    });
}

// Sumber/tempat kulakan token AI per paket
const editSumber = ref<PaketRow | null>(null);
const fs = useForm({ url: '', model: '', key: '' });
function bukaSumber(p: PaketRow) {
    fs.clearErrors();
    fs.defaults({
        url: p.sumber?.url ?? '',
        model: p.sumber?.model ?? '',
        key: '',
    });
    fs.reset();
    editSumber.value = p;
}
function simpanSumber(kosongkan = false) {
    if (!editSumber.value) return;
    if (kosongkan) fs.defaults({ url: '', model: '', key: '' }).reset();
    fs.submit(PaketController.sumber(editSumber.value.id), {
        preserveScroll: true,
        onSuccess: () => (editSumber.value = null),
    });
}
// Hapus paket AI (server menolak bila sudah dipakai layanan)
const hapus = ref<PaketRow | null>(null);
const fHapus = useForm({});
function simpanHapus() {
    if (!hapus.value) return;
    fHapus.delete(PaketController.destroy(hapus.value.id).url, {
        preserveScroll: true,
        onSuccess: () => (hapus.value = null),
    });
}
const menguji = ref<number | null>(null);
function uji(p: PaketRow) {
    menguji.value = p.id;
    router.post(
        PaketController.ujiSumber(p.id).url,
        {},
        { preserveScroll: true, onFinish: () => (menguji.value = null) },
    );
}
// "25 Nov 2026 00.00 – 31 Des 2026 23.59"; status promo dihitung server (hargaPromo)
const tglPromo = (v?: string) =>
    v
        ? new Intl.DateTimeFormat('id-ID', {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(v))
        : null;
const periodePromo = (s: Record<string, string>) =>
    `${tglPromo(s.promo_mulai) ?? 'sekarang'} – ${tglPromo(s.promo_selesai) ?? 'tanpa batas'}`;
const host = (u: string) => {
    try {
        return new URL(u).host;
    } catch {
        return u;
    }
};

function simpan() {
    if (!edit.value) return;
    const p = edit.value;
    f.transform((d) => {
        const dasar = {
            aktif: d.aktif,
            harga: String(d.harga) === '' ? null : Number(d.harga),
            stok: String(d.stok) === '' ? null : Number(d.stok),
        };
        // Spek hanya dikirim untuk paket yang punya: app ber-pod (limit CPU/RAM) atau VPS/Managed (teks landing).
        if (p.pod)
            return {
                ...dasar,
                cpu: Number(d.cpu),
                ram_mb: Number(d.ram_mb),
                disk_gb: Number(d.disk_gb),
            };
        if (isAi.value)
            return {
                ...dasar,
                harga_input:
                    d.harga_input === '' ? null : Number(d.harga_input),
                harga_cache:
                    d.harga_cache === '' ? null : Number(d.harga_cache),
                provider: d.provider.trim() || null,
                harga_promo:
                    d.harga_promo === '' ? null : Number(d.harga_promo),
                promo_mulai: d.promo_mulai || null,
                promo_selesai: d.promo_selesai || null,
            };
        if (adaSpek.value) {
            const { cpu, ram, disk, bandwidth } = d;
            return { ...dasar, cpu, ram, disk, bandwidth };
        }
        return dasar;
    }).submit(PaketController.update(edit.value.id), {
        preserveScroll: true,
        onSuccess: () => (edit.value = null),
    });
}
</script>

<template>
    <Head :title="`Admin · Paket ${kategori.label}`" />
    <div class="space-y-6 p-4 md:p-6">
        <PageHeader
            :title="`Paket ${kategori.label}`"
            :description="
                kategori.value === 'ai'
                    ? 'Harga input, cache & output per 1 juta token, status jual, dan stok. Saldo pelanggan dihitung setara token output.'
                    : 'Harga, status jual, dan stok. Perubahan harga & status ikut memperbarui situs velocitydeveloper.ai.id.'
            "
        >
            <Button v-if="kategori.value === 'ai' && tab === 'model'" @click="bukaTambah"
                >Tambah paket AI</Button
            >
        </PageHeader>

        <datalist v-if="isAi" id="daftar-provider">
            <option v-for="n in daftarProvider" :key="n" :value="n" />
        </datalist>

        <div v-if="isAi" class="flex w-fit rounded-md border border-border p-0.5 text-sm" role="tablist" aria-label="Tampilan paket AI">
            <button
                v-for="(nama, k) in TAB_AI"
                :key="k"
                type="button"
                role="tab"
                :aria-selected="tab === k"
                class="rounded px-4 py-1.5 font-medium"
                :class="tab === k ? 'bg-brand-700 text-white' : 'text-muted-foreground hover:text-ink'"
                @click="pilihTab(k)"
            >
                {{ nama }}
            </button>
        </div>

        <DataTable
            v-if="!isAi || tab === 'model'"
            :kosong="!daftar.length"
            pesan-kosong="Belum ada paket. Jalankan php84 artisan katalog:sync."
        >
            <thead>
                <tr>
                    <th>Nama</th>
                    <template v-if="adaSpek || isApp">
                        <th>CPU</th>
                        <th>RAM</th>
                        <th>Disk</th>
                        <th v-if="kategori.value === 'vps'">Bandwidth</th>
                    </template>
                    <th v-if="isAi">Provider</th>
                    <th class="text-right">
                        {{
                            isAi
                                ? 'Input · cache · output /1 jt'
                                : kategori.value === 'hosting'
                                  ? 'Harga/tahun'
                                  : 'Harga/bulan'
                        }}
                    </th>
                    <th class="text-right">Terpakai / stok</th>
                    <th>Status</th>
                    <th>Keterangan</th>
                    <th v-if="kategori.value === 'ai'">Sumber AI</th>
                    <th><span class="sr-only">Aksi</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="p in daftar" :key="p.id">
                    <td class="font-semibold text-ink">{{ p.nama }}</td>
                    <td v-if="isAi" class="whitespace-nowrap">
                        {{ p.spek?.provider ?? '-' }}
                    </td>
                    <template v-if="adaSpek || isApp">
                        <td class="whitespace-nowrap">{{ p.spek?.cpu }}</td>
                        <td class="whitespace-nowrap">{{ p.spek?.ram }}</td>
                        <td class="whitespace-nowrap">{{ p.spek?.disk }}</td>
                        <td v-if="kategori.value === 'vps'">
                            {{ p.spek?.bandwidth }}
                        </td>
                    </template>
                    <td class="text-right whitespace-nowrap tabular-nums">
                        <template v-if="isAi && p.spek?.harga_input != null">
                            <span class="text-muted-foreground"
                                >{{ rupiah(Number(p.spek.harga_input)) }} ·
                                {{
                                    rupiah(
                                        Number(
                                            p.spek.harga_cache ??
                                                p.spek.harga_input,
                                        ),
                                    )
                                }}
                                ·
                            </span>
                        </template>
                        {{
                            p.harga === null ? 'Hubungi kami' : rupiah(p.harga)
                        }}
                        <span
                            v-if="isAi && p.spek?.harga_promo != null"
                            :class="[
                                'block text-xs',
                                p.promo_berlaku
                                    ? 'text-brand-700'
                                    : 'text-muted-foreground line-through',
                            ]"
                            :title="
                                p.promo_berlaku
                                    ? 'Promo sedang berlaku'
                                    : 'Promo tidak berlaku sekarang'
                            "
                        >
                            Promo {{ rupiah(Number(p.spek.harga_promo)) }}
                            <span class="text-muted-foreground">{{
                                periodePromo(p.spek)
                            }}</span>
                        </span>
                    </td>
                    <td class="text-right tabular-nums">
                        {{ p.terpakai }} / {{ p.stok ?? '∞' }}
                    </td>
                    <td>
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="p.aktif"
                            :aria-label="`${p.nama}: ${p.aktif ? 'on' : 'off'}`"
                            :disabled="menyimpan === p.id"
                            class="inline-flex items-center gap-2 text-xs font-semibold disabled:opacity-50"
                            @click="saklar(p)"
                        >
                            <span
                                class="relative inline-block h-5 w-9 rounded-full transition-colors"
                                :class="
                                    p.aktif
                                        ? 'bg-brand-700'
                                        : 'bg-muted-foreground/30'
                                "
                            >
                                <span
                                    class="absolute top-0.5 left-0.5 size-4 rounded-full bg-white transition-transform"
                                    :class="p.aktif ? 'translate-x-4' : ''"
                                />
                            </span>
                            {{ p.aktif ? 'On' : 'Off' }}
                        </button>
                    </td>
                    <td>
                        <StatusBadge v-bind="status(p)" />
                    </td>
                    <td v-if="kategori.value === 'ai'" class="text-xs">
                        <template v-if="p.sumber?.url">
                            <span class="font-medium text-ink">{{
                                host(p.sumber.url)
                            }}</span>
                            <span class="block text-muted-foreground">{{
                                p.sumber.model
                            }}</span>
                        </template>
                        <span v-else class="text-muted-foreground"
                            >Bawaan proxy (env)</span
                        >
                    </td>
                    <td class="space-x-2 text-right whitespace-nowrap">
                        <template v-if="kategori.value === 'ai'">
                            <Button
                                v-if="p.sumber?.url"
                                size="sm"
                                variant="ghost"
                                :disabled="menguji === p.id"
                                @click="uji(p)"
                                >{{
                                    menguji === p.id ? 'Menguji…' : 'Uji'
                                }}</Button
                            >
                            <Button
                                size="sm"
                                variant="outline"
                                @click="bukaSumber(p)"
                                >Sumber</Button
                            >
                        </template>
                        <Button size="sm" variant="outline" @click="buka(p)"
                            >Ubah</Button
                        >
                        <Button
                            v-if="isAi"
                            size="sm"
                            variant="ghost"
                            class="text-red-700 hover:text-red-800"
                            :disabled="p.layanan > 0"
                            :title="
                                p.layanan > 0
                                    ? `Dipakai ${p.layanan} layanan, matikan saja`
                                    : undefined
                            "
                            @click="(fHapus.clearErrors(), (hapus = p))"
                            >Hapus</Button
                        >
                    </td>
                </tr>
            </tbody>
        </DataTable>
        <p
            v-if="!isAi || tab === 'model'"
            class="text-xs text-muted-foreground"
        >
            Terpakai = layanan menunggu pembayaran, disiapkan, aktif, atau
            ditangguhkan. Layanan gagal/dibatalkan mengembalikan stok.
        </p>

        <Dialog :open="edit !== null" @update:open="(v) => !v && (edit = null)">
            <DialogContent v-if="edit">
                <DialogHeader>
                    <DialogTitle>Ubah {{ edit.nama }}</DialogTitle>
                    <DialogDescription
                        >{{ edit.kategori_label }} · terpakai
                        {{ edit.terpakai }}. Harga baru berlaku untuk pesanan
                        baru.</DialogDescription
                    >
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="simpan">
                    <div v-if="edit.pod" class="grid grid-cols-2 gap-3">
                        <div class="grid gap-2">
                            <Label for="cpu">CPU</Label>
                            <select
                                id="cpu"
                                v-model="f.cpu"
                                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                            >
                                <option
                                    v-for="c in pilihan(
                                        PILIHAN_CPU,
                                        edit.pod.cpu,
                                    )"
                                    :key="c"
                                    :value="String(c)"
                                >
                                    {{ String(c).replace('.', ',') }} vCPU
                                </option>
                            </select>
                            <InputError :message="f.errors.cpu" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="ram_mb">RAM</Label>
                            <select
                                id="ram_mb"
                                v-model="f.ram_mb"
                                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                            >
                                <option
                                    v-for="r in pilihan(
                                        PILIHAN_RAM,
                                        edit.pod.ram_mb,
                                    )"
                                    :key="r"
                                    :value="String(r)"
                                >
                                    {{ labelRam(r) }}
                                </option>
                            </select>
                            <InputError :message="f.errors.ram_mb" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="disk_gb">Disk</Label>
                            <select
                                id="disk_gb"
                                v-model="f.disk_gb"
                                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                            >
                                <option
                                    v-for="g in edit.pod.disk_gb
                                        ? pilihan(
                                              PILIHAN_DISK,
                                              edit.pod.disk_gb,
                                          )
                                        : PILIHAN_DISK"
                                    :key="g"
                                    :value="String(g)"
                                >
                                    {{ g }} GB
                                </option>
                            </select>
                            <InputError :message="f.errors.disk_gb" />
                        </div>
                        <p class="col-span-2 text-xs text-muted-foreground">
                            Limit pod untuk pesanan baru; app yang sudah
                            berjalan tetap memakai limit lama. Disk tampil ke
                            pelanggan sebagai Storage, tapi belum dibatasi per
                            pod.
                        </p>
                    </div>
                    <div v-else-if="adaSpek" class="grid grid-cols-2 gap-3">
                        <div class="grid gap-2">
                            <Label for="cpu">CPU</Label>
                            <Input
                                id="cpu"
                                v-model="f.cpu"
                                placeholder="1 Core"
                                required
                            />
                            <InputError :message="f.errors.cpu" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="ram">RAM</Label>
                            <Input
                                id="ram"
                                v-model="f.ram"
                                placeholder="2 GB"
                                required
                            />
                            <InputError :message="f.errors.ram" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="disk">Disk</Label>
                            <Input
                                id="disk"
                                v-model="f.disk"
                                placeholder="20 GB NVMe"
                                required
                            />
                            <InputError :message="f.errors.disk" />
                        </div>
                        <div v-if="kategori.value === 'vps'" class="grid gap-2">
                            <Label for="bandwidth">Bandwidth</Label>
                            <Input
                                id="bandwidth"
                                v-model="f.bandwidth"
                                placeholder="1 TB/bln"
                            />
                            <InputError :message="f.errors.bandwidth" />
                        </div>
                        <p
                            v-if="kategori.value === 'vps'"
                            class="col-span-2 text-xs text-muted-foreground"
                        >
                            VPS otomatis memakai angka pertama CPU/RAM/Disk
                            (Core, GB, GB) untuk VM pesanan baru.
                        </p>
                    </div>
                    <div v-if="isAi" class="grid gap-2">
                        <Label for="provider">Provider</Label>
                        <Input
                            id="provider"
                            v-model="f.provider"
                            maxlength="50"
                            list="daftar-provider"
                            placeholder="mis. Anthropic"
                        />
                        <InputError :message="f.errors.provider" />
                    </div>
                    <div v-if="isAi" class="grid grid-cols-2 gap-3">
                        <div class="grid gap-2">
                            <Label for="harga_input"
                                >Input /1 jt token (Rp)</Label
                            >
                            <Input
                                id="harga_input"
                                v-model="f.harga_input"
                                type="number"
                                min="0"
                                placeholder="Kosong = seharga output"
                            />
                            <InputError :message="f.errors.harga_input" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="harga_cache"
                                >Cache /1 jt token (Rp)</Label
                            >
                            <Input
                                id="harga_cache"
                                v-model="f.harga_cache"
                                type="number"
                                min="0"
                                placeholder="Kosong = seharga input"
                            />
                            <InputError :message="f.errors.harga_cache" />
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <Label for="harga">{{
                            isAi
                                ? 'Output /1 jt token (Rp) = harga beli saldo'
                                : kategori.value === 'hosting'
                                  ? 'Harga per tahun (Rp)'
                                  : 'Harga per bulan (Rp)'
                        }}</Label>
                        <Input
                            id="harga"
                            v-model="f.harga"
                            type="number"
                            min="0"
                            :step="isAi ? 1 : 1000"
                            placeholder="Kosong = Hubungi kami"
                        />
                        <InputError :message="f.errors.harga" />
                    </div>
                    <fieldset
                        v-if="isAi"
                        class="grid gap-3 rounded-md border border-input p-3"
                    >
                        <legend class="px-1 text-sm font-medium">
                            Harga promo
                        </legend>
                        <div class="grid gap-2">
                            <Label for="harga_promo"
                                >Harga beli saldo saat promo /1 jt token
                                (Rp)</Label
                            >
                            <Input
                                id="harga_promo"
                                v-model="f.harga_promo"
                                type="number"
                                min="0"
                                placeholder="Kosong = tanpa promo"
                            />
                            <InputError :message="f.errors.harga_promo" />
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="promo_mulai">Dari</Label>
                                <TanggalWaktu
                                    id="promo_mulai"
                                    v-model="f.promo_mulai"
                                    placeholder="Langsung berlaku"
                                />
                                <InputError :message="f.errors.promo_mulai" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="promo_selesai">Sampai</Label>
                                <TanggalWaktu
                                    id="promo_selesai"
                                    v-model="f.promo_selesai"
                                    placeholder="Tanpa batas"
                                    jam-awal="23:59"
                                />
                                <InputError :message="f.errors.promo_selesai" />
                            </div>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Berlaku untuk beli & top-up saldo di dashboard.
                            Potongan saldo per request tetap memakai harga
                            normal. Waktu WIB.
                        </p>
                    </fieldset>
                    <div class="grid gap-2">
                        <Label for="stok">Stok</Label>
                        <Input
                            id="stok"
                            v-model="f.stok"
                            type="number"
                            min="0"
                            placeholder="Kosong = tanpa batas"
                        />
                        <p class="text-xs text-muted-foreground">
                            Jumlah maksimal layanan yang memakai paket ini. Isi
                            0 untuk menutup pesanan baru.
                        </p>
                        <InputError :message="f.errors.stok" />
                    </div>
                    <div class="flex items-center gap-2 text-sm">
                        <input id="aktif" v-model="f.aktif" type="checkbox" />
                        <Label for="aktif"
                            >Dijual (tampil di katalog dashboard)</Label
                        >
                    </div>
                    <DialogFooter>
                        <Button type="submit" :disabled="f.processing"
                            >Simpan</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="hapus !== null"
            @update:open="(v) => !v && (hapus = null)"
        >
            <DialogContent v-if="hapus">
                <DialogHeader>
                    <DialogTitle>Hapus {{ hapus.nama }}?</DialogTitle>
                    <DialogDescription>
                        Paket dan sumber AI-nya dihapus permanen, juga dari
                        situs bila tampil di sana. Tindakan ini tidak bisa
                        dibatalkan.
                    </DialogDescription>
                </DialogHeader>
                <InputError
                    :message="(fHapus.errors as Record<string, string>).hapus"
                />
                <DialogFooter>
                    <Button variant="outline" @click="hapus = null"
                        >Batal</Button
                    >
                    <Button
                        variant="destructive"
                        :disabled="fHapus.processing"
                        @click="simpanHapus"
                        >Hapus</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="editSumber !== null"
            @update:open="(v) => !v && (editSumber = null)"
        >
            <DialogContent v-if="editSumber">
                <DialogHeader>
                    <DialogTitle>Sumber AI · {{ editSumber.nama }}</DialogTitle>
                    <DialogDescription>
                        Tempat kulakan token: API OpenAI-compatible yang
                        dipanggil proxy untuk paket ini. Pelanggan tetap memakai
                        model
                        <code>{{ editSumber.sumber?.model_publik }}</code
                        >. Kosongkan URL untuk memakai sumber bawaan proxy.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="simpanSumber()">
                    <div class="grid gap-2">
                        <Label for="sumber-url">Base URL</Label>
                        <Input
                            id="sumber-url"
                            v-model="fs.url"
                            type="url"
                            :placeholder="contohUrl"
                        />
                        <p class="text-xs text-muted-foreground">
                            Sampai /v1, tanpa /chat/completions.
                        </p>
                        <InputError :message="fs.errors.url" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="sumber-model">Nama model di sumber</Label>
                        <Input
                            id="sumber-model"
                            v-model="fs.model"
                            placeholder="mis. deepseek-chat"
                        />
                        <InputError :message="fs.errors.model" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="sumber-key">API key sumber</Label>
                        <Input
                            id="sumber-key"
                            v-model="fs.key"
                            type="password"
                            autocomplete="off"
                            :placeholder="
                                editSumber?.sumber?.ada_key
                                    ? 'Tersimpan · kosongkan bila tidak diganti'
                                    : 'sk-…'
                            "
                        />
                        <p class="text-xs text-muted-foreground">
                            Disimpan terenkripsi, tidak pernah ditampilkan lagi.
                        </p>
                        <InputError :message="fs.errors.key" />
                    </div>
                    <DialogFooter class="gap-2">
                        <Button
                            v-if="editSumber?.sumber?.url"
                            type="button"
                            variant="ghost"
                            :disabled="fs.processing"
                            @click="simpanSumber(true)"
                            >Pakai bawaan proxy</Button
                        >
                        <Button type="submit" :disabled="fs.processing"
                            >Simpan</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="tambah">
            <DialogContent class="max-h-[90svh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Tambah paket AI</DialogTitle>
                    <DialogDescription>
                        Model baru untuk dijual sebagai token prabayar. Paket
                        dibuat dalam keadaan Off: klik Uji, lalu nyalakan agar
                        tampil di katalog.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="simpanTambah">
                    <div class="grid gap-2">
                        <Label for="ai-nama">Nama paket</Label>
                        <Input
                            id="ai-nama"
                            v-model="fAi.nama"
                            required
                            maxlength="100"
                            placeholder="mis. DeepSeek V4.1 Pro"
                        />
                        <InputError :message="fAi.errors.nama" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="ai-model">Nama model untuk pelanggan</Label>
                        <Input
                            id="ai-model"
                            v-model="fAi.model"
                            required
                            maxlength="100"
                            placeholder="mis. deepseek-v4.1-pro"
                        />
                        <p class="text-xs text-muted-foreground">
                            Dipakai pelanggan di field <code>model</code> API.
                            Tidak bisa diubah sesudah dibuat.
                        </p>
                        <InputError :message="fAi.errors.model" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="ai-provider">Provider</Label>
                        <Input
                            id="ai-provider"
                            v-model="fAi.provider"
                            maxlength="50"
                            list="daftar-provider"
                            placeholder="mis. Anthropic"
                        />
                        <InputError :message="fAi.errors.provider" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="ai-harga"
                            >Output /1 jt token (Rp) = harga beli saldo</Label
                        >
                        <Input
                            id="ai-harga"
                            v-model="fAi.harga"
                            type="number"
                            required
                            min="0"
                        />
                        <InputError :message="fAi.errors.harga" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="grid gap-2">
                            <Label for="ai-harga-input"
                                >Input /1 jt token (Rp)</Label
                            >
                            <Input
                                id="ai-harga-input"
                                v-model="fAi.harga_input"
                                type="number"
                                required
                                min="0"
                            />
                            <InputError :message="fAi.errors.harga_input" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="ai-harga-cache"
                                >Cache /1 jt token (Rp)</Label
                            >
                            <Input
                                id="ai-harga-cache"
                                v-model="fAi.harga_cache"
                                type="number"
                                required
                                min="0"
                            />
                            <InputError :message="fAi.errors.harga_cache" />
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <Label for="ai-ringkas"
                            >Deskripsi singkat di katalog</Label
                        >
                        <Input
                            id="ai-ringkas"
                            v-model="fAi.ringkas"
                            maxlength="300"
                            placeholder="Kosong = dibuat otomatis"
                        />
                        <InputError :message="fAi.errors.ringkas" />
                    </div>
                    <fieldset class="space-y-4 rounded-md border p-3">
                        <legend class="px-1 text-sm font-medium">
                            Sumber AI (tempat kulakan)
                        </legend>
                        <div class="grid gap-2">
                            <Label for="ai-sumber-url">Base URL</Label>
                            <Input
                                id="ai-sumber-url"
                                v-model="fAi.sumber_url"
                                type="url"
                                required
                                :placeholder="contohUrl"
                            />
                            <InputError :message="fAi.errors.sumber_url" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="ai-sumber-model"
                                >Nama model di sumber</Label
                            >
                            <Input
                                id="ai-sumber-model"
                                v-model="fAi.sumber_model"
                                required
                                placeholder="mis. deepseek-reasoner"
                            />
                            <InputError :message="fAi.errors.sumber_model" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="ai-sumber-key">API key sumber</Label>
                            <Input
                                id="ai-sumber-key"
                                v-model="fAi.sumber_key"
                                type="password"
                                required
                                autocomplete="off"
                            />
                            <p class="text-xs text-muted-foreground">
                                Disimpan terenkripsi, tidak pernah ditampilkan
                                lagi.
                            </p>
                            <InputError :message="fAi.errors.sumber_key" />
                        </div>
                    </fieldset>
                    <DialogFooter>
                        <Button type="submit" :disabled="fAi.processing"
                            >Buat paket</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <AnalitikToken v-if="analitik && tab === 'laporan'" :laporan="analitik" />
    </div>
</template>
