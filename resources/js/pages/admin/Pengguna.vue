<script setup lang="ts">
import { Head, Link, router } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import DataTable from "@/components/cloud/DataTable.vue";
import PageHeader from "@/components/cloud/PageHeader.vue";
import { Button } from "@/components/ui/button";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { rupiah, tanggal } from "@/lib/format";
import admin from "@/routes/admin";

type Sumber = {
    sumber: string | null;
    medium: string | null;
    kampanye: string | null;
};
type Hit = Sumber & {
    waktu: string;
    jenis: "pv" | "ev" | "eg";
    path: string;
    judul: string | null;
    referrer: string | null;
    negara: string | null;
    kota: string | null;
    perangkat: string | null;
    browser: string | null;
    os: string | null;
    layar: string | null;
    bahasa: string | null;
    event: string | null;
    label: string | null;
    durasi_ms: number | null;
    scroll: number | null;
    awal_sesi: number | boolean;
};
type Kunjungan = { user_id: number; vid: string | null; hits: Hit[] };
type Pengguna = {
    id: number;
    name: string;
    email: string;
    whatsapp: string | null;
    is_admin: boolean;
    created_at: string;
    via: "Google" | "Direct";
    sumber: Sumber | null;
    analitik_vid: string | null;
    services_count: number;
    total_lunas: number | null;
};
defineOptions({
    layout: {
        breadcrumbs: [
            { title: "Admin · Pengguna", href: admin.pengguna.index() },
        ],
    },
});
const props = defineProps<{
    pengguna: Pengguna[];
    kunjungan?: Kunjungan;
}>();

const labelSumber = (s: Sumber | null) =>
    !s?.sumber
        ? "-"
        : s.sumber === "(direct)"
          ? "Langsung"
          : `${s.sumber} / ${s.medium ?? "-"}`;

const dipilih = ref<Pengguna | null>(null);
const memuat = ref(false);
const buka = (u: Pengguna) => {
    dipilih.value = u;
    memuat.value = true;
    router.reload({
        only: ["kunjungan"],
        data: { u: u.id },
        onFinish: () => (memuat.value = false),
    });
};
// data modal hanya dipakai bila milik pengguna yang sedang dibuka (hindari tampil data pengguna sebelumnya)
const data = computed(() =>
    props.kunjungan?.user_id === dipilih.value?.id ? props.kunjungan : null,
);
const pertama = computed(() => data.value?.hits.find((h) => h.jenis === "pv"));
const ringkas = computed(() => {
    const hits = data.value?.hits ?? [];
    return {
        sesi: hits.filter((h) => h.jenis === "pv" && h.awal_sesi).length,
        halaman: hits.filter((h) => h.jenis === "pv").length,
        aktifDetik: Math.round(
            hits.reduce((n, h) => n + (h.durasi_ms ?? 0), 0) / 1000,
        ),
    };
});
const NAMA_EVENT: Record<string, string> = {
    klik_pesan: "Klik Pesan",
    klik_wa: "Klik WhatsApp",
    klik_daftar: "Klik Daftar",
    klik_masuk: "Klik Masuk",
    tautan_keluar: "Tautan keluar",
};
const keterangan = (h: Hit) =>
    h.jenis === "pv"
        ? h.judul || h.path
        : h.jenis === "ev"
          ? `${NAMA_EVENT[h.event ?? ""] ?? h.event}${h.label ? ": " + h.label : ""}`
          : `Aktif ${Math.round((h.durasi_ms ?? 0) / 1000)} dtk, gulir ${h.scroll ?? 0}%`;
