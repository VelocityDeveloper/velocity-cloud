<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Activity, CalendarClock, Globe, Package, Plus, Settings2, Tag, Wallet } from '@lucide/vue';
import DataTable from '@/components/cloud/DataTable.vue';
import PageHeader from '@/components/cloud/PageHeader.vue';
import StatusBadge from '@/components/cloud/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { rupiah, tanggal } from '@/lib/format';
import katalog from '@/routes/katalog';
import layananRoute from '@/routes/layanan';
import type { Layanan } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Layanan Saya', href: layananRoute.index() }],
    },
});
defineProps<{ layanan: Layanan[] }>();
</script>

<template>
    <Head title="Layanan Saya" />
    <div class="space-y-6 p-4 md:p-6">
        <PageHeader title="Layanan Saya">
            <Button as-child
                ><Link :href="katalog.index()"
                    ><Plus aria-hidden="true" />Pesan Layanan</Link
                ></Button
            >
        </PageHeader>
        <DataTable :kosong="!layanan.length" pesan-kosong="Belum ada layanan.">
            <thead>
                <tr>
                    <th>
                        <span class="inline-flex items-center gap-1.5"
                            ><Tag class="size-4 text-brand-700" aria-hidden="true" />Nama</span
                        >
                    </th>
                    <th>
                        <span class="inline-flex items-center gap-1.5"
                            ><Package class="size-4 text-brand-700" aria-hidden="true" />Paket</span
                        >
                    </th>
                    <th>
                        <span class="inline-flex items-center gap-1.5"
                            ><Globe class="size-4 text-brand-700" aria-hidden="true" />Alamat</span
                        >
                    </th>
                    <th>
                        <span class="inline-flex items-center gap-1.5"
                            ><Activity class="size-4 text-brand-700" aria-hidden="true" />Status</span
                        >
                    </th>
                    <th>
                        <span class="inline-flex items-center gap-1.5"
                            ><CalendarClock class="size-4 text-brand-700" aria-hidden="true" />Jatuh tempo</span
                        >
                    </th>
                    <th class="text-right">
                        <span class="inline-flex items-center gap-1.5"
                            ><Wallet class="size-4 text-brand-700" aria-hidden="true" />Harga</span
                        >
                    </th>
                    <th class="text-right"><span class="sr-only">Aksi</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="l in layanan" :key="l.id">
                    <td>
                        <Link
                            :href="layananRoute.show(l.id)"
                            class="font-semibold text-brand-700 hover:text-brand-600"
                            >{{ l.label }}</Link
                        >
                    </td>
                    <td>{{ l.kategori_label }} · {{ l.paket }}</td>
                    <td>
                        <a
                            v-if="l.url"
                            :href="l.url"
                            target="_blank"
                            rel="noopener"
                            class="text-brand-700 hover:text-brand-600"
                            >{{ l.url.replace('https://', '') }}</a
                        ><span v-else class="text-muted-foreground">-</span>
                    </td>
                    <td>
                        <StatusBadge
                            :status="l.status"
                            :label="l.status_label"
                        />
                    </td>
                    <td>{{ tanggal(l.jatuh_tempo) }}</td>
                    <td class="text-right">{{ rupiah(l.harga) }}<span class="text-muted-foreground">{{ l.kategori === 'ai' ? '/jt token' : l.kategori === 'hosting' ? '/thn' : l.kategori === 'domain' ? '' : '/bln' }}</span></td>
                    <td class="text-right">
                        <Button size="sm" variant="outline" as-child>
                            <Link :href="layananRoute.show(l.id)"><Settings2 aria-hidden="true" />Kelola</Link>
                        </Button>
                    </td>
                </tr>
            </tbody>
        </DataTable>
    </div>
</template>
