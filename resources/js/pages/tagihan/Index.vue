<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import DataTable from '@/components/cloud/DataTable.vue';
import PageHeader from '@/components/cloud/PageHeader.vue';
import StatusBadge from '@/components/cloud/StatusBadge.vue';
import { rupiah, tanggal } from '@/lib/format';
import tagihanRoute from '@/routes/tagihan';
import type { Tagihan } from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Tagihan', href: tagihanRoute.index() }] },
});
defineProps<{ tagihan: Tagihan[]; saldo: number }>();
</script>

<template>
    <Head title="Tagihan" />
    <div class="space-y-6 p-4 md:p-6">
        <PageHeader
            title="Tagihan"
            :description="`Saldo ${rupiah(saldo)} dari kode unik pembayaran. Saldo tidak bisa ditarik.`"
        />
        <DataTable :kosong="!tagihan.length" pesan-kosong="Belum ada tagihan.">
            <thead>
                <tr>
                    <th>Nomor</th>
                    <th>Layanan</th>
                    <th>Jatuh tempo</th>
                    <th>Status</th>
                    <th class="text-right">Jumlah</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="t in tagihan" :key="t.id">
                    <td>
                        <Link
                            :href="tagihanRoute.show(t.id)"
                            class="font-semibold text-brand-700 hover:text-brand-600"
                            >{{ t.nomor }}</Link
                        >
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
                </tr>
            </tbody>
        </DataTable>
    </div>
</template>
