<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import DataTable from '@/components/cloud/DataTable.vue';
import PageHeader from '@/components/cloud/PageHeader.vue';
import StatusBadge from '@/components/cloud/StatusBadge.vue';
import { rupiah, tanggal } from '@/lib/format';
import { dashboard } from '@/routes';
import admin from '@/routes/admin';
import type { Layanan, Tagihan } from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard Admin', href: dashboard() }] },
});
defineProps<{
    ringkasan: {
        pelanggan: number;
        pelanggan_baru_7_hari: number;
        layanan_per_status: { value: string; label: string; n: number }[];
        tagihan_belum_dibayar: { jumlah: number; nilai: number };
        omzet_lunas_bulan_ini: number;
        omzet_lunas_bulan_lalu: number;
        paket_dijual: string;
    };
    perlu_tindakan: (Layanan & { pelanggan: string })[];
    tagihan: (Tagihan & { pelanggan: string })[];
}>();
</script>

<template>
    <Head title="Dashboard Admin" />
    <div class="space-y-8 p-4 md:p-6">
        <PageHeader
            title="Dashboard Admin"
            description="Ringkasan bisnis dan pekerjaan yang menunggu admin."
        />

        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-lg border p-5">
                <dt class="text-sm text-muted-foreground">Pelanggan</dt>
                <dd class="mt-1 text-2xl font-bold text-ink">
                    {{ ringkasan.pelanggan }}
                </dd>
                <dd class="text-sm text-muted-foreground">
                    +{{ ringkasan.pelanggan_baru_7_hari }} dalam 7 hari
                </dd>
            </div>
            <div class="rounded-lg border p-5">
                <dt class="text-sm text-muted-foreground">Omzet bulan ini</dt>
                <dd class="mt-1 text-2xl font-bold text-ink">
                    {{ rupiah(ringkasan.omzet_lunas_bulan_ini) }}
                </dd>
                <dd class="text-sm text-muted-foreground">
                    Bulan lalu {{ rupiah(ringkasan.omzet_lunas_bulan_lalu) }}
                </dd>
            </div>
            <div class="rounded-lg border p-5">
                <dt class="text-sm text-muted-foreground">Tagihan belum dibayar</dt>
                <dd class="mt-1 text-2xl font-bold text-ink">
                    {{ ringkasan.tagihan_belum_dibayar.jumlah }}
                </dd>
                <dd class="text-sm text-muted-foreground">
                    {{ rupiah(ringkasan.tagihan_belum_dibayar.nilai) }}
                </dd>
            </div>
            <div class="rounded-lg border p-5">
                <dt class="text-sm text-muted-foreground">Paket dijual</dt>
                <dd class="mt-1 text-2xl font-bold text-ink">
                    {{ ringkasan.paket_dijual }}
                </dd>
            </div>
        </dl>

        <section>
            <h2 class="mb-3 text-lg font-semibold text-ink">Layanan per status</h2>
            <ul class="flex flex-wrap gap-3">
                <li v-for="s in ringkasan.layanan_per_status" :key="s.value">
                    <Link
                        :href="admin.layanan.index({ query: { status: s.value } })"
                        class="flex items-center gap-2 rounded-lg border px-4 py-2 text-sm hover:bg-muted"
                    >
                        <StatusBadge :status="s.value" :label="s.label" />
                        <strong class="text-ink">{{ s.n }}</strong>
                    </Link>
                </li>
            </ul>
        </section>

        <section>
            <h2 class="mb-3 text-lg font-semibold text-ink">
                Layanan perlu tindakan
            </h2>
            <DataTable
                :kosong="!perlu_tindakan.length"
                pesan-kosong="Tidak ada layanan yang sedang disiapkan atau gagal."
            >
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Pelanggan</th>
                        <th>Paket</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="l in perlu_tindakan" :key="l.id">
                        <td>
                            <Link
                                :href="admin.layanan.index({ query: { status: l.status } })"
                                class="font-semibold text-brand-700 hover:text-brand-600"
                                >{{ l.label }}</Link
                            >
                        </td>
                        <td>{{ l.pelanggan }}</td>
                        <td>{{ l.kategori_label }} · {{ l.paket }}</td>
                        <td>
                            <StatusBadge :status="l.status" :label="l.status_label" />
                        </td>
                    </tr>
                </tbody>
            </DataTable>
        </section>

        <section>
            <div class="mb-3 flex items-center justify-between gap-2">
                <h2 class="text-lg font-semibold text-ink">
                    Tagihan belum dibayar
                </h2>
                <Link
                    :href="admin.tagihan.index()"
                    class="text-sm font-semibold text-brand-700 hover:text-brand-600"
                    >Semua tagihan</Link
                >
            </div>
            <DataTable
                :kosong="!tagihan.length"
                pesan-kosong="Tidak ada tagihan yang menunggu pembayaran."
            >
                <thead>
                    <tr>
                        <th>Nomor</th>
                        <th>Pelanggan</th>
                        <th>Layanan</th>
                        <th>Jatuh tempo</th>
                        <th class="text-right">Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="t in tagihan" :key="t.id">
                        <td class="font-semibold text-ink">{{ t.nomor }}</td>
                        <td>{{ t.pelanggan }}</td>
                        <td>{{ t.layanan }}</td>
                        <td>{{ tanggal(t.jatuh_tempo) }}</td>
                        <td class="text-right">{{ rupiah(t.total) }}</td>
                    </tr>
                </tbody>
            </DataTable>
        </section>
    </div>
</template>
