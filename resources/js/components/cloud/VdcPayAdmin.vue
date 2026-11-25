<script setup lang="ts">
import { router, useForm, usePage } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import CheckoutKlienController from "@/actions/App/Http/Controllers/Admin/CheckoutKlienController";
import DataTable from "@/components/cloud/DataTable.vue";
import StatusBadge from "@/components/cloud/StatusBadge.vue";
import TombolSalin from "@/components/cloud/TombolSalin.vue";
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
import { rupiah } from "@/lib/format";

export type Klien = {
    id: number;
    nama: string;
    kode: string;
    kunci_akhir: string | null;
    url_webhook: string;
    host_kembali: string; // satu host per baris
    aktif: boolean;
};
type Webhook = { id: number; jenis: string; status: "antri" | "terkirim" | "gagal"; percobaan: number; kode_respons: number | null; galat: string | null };
type Sesi = { id: string; klien: string; ref: string; jumlah: number; status: string; status_label: string; dibuat: string; webhook: Webhook[] };
export type VdcPay = { klien: Klien[]; sesi: Sesi[]; url_api: string };

defineProps<{ vdcpay: VdcPay }>();

// API key & rahasia webhook hanya datang sekali lewat flash sesudah dibuat
const page = usePage();
const kunci = computed(() => (page.flash as Record<string, unknown>)?.kunci_klien as
    { klien: string; api_key: string; rahasia_webhook: string } | undefined);
const tutupKunci = ref(false);

const edit = ref<Partial<Klien> | null>(null);
const f = useForm({ nama: "", kode: "", url_webhook: "", host_kembali: "", aktif: true });

function buka(k: Partial<Klien>) {
    f.clearErrors();
    f.defaults({ nama: k.nama ?? "", kode: k.kode ?? "", url_webhook: k.url_webhook ?? "", host_kembali: k.host_kembali ?? "", aktif: k.aktif ?? true });
    f.reset();
    edit.value = k;
}

function simpan() {
    const opsi = { preserveScroll: true, onSuccess: () => { edit.value = null; tutupKunci.value = false; } };
    if (edit.value?.id) {
        f.transform(({ kode: _kode, ...d }) => d).put(CheckoutKlienController.update(edit.value.id).url, opsi);
    } else {
        f.transform((d) => d).post(CheckoutKlienController.store().url, opsi);
    }
}

function kunciBaru(k: Klien) {
    if (!confirm(`Buat API key & rahasia webhook baru untuk ${k.nama}? Kunci lama langsung tidak berlaku.`)) return;
    tutupKunci.value = false;
    router.post(CheckoutKlienController.kunci(k.id).url, {}, { preserveScroll: true });
}

const kirimUlang = (w: Webhook) => router.post(CheckoutKlienController.kirimUlang(w.id).url, {}, { preserveScroll: true });

const warnaWebhook = (s: Webhook["status"]) => ({ terkirim: "aktif", antri: "menunggu", gagal: "gagal" })[s];
</script>

