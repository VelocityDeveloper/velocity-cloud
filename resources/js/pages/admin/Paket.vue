<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PaketController from '@/actions/App/Http/Controllers/Admin/PaketController';
import AnalitikToken from '@/components/analitik/AnalitikToken.vue';
import type { LaporanToken } from '@/components/analitik/AnalitikToken.vue';
import DataTable from '@/components/cloud/DataTable.vue';
import PageHeader from '@/components/cloud/PageHeader.vue';
import StatusBadge from '@/components/cloud/StatusBadge.vue';
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
    kategori: 'vps' | 'managed' | 'app' | 'ai';
    kategori_label: string;
    nama: string;
    harga: number | null;
    aktif: boolean;
    stok: number | null;
    terpakai: number;
    bisa_dipesan: boolean;
    spek: Record<string, string> | null;
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
const adaSpek = computed(() => ['vps', 'managed'].includes(props.kategori.value));

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
const f = useForm({ harga: '', aktif: true, stok: '' });

function buka(p: PaketRow) {
    f.clearErrors();
    f.defaults({
        harga: p.harga === null ? '' : String(p.harga),
        aktif: p.aktif,
        stok: p.stok === null ? '' : String(p.stok),
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
const menguji = ref<number | null>(null);
function uji(p: PaketRow) {
    menguji.value = p.id;
    router.post(
        PaketController.ujiSumber(p.id).url,
        {},
        { preserveScroll: true, onFinish: () => (menguji.value = null) },
    );
}
const host = (u: string) => {
    try {
        return new URL(u).host;
    } catch {
        return u;
    }
};

function simpan() {
    if (!edit.value) return;
    f.transform((d) => ({
        ...d,
        harga: String(d.harga) === '' ? null : Number(d.harga),
        stok: String(d.stok) === '' ? null : Number(d.stok),
    })).submit(PaketController.update(edit.value.id), {
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
                    ? 'Harga per 1 juta token, status jual, dan stok. Token AI tidak tampil di situs.'
                    : 'Harga, status jual, dan stok. Perubahan harga & status ikut memperbarui situs velocitydeveloper.ai.id.'
            "
        >
            <Button v-if="kategori.value === 'ai'" @click="bukaTambah"
                >Tambah paket AI</Button
            >
        </PageHeader>

        <DataTable
            :kosong="!daftar.length"
            pesan-kosong="Belum ada paket. Jalankan php84 artisan katalog:sync."
        >
            <thead>
                <tr>
                    <th>Nama</th>
                    <template v-if="adaSpek">
                        <th>CPU</th>
                        <th>RAM</th>
                        <th>Disk</th>
                        <th v-if="kategori.value === 'vps'">Bandwidth</th>
                    </template>
                    <th class="text-right">Harga/bulan</th>
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
                    <template v-if="adaSpek">
                        <td>{{ p.spek?.cpu }}</td>
                        <td>{{ p.spek?.ram }}</td>
                        <td>{{ p.spek?.disk }}</td>
                        <td v-if="kategori.value === 'vps'">
                            {{ p.spek?.bandwidth }}
                        </td>
                    </template>
                    <td class="text-right tabular-nums">
                        {{
                            p.harga === null ? 'Hubungi kami' : rupiah(p.harga)
                        }}
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
                    </td>
                </tr>
            </tbody>
        </DataTable>
        <p class="text-xs text-muted-foreground">
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
                    <div class="grid gap-2">
                        <Label for="harga">Harga per bulan (Rp)</Label>
                        <Input
                            id="harga"
                            v-model="f.harga"
                            type="number"
                            min="0"
                            step="1000"
                            placeholder="Kosong = Hubungi kami"
                        />
                        <InputError :message="f.errors.harga" />
                    </div>
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
                        <Label for="ai-harga"
                            >Harga per 1 juta token (Rp)</Label
                        >
                        <Input
                            id="ai-harga"
                            v-model="fAi.harga"
                            type="number"
                            required
                            min="0"
                            step="500"
                        />
                        <InputError :message="fAi.errors.harga" />
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

        <AnalitikToken v-if="analitik" :laporan="analitik" />
    </div>
</template>
