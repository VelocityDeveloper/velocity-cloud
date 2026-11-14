<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import {
    AppWindow,
    Bot,
    Cpu,
    FileText,
    Gauge,
    HardDrive,
    MemoryStick,
    MessageCircle,
    Server,
    ShoppingCart,
    Wallet,
} from "@lucide/vue";
import { computed } from "vue";
import DataTable from "@/components/cloud/DataTable.vue";
import PageHeader from "@/components/cloud/PageHeader.vue";
import { rupiah } from "@/lib/format";
import katalog from "@/routes/katalog";
import type { Paket } from "@/types";

defineOptions({
    layout: {
        breadcrumbs: [{ title: "Pesan Layanan", href: katalog.index() }],
    },
});
const props = defineProps<{ paket: Paket[]; kategori: Paket["kategori"] }>();

// Kategori dipilih lewat sub-menu sidebar (/katalog/<url>), bukan tab di halaman.
const tab = computed(() => props.kategori);
const info: Record<Paket["kategori"], { judul: string; sub: string }> = {
    app: { judul: "App 1-Klik", sub: "Harga per bulan. App berjalan di container terisolasi milik Anda sendiri." },
    vps: { judul: "Cloud Server", sub: "Harga per bulan. Server virtual dengan akses root penuh." },
    managed: { judul: "Managed Server", sub: "Harga per bulan. Server dikelola tim Velocity Developer." },
    ai: { judul: "Token AI", sub: "Token AI prabayar: bayar sekali, saldo tanpa masa berlaku." },
};
const daftar = computed(() =>
    props.paket.filter((p) => p.kategori === tab.value),
);
</script>

