<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Settings2 } from '@lucide/vue';
import { ref } from 'vue';
import LayananController from '@/actions/App/Http/Controllers/Admin/LayananController';
import DataTable from '@/components/cloud/DataTable.vue';
import FilterStatus from '@/components/cloud/FilterStatus.vue';
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
import { Label } from '@/components/ui/label';
import { tanggal } from '@/lib/format';
import admin from '@/routes/admin';
import type { Layanan } from '@/types';

type Baris = Layanan & {
    pelanggan: string;
    email: string;
    node: string | null;
    akses: string | null;
    custom_domain: string | null;
    pesan_gagal: string | null;
};
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin · Layanan', href: admin.layanan.index() },
        ],
    },
});
defineProps<{
    status: string;
    daftar_status: { value: string; label: string }[];
    jenis: string;
    daftar_jenis: { value: string; label: string }[];
    layanan: Baris[];
}>();

const dipilih = ref<Baris | null>(null);
</script>

<template>
    <Head title="Admin · Layanan" />
    <div class="space-y-6 p-4 md:p-6">
        <PageHeader
            title="Layanan"
            description="Cloud Server dan Managed Server disiapkan manual: isi info akses lalu ubah status ke Aktif. Akun Shared Hosting dibuat otomatis; bila gagal, klik Ulangi."
        >
            <div class="flex flex-wrap items-center gap-3">
                <FilterStatus
                    :nilai="jenis"
                    :pilihan="daftar_jenis"
                    param="jenis"
                    judul="Jenis"
                />
                <FilterStatus :nilai="status" :pilihan="daftar_status" />
            </div>
        </PageHeader>
        <DataTable
            :kosong="!layanan.length"
            pesan-kosong="Tidak ada layanan dengan filter ini."
        >
            <thead>
                <tr>
                    <th>Layanan</th>
                    <th>Pelanggan</th>
                    <th>Paket</th>
                    <th>Node</th>
                    <th>Status</th>
                    <th>Jatuh tempo</th>
                    <th><span class="sr-only">Aksi</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="l in layanan" :key="l.id">
                    <td class="font-semibold text-ink">
                        {{ l.label
                        }}<span
                            v-if="l.url || l.custom_domain"
                            class="block text-xs font-normal text-muted-foreground"
                            >{{ l.url ?? l.custom_domain }}</span
                        >
                    </td>
                    <td>
                        {{ l.pelanggan
                        }}<span class="block text-xs text-muted-foreground">{{
                            l.email
                        }}</span>
                    </td>
                    <td>{{ l.kategori_label }} · {{ l.paket }}</td>
                    <td>{{ l.node ?? '-' }}</td>
                    <td>
                        <StatusBadge
                            :status="l.status"
                            :label="l.status_label"
                        /><span
                            v-if="l.pesan_gagal"
                            class="block max-w-64 truncate text-xs text-red-700"
                            :title="l.pesan_gagal"
                            >{{ l.pesan_gagal }}</span
                        >
                    </td>
                    <td>{{ tanggal(l.jatuh_tempo) }}</td>
                    <td class="space-x-2 text-right">
                        <Form
                            v-if="l.status === 'gagal' && (l.kategori === 'app' || l.kategori === 'hosting' || l.kategori === 'domain')"
                            v-bind="LayananController.ulangi.form(l.id)"
                            class="inline"
                            v-slot="{ processing }"
                        >
                            <Button
                                type="submit"
                                size="sm"
                                variant="outline"
                                :disabled="processing"
                                >Ulangi</Button
                            >
                        </Form>
                        <Button
                            size="icon-sm"
                            variant="outline"
                            :aria-label="`Kelola ${l.label}`"
                            title="Kelola"
                            @click="dipilih = l"
                            ><Settings2 class="size-4" aria-hidden="true"
                        /></Button>
                    </td>
                </tr>
            </tbody>
        </DataTable>

        <Dialog
            :open="dipilih !== null"
            @update:open="(v) => !v && (dipilih = null)"
        >
            <DialogContent v-if="dipilih">
                <DialogHeader>
                    <DialogTitle>{{ dipilih.label }}</DialogTitle>
                    <DialogDescription
                        >{{ dipilih.pelanggan }} · {{ dipilih.kategori_label }}
                        {{ dipilih.paket }}</DialogDescription
                    >
                </DialogHeader>
                <Form
                    v-bind="LayananController.update.form(dipilih.id)"
                    class="space-y-4"
                    v-slot="{ errors, processing }"
                    @success="dipilih = null"
                >
                    <div class="grid gap-2">
                        <Label for="status">Status</Label>
                        <select
                            id="status"
                            name="status"
                            :value="dipilih.status"
                            class="h-9 rounded-md border border-input bg-white px-3 text-sm"
                        >
                            <option
                                v-for="s in daftar_status"
                                :key="s.value"
                                :value="s.value"
                            >
                                {{ s.label }}
                            </option>
                        </select>
                        <InputError :message="errors.status" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="akses">Info akses untuk pelanggan</Label>
                        <textarea
                            id="akses"
                            name="akses"
                            rows="5"
                            maxlength="2000"
                            class="rounded-md border border-input px-3 py-2 font-mono text-sm"
                            :value="dipilih.akses ?? ''"
                            placeholder="IP: &#10;Username: &#10;Sandi awal: "
                        ></textarea>
                        <p class="text-xs text-muted-foreground">
                            Tampil di halaman layanan pelanggan.
                        </p>
                        <InputError :message="errors.akses" />
                    </div>
                    <DialogFooter
                        ><Button type="submit" :disabled="processing"
                            >Simpan</Button
                        ></DialogFooter
                    >
                </Form>
            </DialogContent>
        </Dialog>
    </div>
</template>
