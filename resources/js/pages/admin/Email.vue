<script setup lang="ts">
import { Head, Link, router } from "@inertiajs/vue3";
import { ref } from "vue";
import DataTable from "@/components/cloud/DataTable.vue";
import PageHeader from "@/components/cloud/PageHeader.vue";
import StatusBadge from "@/components/cloud/StatusBadge.vue";
import { Input } from "@/components/ui/input";
import admin from "@/routes/admin";

type Email = {
    id: number;
    waktu: string;
    ke: string;
    pengguna: { id: number; nama: string } | null;
    subjek: string;
    jenis: string;
    status: "terkirim" | "gagal";
};
defineOptions({
    layout: {
        breadcrumbs: [
            { title: "Admin · Support · Email", href: admin.email.index() },
        ],
    },
});
const props = defineProps<{ cari: string; email: Email[] }>();

const cari = ref(props.cari);
const saring = () =>
    router.get(
        admin.email.index().url,
        cari.value ? { cari: cari.value } : {},
        { preserveState: true, replace: true },
    );
</script>

<template>
    <Head title="Admin · Email" />
    <div class="space-y-6 p-4 md:p-6">
        <PageHeader
            title="Email"
            description="Riwayat email keluar dari support@velocitydatacenter.com: verifikasi, reset sandi, balasan tiket, pengingat, dan lainnya. Isi email tidak disimpan."
        />
        <form class="max-w-sm" @submit.prevent="saring">
            <Input
                v-model="cari"
                type="search"
                placeholder="Cari penerima atau subjek"
                aria-label="Cari penerima atau subjek"
            />
        </form>
        <DataTable
            :kosong="!email.length"
            pesan-kosong="Belum ada email keluar yang tercatat."
        >
            <thead>
                <tr>
                    <th>Waktu</th>
                    <th>Penerima</th>
                    <th>Subjek</th>
                    <th>Jenis</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="e in email" :key="e.id">
                    <td class="whitespace-nowrap">{{ e.waktu.slice(0, 16) }}</td>
                    <td>
                        <Link
                            v-if="e.pengguna"
                            :href="admin.pengguna.show(e.pengguna.id)"
                            class="font-semibold text-brand-700 hover:underline"
                            >{{ e.pengguna.nama }}</Link
                        >
                        <span class="block break-all text-xs text-muted-foreground">{{ e.ke }}</span>
                    </td>
                    <td>{{ e.subjek }}</td>
                    <td>{{ e.jenis }}</td>
                    <td>
                        <StatusBadge
                            :status="e.status === 'terkirim' ? 'aktif' : 'gagal'"
                            :label="e.status === 'terkirim' ? 'Terkirim' : 'Gagal'"
                        />
                    </td>
                </tr>
            </tbody>
        </DataTable>
    </div>
</template>
