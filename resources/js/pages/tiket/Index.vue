<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import TiketController from '@/actions/App/Http/Controllers/TiketController';
import DataTable from '@/components/cloud/DataTable.vue';
import PageHeader from '@/components/cloud/PageHeader.vue';
import PilihLampiran from '@/components/cloud/PilihLampiran.vue';
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
import { tanggal } from '@/lib/format';
import tiketRoute from '@/routes/tiket';
import type { Tiket } from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Support Ticket', href: tiketRoute.index() }] },
});
defineProps<{ tiket: Tiket[]; jenis: Record<Tiket['jenis'], string>; layanan: { id: number; label: string }[] }>();

// Prefill dari tautan lain, mis. tombol konfirmasi bayar di halaman tagihan: /tiket?jenis=billing&judul=...&isi=...
const q = new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search);

const buka = ref(q.has('judul'));
const f = useForm({
    jenis: (q.get('jenis') === 'billing' ? 'billing' : 'support') as Tiket['jenis'],
    judul: q.get('judul') ?? '',
    service_id: (Number(q.get('layanan')) || '') as number | '',
    isi: q.get('isi') ?? '',
    lampiran: [] as File[],
});
function kirim() {
    f.transform((d) => ({ ...d, service_id: d.service_id || null })).submit(TiketController.store(), {
        forceFormData: true,
        onSuccess: () => {
            f.reset();
            buka.value = false;
        },
    });
}
</script>

<template>
    <Head title="Support Ticket" />
    <div class="space-y-6 p-4 md:p-6">
        <PageHeader title="Support Ticket" description="Tanyakan kendala layanan Anda. Tim kami membalas di sini.">
            <Button @click="buka = true">Buat Tiket</Button>
        </PageHeader>
        <DataTable :kosong="!tiket.length" pesan-kosong="Belum ada tiket.">
            <thead>
                <tr>
                    <th>Tiket</th>
                    <th>Jenis</th>
                    <th>Layanan</th>
                    <th>Status</th>
                    <th>Diperbarui</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="t in tiket" :key="t.id">
                    <td>
                        <Link :href="tiketRoute.show(t.id)" class="font-semibold text-brand-700 hover:text-brand-600"
                            >#{{ t.id }} {{ t.judul }}</Link
                        >
                    </td>
                    <td>{{ t.jenis_label }}</td>
                    <td>{{ t.layanan ?? '-' }}</td>
                    <td><StatusBadge :status="t.status" :label="t.status_label" /></td>
                    <td>{{ tanggal(t.diperbarui.slice(0, 10)) }}</td>
                </tr>
            </tbody>
        </DataTable>

        <Dialog v-model:open="buka">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Buat tiket</DialogTitle>
                    <DialogDescription>Jelaskan kendala Anda selengkap mungkin.</DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="kirim">
                    <div class="grid gap-2">
                        <Label for="judul">Judul</Label>
                        <Input id="judul" v-model="f.judul" required maxlength="120" placeholder="Contoh: n8n tidak bisa dibuka" />
                        <InputError :message="f.errors.judul" />
                    </div>
                    <fieldset class="grid gap-2">
                        <legend class="text-sm font-medium text-ink">Jenis</legend>
                        <div class="grid grid-cols-2 gap-2">
                            <label
                                v-for="(nama, kunci) in jenis"
                                :key="kunci"
                                class="cursor-pointer rounded-md border border-input px-3 py-2 text-sm has-[:checked]:border-brand-700 has-[:checked]:bg-brand-50 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring"
                            >
                                <input v-model="f.jenis" type="radio" name="jenis" :value="kunci" class="sr-only" />
                                <span class="block font-semibold text-ink">{{ nama }}</span>
                                <span class="block text-xs text-muted-foreground">{{
                                    kunci === 'billing' ? 'Konfirmasi bayar, tagihan' : 'Kendala layanan, komplain, lainnya'
                                }}</span>
                            </label>
                        </div>
                        <InputError :message="f.errors.jenis" />
                    </fieldset>
                    <div v-if="layanan.length" class="grid gap-2">
                        <Label for="service_id">Layanan terkait (opsional)</Label>
                        <select
                            id="service_id"
                            v-model="f.service_id"
                            class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                        >
                            <option value="">Tidak terkait layanan</option>
                            <option v-for="l in layanan" :key="l.id" :value="l.id">{{ l.label }}</option>
                        </select>
                        <InputError :message="f.errors.service_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="isi">Pesan</Label>
                        <textarea
                            id="isi"
                            v-model="f.isi"
                            required
                            rows="6"
                            maxlength="5000"
                            class="rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                        />
                        <InputError :message="f.errors.isi" />
                    </div>
                    <PilihLampiran id="lampiran" v-model="f.lampiran" :galat="Object.entries(f.errors).filter(([k]) => k.startsWith('lampiran')).map(([, v]) => v).join(' ') || undefined" />
                    <DialogFooter>
                        <Button type="submit" :disabled="f.processing">Kirim</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
