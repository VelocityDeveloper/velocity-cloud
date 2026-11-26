<script setup lang="ts">
import { Head, router, useForm } from "@inertiajs/vue3";
import { ref } from "vue";
import NotifikasiController from "@/actions/App/Http/Controllers/Admin/NotifikasiController";
import DataTable from "@/components/cloud/DataTable.vue";
import PageHeader from "@/components/cloud/PageHeader.vue";
import InputError from "@/components/InputError.vue";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import admin from "@/routes/admin";

type Siaran = { kiriman: string; jenis?: string; judul: string; isi: string; url?: string; waktu: string; penerima: number; dibaca: number; ke: string | null };
defineOptions({
    layout: { breadcrumbs: [{ title: "Admin · Notifikasi", href: admin.notifikasi.index() }] },
});
const props = defineProps<{ riwayat: Siaran[]; jumlah_pengguna: number }>();

const buka = ref(false);
const f = useForm({ tujuan: "semua" as "semua" | "email", email: "", judul: "", isi: "", url: "" });
const tgl = (s: string) =>
    new Date(s.replace(" ", "T")).toLocaleString("id-ID", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });

function kirim() {
    const ke = f.tujuan === "semua" ? `SEMUA ${props.jumlah_pengguna} pengguna` : f.email;
    if (!confirm(`Kirim notifikasi "${f.judul}" ke ${ke}?`)) return;
    f.transform((d) => ({ ...d, email: d.tujuan === "email" ? d.email : null, url: d.url || null })).submit(NotifikasiController.store(), {
        preserveScroll: true,
        onSuccess: () => {
            f.reset();
            buka.value = false;
        },
    });
}

function tarik(s: Siaran) {
    if (!confirm(`Tarik "${s.judul}" dari lonceng ${s.penerima} pengguna?`)) return;
    router.delete(NotifikasiController.destroy(s.kiriman).url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Admin · Notifikasi" />
    <div class="space-y-6 p-4 md:p-6">
        <PageHeader title="Notifikasi" description="Kirim info ke lonceng dashboard pelanggan, mis. promo atau pemeliharaan.">
            <Button @click="buka = true">Kirim notifikasi</Button>
        </PageHeader>

        <Dialog v-model:open="buka">
            <DialogContent class="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Kirim notifikasi</DialogTitle>
                    <DialogDescription>Muncul di lonceng dashboard penerima.</DialogDescription>
                </DialogHeader>
                <form class="grid gap-4" @submit.prevent="kirim">
                    <fieldset class="grid gap-2">
                        <legend class="mb-2 text-sm font-medium">Tujuan</legend>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="f.tujuan" type="radio" value="semua" /> Semua pengguna ({{ jumlah_pengguna }})
                        </label>
                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="f.tujuan" type="radio" value="email" /> Satu pengguna (email)
                        </label>
                        <Input v-if="f.tujuan === 'email'" v-model="f.email" type="email" placeholder="email@pelanggan.com" aria-label="Email pengguna" />
                        <InputError :message="f.errors.email" />
                    </fieldset>
                    <div class="grid gap-2">
                        <Label for="judul">Judul</Label>
                        <Input id="judul" v-model="f.judul" maxlength="120" placeholder="Token DeepSeek diskon 20% sampai 30 Nov" required />
                        <InputError :message="f.errors.judul" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="isi">Isi</Label>
                        <textarea
                            id="isi"
                            v-model="f.isi"
                            rows="3"
                            maxlength="500"
                            required
                            class="rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                        ></textarea>
                        <InputError :message="f.errors.isi" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="url">Tautan (opsional)</Label>
                        <Input id="url" v-model="f.url" maxlength="255" placeholder="/katalog/ai" />
                        <p class="text-xs text-muted-foreground">Halaman yang dibuka saat notifikasi diklik. Isi path di aplikasi ini.</p>
                        <InputError :message="f.errors.url" />
                    </div>
                    <div class="flex justify-end gap-2">
                        <Button type="button" variant="outline" @click="buka = false">Batal</Button>
                        <Button type="submit" :disabled="f.processing">Kirim notifikasi</Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <section>
            <h2 class="mb-3 text-lg font-semibold text-ink">Riwayat kiriman</h2>
            <DataTable :kosong="!riwayat.length" pesan-kosong="Belum ada notifikasi.">
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>Judul & isi</th>
                        <th>Tautan</th>
                        <th class="text-right">Dibaca</th>
                        <th class="text-right"><span class="sr-only">Aksi</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in riwayat" :key="s.kiriman">
                        <td class="whitespace-nowrap">{{ tgl(s.waktu) }}</td>
                        <td>
                            <strong class="block text-ink">{{ s.judul }}</strong>
                            <span class="text-muted-foreground">{{ s.isi }}</span>
                        </td>
                        <td>{{ s.url ?? "-" }}</td>
                        <td class="text-right tabular-nums">
                            {{ s.dibaca }} / {{ s.penerima }}
                            <span v-if="s.ke" class="block text-xs text-muted-foreground">{{ s.ke }}</span>
                        </td>
                        <td class="text-right">
                            <Button size="sm" variant="outline" @click="tarik(s)">Tarik</Button>
                        </td>
                    </tr>
                </tbody>
            </DataTable>
        </section>
    </div>
</template>
