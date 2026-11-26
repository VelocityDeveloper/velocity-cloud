<script setup lang="ts">
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import { ref } from "vue";
import PromoController from "@/actions/App/Http/Controllers/Admin/PromoController";
import DataTable from "@/components/cloud/DataTable.vue";
import PageHeader from "@/components/cloud/PageHeader.vue";
import StatusBadge from "@/components/cloud/StatusBadge.vue";
import TanggalWaktu from "@/components/cloud/TanggalWaktu.vue";
import InputError from "@/components/InputError.vue";
import { Button } from "@/components/ui/button";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import admin from "@/routes/admin";

type Kategori = "vps" | "managed" | "app" | "ai" | "hosting";
type Promo = {
    id: number;
    nama: string;
    bonus_token_juta: number;
    min_pembelian: number;
    kategori: Kategori[];
    mulai: string | null;
    selesai: string | null;
    aktif: boolean;
    status: "berlaku" | "terjadwal" | "berakhir" | "nonaktif";
    klaim_count: number;
    pelanggan: number;
    token_total: number;
    omzet: number;
};
type Klaim = {
    id: number;
    promo: string | null;
    pelanggan: string | null;
    email: string | null;
    invoice: string | null;
    paket: string | null;
    nominal: number;
    token_juta: number;
    saldo_token: number | null;
    terpakai_token: number | null;
    waktu: string;
};
defineOptions({
    layout: { breadcrumbs: [{ title: "Admin · Promo", href: admin.promo.index() }] },
});
const props = defineProps<{ promo: Promo[]; klaim: Klaim[]; pilih: number | null }>();

const namaKategori: Record<Kategori, string> = { vps: "Cloud Server", app: "App", ai: "Token AI", managed: "Managed", hosting: "Hosting" };
const labelStatus = { berlaku: "Berlaku", terjadwal: "Terjadwal", berakhir: "Berakhir", nonaktif: "Nonaktif" };
const gayaStatus = { berlaku: "aktif", terjadwal: "terbuka", berakhir: "nonaktif", nonaktif: "nonaktif" };
const rp = (n: number) => "Rp" + n.toLocaleString("id-ID");
const jt = (n: number | null) => (n === null ? "-" : (n / 1_000_000).toLocaleString("id-ID", { maximumFractionDigits: 1 }) + " jt");
const tgl = (s: string | null) =>
    s ? new Date(s).toLocaleString("id-ID", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }) : null;

const edit = ref<Partial<Promo> | null>(null);
const f = useForm({
    nama: "",
    bonus_token_juta: 20,
    min_pembelian: 100000,
    kategori: ["vps", "app", "ai"] as Kategori[],
    mulai: "" as string,
    selesai: "" as string,
    aktif: true,
});

function buka(p: Partial<Promo>) {
    f.clearErrors();
    f.defaults({
        nama: p.nama ?? "",
        bonus_token_juta: p.bonus_token_juta ?? 20,
        min_pembelian: p.min_pembelian ?? 100000,
        kategori: p.kategori ?? ["vps", "app", "ai"],
        mulai: p.mulai ?? "",
        selesai: p.selesai ?? "",
        aktif: p.aktif ?? true,
    });
    f.reset();
    edit.value = p;
}

function simpan() {
    const tujuan = edit.value?.id ? PromoController.update(edit.value.id) : PromoController.store();
    f.transform((d) => ({ ...d, mulai: d.mulai || null, selesai: d.selesai || null })).submit(tujuan, {
        preserveScroll: true,
        onSuccess: () => (edit.value = null),
    });
}

