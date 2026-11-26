<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import {
    AppWindow,
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
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
import { computed, ref } from "vue";
import DataTable from "@/components/cloud/DataTable.vue";
import PageHeader from "@/components/cloud/PageHeader.vue";
import PromoBanner from "@/components/cloud/PromoBanner.vue";
import type { PromoAktif } from "@/components/cloud/PromoBanner.vue";
import { rupiah } from "@/lib/format";
import katalog from "@/routes/katalog";
import type { Paket } from "@/types";

defineOptions({
    layout: {
        breadcrumbs: [{ title: "Pesan Layanan", href: katalog.index() }],
    },
});
const props = defineProps<{ paket: Paket[]; kategori: Paket["kategori"]; promo: PromoAktif[] }>();

// Kategori dipilih lewat sub-menu sidebar (/katalog/<url>), bukan tab di halaman.
const tab = computed(() => props.kategori);
const info: Record<Paket["kategori"], { judul: string; sub: string }> = {
    app: { judul: "App 1-Klik", sub: "Harga per bulan. App berjalan di container terisolasi milik Anda sendiri." },
    vps: { judul: "Cloud Server", sub: "Harga per bulan. Server virtual dengan akses root penuh." },
    managed: { judul: "Managed Server", sub: "Harga per bulan. Server dikelola tim Velocity Developer." },
    hosting: { judul: "Shared Hosting", sub: "Harga per tahun. Hosting dengan panel DirectAdmin, akun dibuat otomatis setelah lunas." },
    domain: { judul: "Domain", sub: "Harga sekali bayar per periode registrasi." }, // tak punya tab katalog, dipesan di /domain
    ai: { judul: "Token AI", sub: "Harga per 1 juta token. Saldo dibeli seharga output dan tanpa masa berlaku; token input & cache memotong saldo lebih sedikit sesuai harganya." },
};
// Token AI bisa diurut per harga (klik judul kolom): pertama termurah, klik lagi termahal.
type KolomHarga = "input" | "cache" | "output";
const urut = ref<{ kolom: KolomHarga; turun: boolean } | null>(null);
const urutkan = (kolom: KolomHarga) =>
    (urut.value = urut.value?.kolom === kolom ? { kolom, turun: !urut.value.turun } : { kolom, turun: false });
// harga_input/harga_cache kosong = seharga output (paket lama)
const hargaAi = (p: Paket, kolom: KolomHarga) =>
    kolom === "output"
        ? Number(p.harga_promo ?? p.harga)
        : kolom === "input"
          ? Number(p.spek?.harga_input ?? p.harga)
          : Number(p.spek?.harga_cache ?? p.spek?.harga_input ?? p.harga);
// ikon ⇅ = kolom bisa diurut; panah ↑/↓ = kolom yang sedang dipakai mengurutkan
const ikonUrut = (k: KolomHarga) => (urut.value?.kolom === k ? (urut.value.turun ? ArrowDown : ArrowUp) : ArrowUpDown);
const ariaUrut = (k: KolomHarga) => (urut.value?.kolom === k ? (urut.value.turun ? "descending" : "ascending") : "none");
const daftar = computed(() => {
    const isi = props.paket.filter((p) => p.kategori === tab.value);
    const u = urut.value;
    if (tab.value !== "ai" || !u) return isi;
    return isi.sort((a, b) => (hargaAi(a, u.kolom) - hargaAi(b, u.kolom)) * (u.turun ? -1 : 1));
});
</script>

<template>
    <Head :title="`Pesan ${info[tab].judul}`" />
    <div class="space-y-6 p-4 md:p-6">
        <PageHeader :title="info[tab].judul" :description="info[tab].sub" />
        <PromoBanner :promo="promo" :kategori="tab" />

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
                    <th class="text-right" :aria-sort="ariaUrut('input')">
                        <button type="button" class="inline-flex items-center gap-1 font-semibold hover:text-brand-700" title="Urutkan menurut harga input" @click="urutkan('input')">Input<component :is="ikonUrut('input')" class="size-3.5" :class="urut?.kolom === 'input' ? 'text-brand-700' : 'text-muted-foreground'" aria-hidden="true" /></button>
                    </th>
                    <th class="text-right" :aria-sort="ariaUrut('cache')">
                        <button type="button" class="inline-flex items-center gap-1 font-semibold hover:text-brand-700" title="Urutkan menurut harga cache" @click="urutkan('cache')">Cache<component :is="ikonUrut('cache')" class="size-3.5" :class="urut?.kolom === 'cache' ? 'text-brand-700' : 'text-muted-foreground'" aria-hidden="true" /></button>
                    </th>
                    <th class="text-right" :aria-sort="ariaUrut('output')">
                        <button type="button" class="inline-flex items-center gap-1.5 font-semibold hover:text-brand-700" title="Urutkan menurut harga output" @click="urutkan('output')"
                            ><Wallet
                                class="size-4 text-brand-700"
                                aria-hidden="true"
                            />Output<component :is="ikonUrut('output')" class="-ml-0.5 size-3.5" :class="urut?.kolom === 'output' ? 'text-brand-700' : 'text-muted-foreground'" aria-hidden="true" /></button
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
                            v-if="p.spek?.populer"
                            class="ml-2 inline-block rounded-full bg-brand-50 px-2 py-0.5 align-middle text-[11px] font-semibold text-brand-700"
                            >Populer</span
                        ><span
                            class="block font-mono text-xs font-normal text-muted-foreground"
                            >{{ p.spek?.model }}</span
                        >
                    </td>
                    <td
                        class="min-w-72 !whitespace-normal text-muted-foreground"
                    >
                        {{ p.ringkas }}
                    </td>
                    <td class="text-right text-muted-foreground tabular-nums">
                        {{ rupiah(hargaAi(p, "input")) }}
                    </td>
                    <td class="text-right text-muted-foreground tabular-nums">
                        {{ rupiah(hargaAi(p, "cache")) }}
                    </td>
                    <td class="text-right font-semibold text-ink tabular-nums">
                        <s v-if="p.harga_promo != null" class="block text-xs font-normal text-muted-foreground">{{ rupiah(p.harga) }}</s>
                        <span :class="p.harga_promo != null && 'text-brand-700'">{{ rupiah(p.harga_promo ?? p.harga) }}</span>
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
                            />{{ tab === 'hosting' ? 'Harga/thn' : 'Harga/bln' }}</span
                        >
                    </th>
                    <th v-if="tab !== 'hosting'">
                        <span class="inline-flex items-center gap-1.5"
                            ><Cpu
                                class="size-4 text-brand-700"
                                aria-hidden="true"
                            />CPU</span
                        >
                    </th>
                    <th v-if="tab !== 'hosting'">
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
                    <th v-if="tab === 'vps' || tab === 'hosting'">
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
                    <td v-if="tab !== 'hosting'">{{ p.spek?.cpu }}</td>
                    <td v-if="tab !== 'hosting'">{{ p.spek?.ram }}</td>
                    <td>{{ p.spek?.disk }}</td>
                    <td v-if="tab === 'vps' || tab === 'hosting'">{{ p.spek?.bandwidth }}</td>
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
                            :href="`https://wa.me/${$page.props.whatsapp}?text=${encodeURIComponent('Halo, saya tertarik paket ' + p.nama + ' -vc')}`"
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
