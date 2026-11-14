<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import DataTable from '@/components/cloud/DataTable.vue';
import PageHeader from '@/components/cloud/PageHeader.vue';
import { rupiah, tanggal } from '@/lib/format';
import admin from '@/routes/admin';

type Pengguna = {
    id: number;
    name: string;
    email: string;
    whatsapp: string | null;
    is_admin: boolean;
    created_at: string;
    services_count: number;
    total_lunas: number | null;
};
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin · Pengguna', href: admin.pengguna.index() },
        ],
    },
});
defineProps<{ pengguna: Pengguna[] }>();
</script>

<template>
    <Head title="Admin · Pengguna" />
    <div class="space-y-6 p-4 md:p-6">
        <PageHeader title="Pengguna" />
        <DataTable
            :kosong="!pengguna.length"
            pesan-kosong="Belum ada pengguna."
        >
            <thead>
                <tr>
                    <th>Nama</th>
                    <th>Email</th>
                    <th>WhatsApp</th>
                    <th class="text-right">Layanan</th>
                    <th class="text-right">Total lunas</th>
                    <th>Daftar</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="u in pengguna" :key="u.id">
                    <td class="font-semibold text-ink">
                        {{ u.name
                        }}<span
                            v-if="u.is_admin"
                            class="ml-2 text-xs font-normal text-brand-700"
                            >admin</span
                        >
                    </td>
                    <td>{{ u.email }}</td>
                    <td>{{ u.whatsapp ?? '-' }}</td>
                    <td class="text-right">{{ u.services_count }}</td>
                    <td class="text-right">
                        {{ rupiah(Number(u.total_lunas ?? 0)) }}
                    </td>
                    <td>{{ tanggal(u.created_at.slice(0, 10)) }}</td>
                </tr>
            </tbody>
        </DataTable>
    </div>
</template>
