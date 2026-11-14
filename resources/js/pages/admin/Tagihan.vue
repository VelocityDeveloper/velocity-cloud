<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import TagihanController from '@/actions/App/Http/Controllers/Admin/TagihanController';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { rupiah, tanggal } from '@/lib/format';
import admin from '@/routes/admin';
import type { Tagihan } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin · Tagihan', href: admin.tagihan.index() },
        ],
    },
});
defineProps<{
    status: string;
    tagihan: (Tagihan & { pelanggan: string; email: string })[];
}>();

const dipilih = ref<(Tagihan & { pelanggan: string }) | null>(null);
const pilihanStatus = [
    { value: 'belum_dibayar', label: 'Belum dibayar' },
    { value: 'lunas', label: 'Lunas' },
    { value: 'dibatalkan', label: 'Dibatalkan' },
];
</script>

<template>
    <Head title="Admin · Tagihan" />
    <div class="space-y-6 p-4 md:p-6">
        <PageHeader
            title="Tagihan"
            description="Tandai lunas setelah pembayaran dikonfirmasi. Layanan app langsung diprovisioning."
        >
            <FilterStatus :nilai="status" :pilihan="pilihanStatus" />
        </PageHeader>
        <DataTable
            :kosong="!tagihan.length"
            pesan-kosong="Tidak ada tagihan dengan status ini."
        >
            <thead>
                <tr>
                    <th>Nomor</th>
                    <th>Pelanggan</th>
                    <th>Layanan</th>
                    <th>Jatuh tempo</th>
                    <th>Status</th>
                    <th class="text-right">Jumlah</th>
                    <th><span class="sr-only">Aksi</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="t in tagihan" :key="t.id">
                    <td class="font-semibold text-ink">{{ t.nomor }}</td>
                    <td>
                        {{ t.pelanggan
                        }}<span class="block text-xs text-muted-foreground">{{
                            t.email
                        }}</span>
                    </td>
                    <td>{{ t.layanan }}</td>
                    <td>{{ tanggal(t.jatuh_tempo) }}</td>
                    <td>
                        <StatusBadge
                            :status="t.status"
                            :label="t.status_label"
                        />
                    </td>
                    <td class="text-right font-semibold text-ink">
                        {{ rupiah(t.total) }}
                    </td>
                    <td class="text-right">
                        <Button
                            v-if="t.status === 'belum_dibayar'"
                            size="sm"
                            variant="outline"
                            @click="dipilih = t"
                            >Tandai lunas</Button
                        >
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
                    <DialogTitle>Tandai {{ dipilih.nomor }} lunas</DialogTitle>
                    <DialogDescription
                        >{{ dipilih.pelanggan }} · {{ rupiah(dipilih.total) }}.
                        Pastikan dana sudah masuk.</DialogDescription
                    >
                </DialogHeader>
                <Form
                    v-bind="TagihanController.lunas.form(dipilih.id)"
                    class="space-y-4"
                    v-slot="{ errors, processing }"
                    @success="dipilih = null"
                >
                    <div class="grid gap-2">
                        <Label for="metode">Metode</Label>
                        <select
                            id="metode"
                            name="metode"
                            class="h-9 rounded-md border border-input bg-white px-3 text-sm"
                            required
                        >
                            <option value="transfer">Transfer bank</option>
                            <option value="qris">QRIS</option>
                            <option value="tunai">Tunai</option>
                            <option value="lainnya">Lainnya</option>
                        </select>
                        <InputError :message="errors.metode" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="referensi">Referensi (opsional)</Label>
                        <Input
                            id="referensi"
                            name="referensi"
                            maxlength="100"
                            placeholder="Nomor transaksi / catatan"
                        />
                    </div>
                    <DialogFooter
                        ><Button type="submit" :disabled="processing"
                            >Tandai Lunas</Button
                        ></DialogFooter
                    >
                </Form>
            </DialogContent>
        </Dialog>
    </div>
</template>