<template>
    <section class="space-y-4 rounded-lg border border-border p-5" aria-labelledby="judul-vdcpay">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="judul-vdcpay" class="font-semibold text-ink">VDC Pay (halaman bayar untuk web lain)</h2>
                <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                    Web klien membuat sesi bayar lewat API, pembeli membayar di halaman ini lewat Midtrans di atas, lalu hasilnya
                    dikirim ke webhook klien. API: <code class="text-xs">{{ vdcpay.url_api }}</code>
                </p>
            </div>
            <Button @click="buka({})">Tambah Klien</Button>
        </div>

        <div v-if="kunci && !tutupKunci" class="space-y-3 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm dark:bg-amber-950/30" role="alert">
            <p class="font-semibold">Kunci baru {{ kunci.klien }}: salin sekarang, tidak akan ditampilkan lagi.</p>
            <div v-for="(nilai, label) in { 'API key': kunci.api_key, 'Rahasia webhook': kunci.rahasia_webhook }" :key="label" class="grid gap-1">
                <span class="text-xs text-muted-foreground">{{ label }}</span>
                <div class="flex items-center gap-2 rounded-md border border-border bg-background px-3 py-2">
                    <code class="min-w-0 flex-1 truncate">{{ nilai }}</code>
                    <TombolSalin :teks="nilai" :label="label" />
                </div>
            </div>
            <Button size="sm" variant="outline" @click="tutupKunci = true">Sudah saya simpan</Button>
        </div>

        <DataTable :kosong="!vdcpay.klien.length" pesan-kosong="Belum ada web klien.">
            <thead>
                <tr>
                    <th>Klien</th>
                    <th>Webhook</th>
                    <th>API key</th>
                    <th>Status</th>
                    <th><span class="sr-only">Aksi</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="k in vdcpay.klien" :key="k.id">
                    <td class="font-semibold text-ink">{{ k.nama }} <span class="font-mono text-xs text-muted-foreground">{{ k.kode }}</span></td>
                    <td class="max-w-64 truncate text-sm">{{ k.url_webhook }}</td>
                    <td class="font-mono text-sm">{{ k.kunci_akhir ? `…${k.kunci_akhir}` : "-" }}</td>
                    <td><StatusBadge :status="k.aktif ? 'aktif' : 'nonaktif'" :label="k.aktif ? 'Aktif' : 'Nonaktif'" /></td>
                    <td class="space-x-2 text-right whitespace-nowrap">
                        <Button size="sm" variant="outline" @click="buka(k)">Ubah</Button>
                        <Button size="sm" variant="outline" @click="kunciBaru(k)">Kunci baru</Button>
                    </td>
                </tr>
            </tbody>
        </DataTable>

        <h3 class="text-sm font-semibold text-ink">20 sesi terakhir</h3>
        <DataTable :kosong="!vdcpay.sesi.length" pesan-kosong="Belum ada sesi bayar.">
            <thead>
                <tr>
                    <th>Sesi</th>
                    <th>Jumlah</th>
                    <th>Status</th>
                    <th>Webhook</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="s in vdcpay.sesi" :key="s.id">
                    <td class="text-sm">
                        <span class="font-mono">{{ s.klien }} · {{ s.ref }}</span>
                        <span class="block text-xs text-muted-foreground">{{ new Date(s.dibuat).toLocaleString("id-ID") }}</span>
                    </td>
                    <td>{{ rupiah(s.jumlah) }}</td>
                    <td><StatusBadge :status="s.status" :label="s.status_label" /></td>
                    <td class="space-y-1 text-sm">
                        <div v-for="w in s.webhook" :key="w.id" class="flex flex-wrap items-center gap-2">
                            <StatusBadge :status="warnaWebhook(w.status)" :label="`${w.jenis} · ${w.status}`" />
                            <span class="text-xs text-muted-foreground" :title="w.galat ?? ''">
                                {{ w.percobaan }}× {{ w.kode_respons ? `HTTP ${w.kode_respons}` : "" }}
                            </span>
                            <Button v-if="w.status === 'gagal'" size="sm" variant="outline" @click="kirimUlang(w)">Kirim ulang</Button>
                        </div>
                        <span v-if="!s.webhook.length" class="text-muted-foreground">-</span>
                    </td>
                </tr>
            </tbody>
        </DataTable>

        <Dialog :open="edit !== null" @update:open="(v) => !v && (edit = null)">
            <DialogContent v-if="edit">
                <DialogHeader>
                    <DialogTitle>{{ edit.id ? "Ubah klien" : "Tambah klien" }}</DialogTitle>
                    <DialogDescription>
                        {{ edit.id ? "Kode tidak bisa diubah." : "API key & rahasia webhook dibuat otomatis sesudah disimpan." }}
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="simpan">
                    <div class="grid gap-2">
                        <Label for="vp-nama">Nama (tampil di halaman bayar)</Label>
                        <Input id="vp-nama" v-model="f.nama" required maxlength="60" placeholder="Velocia" />
                        <InputError :message="f.errors.nama" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="vp-kode">Kode order</Label>
                        <Input id="vp-kode" v-model="f.kode" :disabled="!!edit.id" required maxlength="6" class="max-w-32 font-mono uppercase"
                            placeholder="VCM" @input="f.kode = f.kode.toUpperCase()" />
                        <p class="text-xs text-muted-foreground">2–6 huruf besar/angka; order Midtrans menjadi CS-{{ f.kode || "KODE" }}-…</p>
                        <InputError :message="f.errors.kode" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="vp-webhook">URL webhook (https)</Label>
                        <Input id="vp-webhook" v-model="f.url_webhook" type="url" required maxlength="255" placeholder="https://app.contoh.id/vdcpay/webhook" />
                        <InputError :message="f.errors.url_webhook" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="vp-host">Host yang boleh untuk URL kembali</Label>
                        <textarea id="vp-host" v-model="f.host_kembali" rows="3" required maxlength="1000"
                            placeholder="app.velocitycybermedia.id" class="rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm" />
                        <p class="text-xs text-muted-foreground">Satu host per baris. url_sukses/url_batal di luar daftar ini ditolak.</p>
                        <InputError :message="f.errors.host_kembali" />
                    </div>
                    <div class="flex items-center gap-2 text-sm">
                        <input id="vp-aktif" v-model="f.aktif" type="checkbox" />
                        <Label for="vp-aktif">Aktif (API key bisa dipakai)</Label>
                    </div>
                    <DialogFooter>
                        <Button type="submit" :disabled="f.processing">Simpan</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </section>
</template>
