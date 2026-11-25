<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import DataTable from "@/components/cloud/DataTable.vue";
import PageHeader from "@/components/cloud/PageHeader.vue";
import StatusBadge from "@/components/cloud/StatusBadge.vue";
import { rupiah, tanggal } from "@/lib/format";
import admin from "@/routes/admin";
import tiketRoute from "@/routes/tiket";

type Layanan = {
    id: number;
    label: string;
    paket: string;
    kategori_label: string;
    status: string;
    status_label: string;
    harga: number;
    jatuh_tempo: string | null;
    saldo_token: number | null;
};
type Tagihan = {
    id: number;
    nomor: string;
    layanan: string;
    total: number;
    status: string;
    status_label: string;
    periode: string;
    dibayar_pada: string | null;
    metode: string | null;
};
type Tiket = {
    id: number;
    judul: string;
    jenis_label: string;
    status: string;
    status_label: string;
    diperbarui: string;
};
const props = defineProps<{
    pengguna: {
        id: number;
        name: string;
        email: string;
        whatsapp: string | null;
        is_admin: boolean;
        saldo: number;
        via: string;
        daftar: string;
        total_lunas: number;
    };
    layanan: Layanan[];
    tagihan: Tagihan[];
    tiket: Tiket[];
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: "Admin · Pengguna", href: admin.pengguna.index() },
        ],
    },
});
const angka = (n: number) => n.toLocaleString("id-ID");
</script>

<template>
    <Head :title="`Admin · ${props.pengguna.name}`" />
    <div class="space-y-6 p-4 md:p-6">
        <PageHeader
            :title="pengguna.name"
            :description="`${pengguna.email}${pengguna.is_admin ? ' · admin' : ''}`"
        >
            <Link
                :href="admin.pengguna.index()"
                class="text-sm text-brand-700 hover:underline"
                >← Semua pengguna</Link
            >
        </PageHeader>

        <dl class="grid gap-4 rounded-lg border p-4 text-sm sm:grid-cols-3 lg:grid-cols-6">
            <div>
                <dt class="text-muted-foreground">WhatsApp</dt>
                <dd class="font-semibold text-ink">{{ pengguna.whatsapp ?? "-" }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Daftar</dt>
                <dd class="font-semibold text-ink">{{ tanggal(pengguna.daftar.slice(0, 10)) }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Via</dt>
                <dd class="font-semibold text-ink">{{ pengguna.via }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Layanan</dt>
                <dd class="font-semibold text-ink">{{ layanan.length }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Total lunas</dt>
                <dd class="font-semibold text-ink">{{ rupiah(Number(pengguna.total_lunas)) }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Saldo akun</dt>
                <dd class="font-semibold text-ink">{{ rupiah(Number(pengguna.saldo ?? 0)) }}</dd>
            </div>
        </dl>

        <section class="space-y-2">
            <h2 class="text-base font-semibold text-ink">Layanan</h2>
            <DataTable :kosong="!layanan.length" pesan-kosong="Belum ada layanan.">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Layanan</th>
                        <th>Paket</th>
                        <th>Status</th>
                        <th class="text-right">Harga</th>
                        <th>Jatuh tempo</th>
                        <th class="text-right">Saldo token</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in layanan" :key="s.id">
                        <td class="text-muted-foreground">{{ s.id }}</td>
                        <td class="font-semibold text-ink">{{ s.label }}</td>
                        <td>{{ s.paket }}<span class="block text-xs text-muted-foreground">{{ s.kategori_label }}</span></td>
                        <td><StatusBadge :status="s.status" :label="s.status_label" /></td>
                        <td class="text-right">{{ rupiah(s.harga) }}</td>
                        <td>{{ s.jatuh_tempo ? tanggal(s.jatuh_tempo) : "-" }}</td>
                        <td class="text-right">{{ s.saldo_token === null ? "-" : angka(s.saldo_token) }}</td>
                    </tr>
                </tbody>
            </DataTable>
        </section>

        <section class="space-y-2">
            <h2 class="text-base font-semibold text-ink">Tagihan</h2>
            <DataTable :kosong="!tagihan.length" pesan-kosong="Belum ada tagihan.">
                <thead>
                    <tr>
                        <th>Nomor</th>
                        <th>Layanan</th>
                        <th>Periode</th>
                        <th class="text-right">Total</th>
                        <th>Status</th>
                        <th>Dibayar</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="i in tagihan" :key="i.id">
                        <td class="font-mono text-xs">{{ i.nomor }}</td>
                        <td>{{ i.layanan }}</td>
                        <td>{{ i.periode }}</td>
                        <td class="text-right">{{ rupiah(i.total) }}</td>
                        <td><StatusBadge :status="i.status" :label="i.status_label" /></td>
                        <td>
                            {{ i.dibayar_pada ? i.dibayar_pada.slice(0, 16) : "-"
                            }}<span v-if="i.metode" class="block text-xs text-muted-foreground">{{ i.metode }}</span>
                        </td>
                    </tr>
                </tbody>
            </DataTable>
        </section>

        <section class="space-y-2">
            <h2 class="text-base font-semibold text-ink">Tiket</h2>
            <DataTable :kosong="!tiket.length" pesan-kosong="Belum ada tiket.">
                <thead>
                    <tr>
                        <th>Judul</th>
                        <th>Jenis</th>
                        <th>Status</th>
                        <th>Diperbarui</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="t in tiket" :key="t.id">
                        <td>
                            <Link :href="tiketRoute.show(t.id)" class="font-semibold text-brand-700 hover:underline">{{ t.judul }}</Link>
                        </td>
                        <td>{{ t.jenis_label }}</td>
                        <td><StatusBadge :status="t.status" :label="t.status_label" /></td>
                        <td>{{ t.diperbarui.slice(0, 16) }}</td>
                    </tr>
                </tbody>
            </DataTable>
        </section>
    </div>
</template>