const jam = (w: string) => w.slice(0, 16);
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
                    <th class="text-right">ID</th>
                    <th>Nama</th>
                    <th>Email</th>
                    <th>WhatsApp</th>
                    <th class="text-right">Layanan</th>
                    <th class="text-right">Total lunas</th>
                    <th>Daftar</th>
                    <th>Via</th>
                    <th>Sumber</th>
                    <th><span class="sr-only">Aksi</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="u in pengguna" :key="u.id">
                    <td class="text-right text-muted-foreground tabular-nums">{{ u.id }}</td>
                    <td class="font-semibold text-ink">
                        {{ u.name
                        }}<span
                            v-if="u.is_admin"
                            class="ml-2 text-xs font-normal text-brand-700"
                            >admin</span
                        >
                    </td>
                    <td>{{ u.email }}</td>
                    <td>{{ u.whatsapp ?? "-" }}</td>
                    <td class="text-right">{{ u.services_count }}</td>
                    <td class="text-right">
                        {{ rupiah(Number(u.total_lunas ?? 0)) }}
                    </td>
                    <td>{{ tanggal(u.created_at.slice(0, 10)) }}</td>
                    <td>{{ u.via }}</td>
                    <td>
                        <button
                            v-if="u.analitik_vid"
                            type="button"
                            class="text-brand-700 underline-offset-2 hover:underline"
                            @click="buka(u)"
                        >
                            {{ labelSumber(u.sumber) }}
                        </button>
                        <span v-else>-</span>
                    </td>
                    <td class="text-right">
                        <Button size="sm" variant="outline" as-child>
                            <Link
                                :href="admin.pengguna.show(u.id)"
                                :aria-label="`Detail ${u.name}`"
                                >Detail</Link
                            >
                        </Button>
                    </td>
                </tr>
            </tbody>
        </DataTable>

        <Dialog
            :open="dipilih !== null"
            @update:open="(v) => !v && (dipilih = null)"
        >
            <DialogContent v-if="dipilih" class="sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Sumber trafik {{ dipilih.name }}</DialogTitle>
                    <DialogDescription>
                        {{ dipilih.email }} · daftar
                        {{ jam(dipilih.created_at.replace("T", " ")) }}
                    </DialogDescription>
                </DialogHeader>
                <p v-if="memuat && !data" class="text-sm text-muted-foreground">
                    Memuat…
                </p>
                <p
                    v-else-if="data && !data.hits.length"
                    class="text-sm text-muted-foreground"
                >
                    Belum ada kunjungan landing yang tercatat untuk pengunjung
                    ini.
                </p>
                <template v-else-if="data">
                    <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-muted-foreground">
                                Sumber / medium
                            </dt>
                            <dd class="font-semibold text-ink">
                                {{ labelSumber(pertama ?? null) }}
                                <span
                                    v-if="pertama?.kampanye"
                                    class="font-normal"
                                >
                                    · kampanye {{ pertama.kampanye }}</span
                                >
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Referrer</dt>
                            <dd class="break-all">
                                {{ pertama?.referrer ?? "-" }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Halaman pertama
                            </dt>
                            <dd class="break-all">
                                {{ pertama?.path ?? "-" }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Datang pertama
                            </dt>
                            <dd>{{ pertama ? jam(pertama.waktu) : "-" }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Perangkat</dt>
                            <dd>
                                {{
                                    [
                                        pertama?.perangkat,
                                        pertama?.browser,
                                        pertama?.os,
                                        pertama?.layar,
                                    ]
                                        .filter(Boolean)
                                        .join(" · ") || "-"
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Lokasi · bahasa
                            </dt>
                            <dd>
                                {{
                                    [
                                        pertama?.kota,
                                        pertama?.negara,
                                        pertama?.bahasa,
                                    ]
                                        .filter(Boolean)
                                        .join(" · ") || "-"
                                }}
                            </dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-muted-foreground">Ringkasan</dt>
                            <dd>
                                {{ ringkas.sesi }} sesi ·
                                {{ ringkas.halaman }} halaman · aktif
                                {{ ringkas.aktifDetik }} dtk
                            </dd>
                        </div>
                    </dl>
                    <div class="max-h-80 overflow-auto rounded border">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 bg-background">
                                <tr class="text-left text-muted-foreground">
                                    <th class="p-2 font-medium">Waktu</th>
                                    <th class="p-2 font-medium">Halaman</th>
                                    <th class="p-2 font-medium">Aktivitas</th>
                                    <th class="p-2 font-medium">Sumber</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(h, i) in data.hits"
                                    :key="i"
                                    class="border-t align-top"
                                >
                                    <td class="p-2 whitespace-nowrap">
                                        {{ jam(h.waktu) }}
                                    </td>
                                    <td class="p-2 break-all">{{ h.path }}</td>
                                    <td class="p-2">{{ keterangan(h) }}</td>
                                    <td class="p-2 break-all">
                                        <template
                                            v-if="
                                                h.jenis === 'pv' && h.awal_sesi
                                            "
                                        >
                                            {{ labelSumber(h) }}
                                            <span
                                                v-if="h.referrer"
                                                class="block text-xs text-muted-foreground"
                                                >{{ h.referrer }}</span
                                            >
                                        </template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </DialogContent>
        </Dialog>
    </div>
</template>
