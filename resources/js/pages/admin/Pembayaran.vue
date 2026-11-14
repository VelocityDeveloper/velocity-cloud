<script setup lang="ts">
import { Head, router, useForm } from "@inertiajs/vue3";
import { ref } from "vue";
import PembayaranController from "@/actions/App/Http/Controllers/Admin/PembayaranController";
import DataTable from "@/components/cloud/DataTable.vue";
import PageHeader from "@/components/cloud/PageHeader.vue";
import StatusBadge from "@/components/cloud/StatusBadge.vue";
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

type Jenis = "qris" | "bank" | "ewallet" | "lainnya";
type Metode = {
    id: number;
    jenis: Jenis;
    nama: string;
    nomor: string | null;
    atas_nama: string | null;
    instruksi: string | null;
    urutan: number;
    aktif: boolean;
    gambar_url: string | null;
};
defineOptions({
    layout: {
        breadcrumbs: [{ title: "Admin · Pembayaran", href: admin.pembayaran.index() }],
    },
});
const props = defineProps<{ metode: Metode[]; jenis: Record<Jenis, string> }>();

const edit = ref<Partial<Metode> | null>(null);
const pratinjau = ref<string | null>(null);
const f = useForm({
    jenis: "qris" as Jenis,
    nama: "",
    nomor: "",
    atas_nama: "",
    instruksi: "",
    urutan: 0,
    aktif: true,
    gambar: null as File | null,
});

function buka(m: Partial<Metode>) {
    f.clearErrors();
    f.defaults({
        jenis: m.jenis ?? "qris",
        nama: m.nama ?? "",
        nomor: m.nomor ?? "",
        atas_nama: m.atas_nama ?? "",
        instruksi: m.instruksi ?? "",
        urutan: m.urutan ?? props.metode.length,
        aktif: m.aktif ?? true,
        gambar: null,
    });
    f.reset();
    pratinjau.value = m.gambar_url ?? null;
    edit.value = m;
}

function pilihGambar(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    f.gambar = file;
    pratinjau.value = file ? URL.createObjectURL(file) : (edit.value?.gambar_url ?? null);
}

function simpan() {
    const tujuan = edit.value?.id
        ? PembayaranController.update(edit.value.id)
        : PembayaranController.store();
    f.transform((d) => ({ ...d, aktif: d.aktif ? 1 : 0 })).submit(tujuan, {
        forceFormData: true,
        onSuccess: () => (edit.value = null),
    });
}

function hapus(m: Metode) {
    if (!confirm(`Hapus metode ${m.nama}?`)) return;
    router.delete(PembayaranController.destroy(m.id).url, { preserveScroll: true });
}

const labelNomor: Record<Jenis, string> = {
    qris: "NMID (opsional)",
    bank: "Nomor rekening",
    ewallet: "Nomor e-wallet",
    lainnya: "Nomor / kode (opsional)",
};
</script>

