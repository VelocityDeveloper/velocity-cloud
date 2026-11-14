<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import { QrCode } from "@lucide/vue";
import { computed, ref } from "vue";
import PageHeader from "@/components/cloud/PageHeader.vue";
import StatusBadge from "@/components/cloud/StatusBadge.vue";
import TombolSalin from "@/components/cloud/TombolSalin.vue";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { rupiah, tanggal } from "@/lib/format";
import layananRoute from "@/routes/layanan";
import tagihanRoute from "@/routes/tagihan";
import tiketRoute from "@/routes/tiket";
import type { Tagihan } from "@/types";

type MetodeBayar = {
    id: number;
    jenis: string;
    nama: string;
    nomor: string | null;
    atas_nama: string | null;
    instruksi: string | null;
    gambar_url: string | null;
};
const props = defineProps<{
    tagihan: Tagihan;
    layanan_id: number;
    metode_bayar: MetodeBayar[];
}>();
defineOptions({
    layout: {
        breadcrumbs: [
            { title: "Tagihan", href: tagihanRoute.index() },
            { title: "Detail", href: "#" },
        ],
    },
});

const qris = ref<MetodeBayar | null>(null);
// shortcut: pembayaran online (Duitku) belum dipasang; bayar manual ke metode di /admin/pembayaran, konfirmasi via WhatsApp, admin menandai lunas.
// Konfirmasi bayar lewat tiket billing (pengganti WhatsApp); form tiket terisi otomatis.
const konfirmasi = computed(() => {
    const q = new URLSearchParams({
        jenis: "billing",
        judul: `Konfirmasi pembayaran ${props.tagihan.nomor}`,
        isi: `Saya sudah membayar tagihan ${props.tagihan.nomor} sebesar ${rupiah(props.tagihan.total)} pada tanggal ... melalui ...`,
        layanan: String(props.layanan_id),
    });
    return `${tiketRoute.index().url}?${q}`;
});
</script>

