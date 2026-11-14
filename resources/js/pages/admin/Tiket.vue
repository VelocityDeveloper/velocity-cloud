<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import DataTable from '@/components/cloud/DataTable.vue';
import FilterStatus from '@/components/cloud/FilterStatus.vue';
import PageHeader from '@/components/cloud/PageHeader.vue';
import StatusBadge from '@/components/cloud/StatusBadge.vue';
import { tanggal } from '@/lib/format';
import admin from '@/routes/admin';
import tiketRoute from '@/routes/tiket';
import type { Tiket } from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Admin · Tiket', href: admin.tiket.index() }] },
});
defineProps<{ status: string; pilihan: { value: string; label: string }[]; tiket: Tiket[] }>();
</script>

<template>
    <Head title="Admin · Tiket" />
    <div class="space-y-6 p-4 md:p-6">
        <PageHeader title="Support Ticket" description="Menunggu tim = belum dibalas. Balasan admin mengubah status jadi Dijawab.">
            <FilterStatus :nilai="status" :pilihan="pilihan" />
        </PageHeader>
        <DataTable :kosong="!tiket.length" pesan-kosong="Tidak ada tiket dengan status ini.">
            <thead>
                <tr>
                    <th>Tiket</th>
                    <th>Jenis</th>
                    <th>Pelanggan</th>
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
                    <td>
                        {{ t.pelanggan }}<span class="block text-xs text-muted-foreground">{{ t.email }}</span>
                    </td>
                    <td>{{ t.layanan ?? '-' }}</td>
                    <td><StatusBadge :status="t.status" :label="t.status_label" /></td>
                    <td>{{ tanggal(t.diperbarui.slice(0, 10)) }}</td>
                </tr>
            </tbody>
        </DataTable>
    </div>
</template>