function hapus(p: Promo) {
    if (!confirm(`Hapus promo ${p.nama}?`)) return;
    router.delete(PromoController.destroy(p.id).url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Admin · Promo" />
    <div class="space-y-6 p-4 md:p-6">
        <PageHeader
            title="Promo"
            description="Bonus saldo token AI otomatis saat tagihan lunas. Pelanggan yang belum punya layanan Token AI dibuatkan otomatis."
        >
            <Button @click="buka({})">Tambah Promo</Button>
        </PageHeader>

        <DataTable :kosong="!promo.length" pesan-kosong="Belum ada promo.">
            <thead>
                <tr>
                    <th>Promo</th>
                    <th>Syarat</th>
                    <th>Berlaku</th>
                    <th>Status</th>
                    <th>Dipakai</th>
                    <th>Total token diberikan</th>
                    <th>Omzet pemicu</th>
                    <th><span class="sr-only">Aksi</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="p in promo" :key="p.id" :class="p.id === pilih ? 'bg-brand-50/60' : ''">
                    <td>
                        <span class="font-semibold text-ink">{{ p.nama }}</span>
                        <span class="block text-muted-foreground">Bonus {{ p.bonus_token_juta }} jt token</span>
                    </td>
                    <td class="text-sm">
                        Min. {{ rp(p.min_pembelian) }}
                        <span class="block text-muted-foreground">{{ p.kategori.map((k) => namaKategori[k]).join(", ") }}</span>
                    </td>
                    <td class="text-sm">
                        {{ tgl(p.mulai) ?? "Sejak dibuat" }}
                        <span class="block text-muted-foreground">s/d {{ tgl(p.selesai) ?? "tanpa batas" }}</span>
                    </td>
                    <td><StatusBadge :status="gayaStatus[p.status]" :label="labelStatus[p.status]" /></td>
                    <td>
                        {{ p.klaim_count }}×
                        <span class="block text-muted-foreground">{{ p.pelanggan }} pelanggan</span>
                    </td>
                    <td>
                        {{ p.token_total }} jt
                        <span v-if="p.klaim_count" class="block text-muted-foreground">total dari {{ p.klaim_count }} klaim</span>
                    </td>
                    <td>{{ rp(p.omzet) }}</td>
                    <td class="space-x-2 text-right whitespace-nowrap">
                        <Button size="sm" variant="outline" as-child>
                            <Link :href="admin.promo.index({ query: p.id === pilih ? {} : { promo: p.id } })" preserve-scroll>
                                {{ p.id === pilih ? "Semua klaim" : "Lihat klaim" }}
                            </Link>
                        </Button>
                        <Button size="sm" variant="outline" @click="buka(p)">Ubah</Button>
                        <Button v-if="!p.klaim_count" size="sm" variant="outline" @click="hapus(p)">Hapus</Button>
                    </td>
                </tr>
            </tbody>
        </DataTable>

        <h2 class="font-semibold text-ink">
            Pemakaian promo{{ pilih ? `: ${promo.find((p) => p.id === pilih)?.nama ?? ""}` : "" }}
        </h2>
        <DataTable :kosong="!klaim.length" pesan-kosong="Belum ada pelanggan yang memakai promo.">
            <thead>
                <tr>
                    <th>Waktu</th>
                    <th>Pelanggan</th>
                    <th>Tagihan</th>
                    <th>Promo</th>
                    <th>Bonus</th>
                    <th>Token AI pelanggan</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="k in klaim" :key="k.id">
                    <td>{{ k.waktu }}</td>
                    <td>
                        <span class="font-semibold text-ink">{{ k.pelanggan ?? "-" }}</span>
                        <span class="block text-muted-foreground">{{ k.email }}</span>
                    </td>
                    <td>
                        <span class="font-mono">{{ k.invoice ?? "-" }}</span>
                        <span class="block text-muted-foreground">{{ k.paket }} · {{ rp(k.nominal) }}</span>
                    </td>
                    <td>{{ k.promo }}</td>
                    <td>{{ k.token_juta }} jt</td>
                    <td>
                        Terpakai {{ jt(k.terpakai_token) }}
                        <span class="block text-muted-foreground">Sisa {{ jt(k.saldo_token) }}</span>
                    </td>
                </tr>
            </tbody>
        </DataTable>

        <Dialog :open="edit !== null" @update:open="(v) => !v && (edit = null)">
            <DialogContent v-if="edit" class="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{ edit.id ? "Ubah promo" : "Tambah promo" }}</DialogTitle>
                    <DialogDescription>
                        Bonus diberikan sekali per pembelian baru yang lunas dan memenuhi syarat (pesanan baru dan beli/top-up token). Perpanjangan tidak dapat bonus.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="simpan">
                    <div class="grid gap-2">
                        <Label for="nama">Nama promo</Label>
                        <Input id="nama" v-model="f.nama" maxlength="100" required placeholder="Gratis 20 jt token DeepSeek" />
                        <InputError :message="f.errors.nama" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="bonus">Bonus token (juta)</Label>
                            <Input id="bonus" v-model.number="f.bonus_token_juta" type="number" min="1" max="1000" required />
                            <InputError :message="f.errors.bonus_token_juta" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="min">Minimal tagihan (Rp)</Label>
                            <Input id="min" v-model.number="f.min_pembelian" type="number" min="0" step="1000" required />
                            <InputError :message="f.errors.min_pembelian" />
                        </div>
                    </div>
                    <fieldset class="grid gap-2">
                        <legend class="text-sm font-medium">Berlaku untuk</legend>
                        <label v-for="(nama, k) in namaKategori" :key="k" class="flex items-center gap-2 text-sm">
                            <input v-model="f.kategori" type="checkbox" :value="k" />
                            {{ nama }}
                        </label>
                        <InputError :message="f.errors.kategori" />
                    </fieldset>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="mulai">Mulai</Label>
                            <TanggalWaktu id="mulai" v-model="f.mulai" placeholder="Langsung berlaku" />
                            <p class="text-xs text-muted-foreground">Kosong = langsung berlaku.</p>
                            <InputError :message="f.errors.mulai" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="selesai">Berlaku sampai</Label>
                            <TanggalWaktu id="selesai" v-model="f.selesai" placeholder="Tanpa batas" jam-awal="23:59" />
                            <p class="text-xs text-muted-foreground">Kosong = tanpa batas. Waktu WIB.</p>
                            <InputError :message="f.errors.selesai" />
                        </div>
                    </div>
                    <div class="flex items-center gap-2 text-sm">
                        <input id="aktif" v-model="f.aktif" type="checkbox" />
                        <Label for="aktif">Aktif</Label>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="edit = null">Batal</Button>
                        <Button type="submit" :disabled="f.processing">Simpan</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