<template>
    <Head :title="tagihan.nomor" />
    <div
        class="grid gap-6 p-4 md:p-6"
        :class="
            tagihan.status === 'belum_dibayar'
                ? 'lg:grid-cols-[minmax(0,42rem)_22rem] lg:items-start'
                : 'max-w-2xl'
        "
    >
        <div class="space-y-6">
            <PageHeader :title="tagihan.nomor" :description="tagihan.layanan">
                <StatusBadge
                    :status="tagihan.status"
                    :label="tagihan.status_label"
                />
            </PageHeader>

            <dl
                class="divide-y divide-border rounded-lg border border-border text-sm tabular-nums"
            >
                <div class="flex justify-between px-5 py-3">
                    <dt class="text-muted-foreground">Periode</dt>
                    <dd>{{ tagihan.periode }}</dd>
                </div>
                <div class="flex justify-between px-5 py-3">
                    <dt class="text-muted-foreground">Jatuh tempo</dt>
                    <dd>{{ tanggal(tagihan.jatuh_tempo) }}</dd>
                </div>
                <div
                    v-if="tagihan.dibayar_pada"
                    class="flex justify-between px-5 py-3"
                >
                    <dt class="text-muted-foreground">Dibayar</dt>
                    <dd>
                        {{ tanggal(tagihan.dibayar_pada.slice(0, 10)) }} ·
                        {{ tagihan.metode }}
                    </dd>
                </div>
                <template v-if="tagihan.kode_unik">
                    <div class="flex justify-between px-5 py-3">
                        <dt class="text-muted-foreground">Subtotal</dt>
                        <dd>{{ rupiah(tagihan.jumlah) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 px-5 py-3">
                        <dt class="text-muted-foreground">
                            Kode unik
                            <span class="block text-xs"
                                >Masuk ke saldo Anda setelah lunas</span
                            >
                        </dt>
                        <dd>{{ rupiah(tagihan.kode_unik) }}</dd>
                    </div>
                </template>
                <div class="flex justify-between px-5 py-4">
                    <dt class="font-semibold text-ink">Total</dt>
                    <dd class="font-heading text-xl font-bold text-ink">
                        {{ rupiah(tagihan.total) }}
                    </dd>
                </div>
            </dl>

            <Link
                :href="layananRoute.show(layanan_id)"
                class="inline-block text-sm font-semibold text-brand-700 hover:text-brand-600"
                >Lihat layanan</Link
            >
        </div>

        <section
            v-if="tagihan.status === 'belum_dibayar'"
            class="h-fit rounded-lg border border-border bg-brand-50 p-5 lg:sticky lg:top-6"
        >
            <h2 class="font-semibold text-ink">Cara membayar</h2>
            <template v-if="metode_bayar.length">
                <div
                    class="mt-3 flex items-center justify-between gap-3 rounded-md border border-border bg-background px-4 py-3"
                >
                    <div>
                        <p class="text-xs text-muted-foreground">Bayar tepat</p>
                        <p
                            class="font-heading text-xl font-bold text-ink tabular-nums"
                        >
                            {{ rupiah(tagihan.total) }}
                        </p>
                    </div>
                    <TombolSalin
                        :teks="String(tagihan.total)"
                        label="nominal"
                    />
                </div>
                <ul class="mt-3 space-y-2">
                    <li
                        v-for="m in metode_bayar"
                        :key="m.id"
                        class="rounded-md border border-border bg-background px-4 py-3 text-sm"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold text-ink">
                                    {{ m.nama }}
                                </p>
                                <p
                                    v-if="m.nomor && !m.gambar_url"
                                    class="font-mono text-ink break-all"
                                >
                                    {{ m.nomor }}
                                </p>
                                <p
                                    v-if="m.atas_nama"
                                    class="text-xs text-muted-foreground"
                                >
                                    a.n. {{ m.atas_nama }}
                                </p>
                            </div>
                            <Button
                                v-if="m.gambar_url"
                                size="sm"
                                variant="outline"
                                @click="qris = m"
                            >
                                <QrCode class="size-4" aria-hidden="true" />
                                Tampilkan QR
                            </Button>
                            <TombolSalin
                                v-else-if="m.nomor"
                                :teks="m.nomor"
                                :label="`nomor ${m.nama}`"
                            />
                        </div>
                        <p
                            v-if="m.instruksi"
                            class="mt-1 text-xs whitespace-pre-line text-muted-foreground"
                        >
                            {{ m.instruksi }}
                        </p>
                    </li>
                </ul>
                <p class="mt-3 text-xs text-muted-foreground">
                    Setelah membayar, konfirmasi lewat tiket billing. Layanan
                    diproses setelah dikonfirmasi.
                </p>
            </template>
            <p v-else class="mt-2 text-sm">
                Pembayaran online sedang kami siapkan. Untuk saat ini, buat
                tiket billing dengan menyebut nomor tagihan. Layanan diproses
                setelah pembayaran dikonfirmasi.
            </p>
            <Button as-child class="mt-4"
                ><Link :href="konfirmasi">{{
                    metode_bayar.length
                        ? "Konfirmasi pembayaran"
                        : "Buat tiket billing"
                }}</Link></Button
            >
        </section>
        <Dialog :open="qris !== null" @update:open="(v) => !v && (qris = null)">
            <DialogContent v-if="qris" class="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>{{ qris.nama }}</DialogTitle>
                    <DialogDescription>
                        Scan lalu bayar tepat
                        <strong class="text-ink tabular-nums">{{
                            rupiah(tagihan.total)
                        }}</strong>.
                    </DialogDescription>
                </DialogHeader>
                <img
                    :src="qris.gambar_url ?? ''"
                    :alt="`Kode QR ${qris.nama}`"
                    class="w-full rounded border border-border bg-white object-contain"
                />
                <div class="flex items-center justify-between gap-3 text-sm">
                    <span v-if="qris.atas_nama" class="text-muted-foreground"
                        >a.n. {{ qris.atas_nama }}</span
                    >
                    <TombolSalin
                        :teks="String(tagihan.total)"
                        label="nominal"
                        class="ml-auto"
                    />
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