<template>
    <Head :title="`Pesan ${info[tab].judul}`" />
    <div class="space-y-6 p-4 md:p-6">
        <PageHeader :title="info[tab].judul" :description="info[tab].sub" />

        <DataTable v-if="tab === 'app'">
            <thead>
                <tr>
                    <th>
                        <span class="inline-flex items-center gap-1.5"
                            ><AppWindow
                                class="size-4 text-brand-700"
                                aria-hidden="true"
                            />Aplikasi</span
                        >
                    </th>
                    <th>
                        <span class="inline-flex items-center gap-1.5"
                            ><FileText
                                class="size-4 text-brand-700"
                                aria-hidden="true"
                            />Keterangan</span
                        >
                    </th>
                    <th class="text-right">
                        <span class="inline-flex items-center gap-1.5"
                            ><Wallet
                                class="size-4 text-brand-700"
                                aria-hidden="true"
                            />Harga/bln</span
                        >
                    </th>
                    <th><span class="sr-only">Aksi</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="p in daftar" :key="p.id">
                    <td class="font-semibold text-ink">
                        {{ p.nama
                        }}<span
                            class="block text-xs font-normal text-muted-foreground"
                            >{{ p.spek?.kategori_app }}</span
                        >
                    </td>
                    <td
                        class="min-w-72 !whitespace-normal text-muted-foreground"
                    >
                        {{ p.ringkas }}
                    </td>
                    <td class="text-right font-semibold text-ink">
                        {{ rupiah(p.harga) }}
                    </td>
                    <td class="text-right">
                        <Link
                            v-if="p.bisa_dipesan"
                            :href="katalog.show(p.slug)"
                            class="inline-flex items-center gap-1.5 font-semibold text-brand-700 hover:text-brand-600"
                            ><ShoppingCart
                                class="size-4"
                                aria-hidden="true"
                            />Pesan</Link
                        >
                        <span v-else class="text-xs text-muted-foreground">{{
                            p.habis ? "Stok habis" : "Segera hadir"
                        }}</span>
                    </td>
                </tr>
            </tbody>
        </DataTable>

        <DataTable v-else-if="tab === 'ai'">
            <thead>
                <tr>
                    <th>
                        <span class="inline-flex items-center gap-1.5"
                            ><Bot
                                class="size-4 text-brand-700"
                                aria-hidden="true"
                            />Model</span
                        >
                    </th>
                    <th>
                        <span class="inline-flex items-center gap-1.5"
                            ><FileText
                                class="size-4 text-brand-700"
                                aria-hidden="true"
                            />Keterangan</span
                        >
                    </th>
                    <th class="text-right">
                        <span class="inline-flex items-center gap-1.5"
                            ><Wallet
                                class="size-4 text-brand-700"
                                aria-hidden="true"
                            />Harga/1 jt token</span
                        >
                    </th>
                    <th><span class="sr-only">Aksi</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="p in daftar" :key="p.id">
                    <td class="font-semibold text-ink">
                        {{ p.nama
                        }}<span
                            class="block font-mono text-xs font-normal text-muted-foreground"
                            >{{ p.spek?.model }}</span
                        >
                    </td>
                    <td
                        class="min-w-72 !whitespace-normal text-muted-foreground"
                    >
                        {{ p.ringkas }}
                    </td>
                    <td class="text-right font-semibold text-ink">
                        {{ rupiah(p.harga) }}
                    </td>
                    <td class="text-right">
                        <Link
                            v-if="p.bisa_dipesan"
                            :href="katalog.show(p.slug)"
                            class="inline-flex items-center gap-1.5 font-semibold text-brand-700 hover:text-brand-600"
                            ><ShoppingCart
                                class="size-4"
                                aria-hidden="true"
                            />Beli</Link
                        >
                        <span v-else class="text-xs text-muted-foreground">{{
                            p.habis ? "Stok habis" : "Segera hadir"
                        }}</span>
                    </td>
                </tr>
            </tbody>
        </DataTable>

        <DataTable v-else>
            <thead>
                <tr>
                    <th>
                        <span class="inline-flex items-center gap-1.5"
                            ><Server
                                class="size-4 text-brand-700"
                                aria-hidden="true"
                            />Paket</span
                        >
                    </th>
                    <th class="text-right">
                        <span class="inline-flex items-center gap-1.5"
                            ><Wallet
                                class="size-4 text-brand-700"
                                aria-hidden="true"
                            />Harga/bln</span
                        >
                    </th>
                    <th>
                        <span class="inline-flex items-center gap-1.5"
                            ><Cpu
                                class="size-4 text-brand-700"
                                aria-hidden="true"
                            />CPU</span
                        >
                    </th>
                    <th>
                        <span class="inline-flex items-center gap-1.5"
                            ><MemoryStick
                                class="size-4 text-brand-700"
                                aria-hidden="true"
                            />RAM</span
                        >
                    </th>
                    <th>
                        <span class="inline-flex items-center gap-1.5"
                            ><HardDrive
                                class="size-4 text-brand-700"
                                aria-hidden="true"
                            />Storage</span
                        >
                    </th>
                    <th v-if="tab === 'vps'">
                        <span class="inline-flex items-center gap-1.5"
                            ><Gauge
                                class="size-4 text-brand-700"
                                aria-hidden="true"
                            />Bandwidth</span
                        >
                    </th>
                    <th><span class="sr-only">Aksi</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="p in daftar" :key="p.id">
                    <td class="font-semibold text-ink">{{ p.nama }}</td>
                    <td class="text-right font-semibold text-ink">
                        {{ rupiah(p.harga) }}
                    </td>
                    <td>{{ p.spek?.cpu }}</td>
                    <td>{{ p.spek?.ram }}</td>
                    <td>{{ p.spek?.disk }}</td>
                    <td v-if="tab === 'vps'">{{ p.spek?.bandwidth }}</td>
                    <td class="text-right">
                        <Link
                            v-if="p.bisa_dipesan"
                            :href="katalog.show(p.slug)"
                            class="inline-flex items-center gap-1.5 font-semibold text-brand-700 hover:text-brand-600"
                            ><ShoppingCart
                                class="size-4"
                                aria-hidden="true"
                            />Pesan</Link
                        >
                        <span
                            v-else-if="p.habis"
                            class="text-xs text-muted-foreground"
                            >Stok habis</span
                        >
                        <a
                            v-else
                            :href="`https://wa.me/${$page.props.whatsapp}?text=${encodeURIComponent('Halo, saya tertarik paket ' + p.nama)}`"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex items-center gap-1.5 font-semibold text-brand-700 hover:text-brand-600"
                            ><MessageCircle
                                class="size-4"
                                aria-hidden="true"
                            />Konsultasi</a
                        >
                    </td>
                </tr>
            </tbody>
        </DataTable>
    </div>
</template>