<template>
    <Head title="Admin · Pembayaran" />
    <div class="space-y-6 p-4 md:p-6">
        <PageHeader
            title="Pembayaran"
            description="Metode yang tampil di halaman tagihan pelanggan. Setelah pelanggan membayar, tandai lunas di menu Tagihan."
        >
            <Button @click="buka({})">Tambah Metode</Button>
        </PageHeader>
        <DataTable
            :kosong="!metode.length"
            pesan-kosong="Belum ada metode. Pelanggan hanya melihat tombol konfirmasi via WhatsApp."
        >
            <thead>
                <tr>
                    <th>Metode</th>
                    <th>Jenis</th>
                    <th>Nomor / atas nama</th>
                    <th>Status</th>
                    <th><span class="sr-only">Aksi</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="m in metode" :key="m.id">
                    <td class="font-semibold text-ink">
                        <div class="flex items-center gap-3">
                            <img
                                v-if="m.gambar_url"
                                :src="m.gambar_url"
                                :alt="`QR ${m.nama}`"
                                class="size-10 rounded border border-border object-contain"
                            />
                            {{ m.nama }}
                        </div>
                    </td>
                    <td>{{ jenis[m.jenis] }}</td>
                    <td class="text-sm">
                        <span class="font-mono">{{ m.nomor ?? "-" }}</span>
                        <span v-if="m.atas_nama" class="block text-muted-foreground">a.n. {{ m.atas_nama }}</span>
                    </td>
                    <td>
                        <StatusBadge
                            :status="m.aktif ? 'aktif' : 'nonaktif'"
                            :label="m.aktif ? 'Aktif' : 'Nonaktif'"
                        />
                    </td>
                    <td class="space-x-2 text-right whitespace-nowrap">
                        <Button size="sm" variant="outline" @click="buka(m)">Ubah</Button>
                        <Button size="sm" variant="outline" @click="hapus(m)">Hapus</Button>
                    </td>
                </tr>
            </tbody>
        </DataTable>

        <Dialog :open="edit !== null" @update:open="(v) => !v && (edit = null)">
            <DialogContent v-if="edit" class="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{ edit.id ? "Ubah metode" : "Tambah metode" }}</DialogTitle>
                    <DialogDescription>
                        QRIS wajib unggah gambar QR (PNG/JPG/WebP, maks 2 MB).
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="simpan">
                    <div class="grid gap-2">
                        <Label for="jenis">Jenis</Label>
                        <select
                            id="jenis"
                            v-model="f.jenis"
                            class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                        >
                            <option v-for="(nama, kunci) in jenis" :key="kunci" :value="kunci">
                                {{ nama }}
                            </option>
                        </select>
                        <InputError :message="f.errors.jenis" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="nama">Nama</Label>
                        <Input
                            id="nama"
                            v-model="f.nama"
                            required
                            maxlength="60"
                            :placeholder="{ qris: 'QRIS', bank: 'BCA', ewallet: 'DANA', lainnya: 'Tunai di kantor' }[f.jenis]"
                        />
                        <InputError :message="f.errors.nama" />
                    </div>
                    <div v-if="f.jenis === 'qris'" class="grid gap-2">
                        <Label for="gambar">Gambar QRIS{{ edit.gambar_url ? ", kosongkan jika tidak diganti" : "" }}</Label>
                        <input
                            id="gambar"
                            type="file"
                            accept="image/png,image/jpeg,image/webp"
                            :required="!edit.gambar_url"
                            class="text-sm"
                            @change="pilihGambar"
                        />
                        <img
                            v-if="pratinjau"
                            :src="pratinjau"
                            alt="Pratinjau QRIS"
                            class="size-40 rounded border border-border object-contain"
                        />
                        <InputError :message="f.errors.gambar" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="nomor">{{ labelNomor[f.jenis] }}</Label>
                        <Input id="nomor" v-model="f.nomor" maxlength="60" :required="f.jenis === 'bank' || f.jenis === 'ewallet'" />
                        <InputError :message="f.errors.nomor" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="atas_nama">Atas nama</Label>
                        <Input id="atas_nama" v-model="f.atas_nama" maxlength="100" />
                        <InputError :message="f.errors.atas_nama" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="instruksi">Instruksi (opsional)</Label>
                        <textarea
                            id="instruksi"
                            v-model="f.instruksi"
                            rows="3"
                            maxlength="1000"
                            placeholder="Contoh: sertakan nomor tagihan di berita transfer."
                            class="rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                        />
                        <InputError :message="f.errors.instruksi" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="urutan">Urutan tampil</Label>
                        <Input id="urutan" v-model.number="f.urutan" type="number" min="0" max="999" class="max-w-28" />
                        <InputError :message="f.errors.urutan" />
                    </div>
                    <div class="flex items-center gap-2 text-sm">
                        <input id="aktif" v-model="f.aktif" type="checkbox" />
                        <Label for="aktif">Tampil di halaman tagihan</Label>
                    </div>
                    <DialogFooter>
                        <Button type="submit" :disabled="f.processing">Simpan</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
