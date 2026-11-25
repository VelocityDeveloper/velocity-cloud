<script setup lang="ts">
import { Head, useForm } from "@inertiajs/vue3";
import { CircleCheck, CircleX, Clock } from "@lucide/vue";
import { computed } from "vue";
import CheckoutController from "@/actions/App/Http/Controllers/CheckoutController";
import { Button } from "@/components/ui/button";
import { rupiah } from "@/lib/format";

type Sesi = {
    id: string;
    klien: string;
    deskripsi: string;
    jumlah: number;
    email: string;
    status: "dibuat" | "menunggu" | "lunas" | "kedaluwarsa" | "batal" | "ditolak" | "refund";
    status_label: string;
    berlaku_sampai: string;
    url_sukses: string | null; // hanya terisi bila lunas
    url_batal: string | null;
};
const props = defineProps<{ sesi: Sesi; bisa_bayar: boolean }>();
defineOptions({ layout: { title: "Pembayaran", description: "Diproses oleh Velocity Data Center" } });

const f = useForm({});
const bayar = () => f.post(CheckoutController.bayar(props.sesi.id).url);

const batas = computed(() =>
    new Date(props.sesi.berlaku_sampai).toLocaleString("id-ID", { dateStyle: "medium", timeStyle: "short" }),
);
const gagal = computed(() => ["kedaluwarsa", "batal", "ditolak", "refund"].includes(props.sesi.status));
</script>

<template>
    <Head :title="`Bayar ${sesi.klien}`" />
    <div class="space-y-5 rounded-lg border border-border bg-background p-6 shadow-sm">
        <div class="space-y-1">
            <p class="text-sm text-muted-foreground">Pembayaran untuk</p>
            <p class="text-lg font-semibold text-ink">{{ sesi.klien }}</p>
            <p class="text-sm">{{ sesi.deskripsi }}</p>
        </div>
        <div class="flex items-baseline justify-between border-y border-border py-4">
            <span class="text-sm text-muted-foreground">Total</span>
            <span class="text-2xl font-bold text-ink">{{ rupiah(sesi.jumlah) }}</span>
        </div>
        <p class="text-sm text-muted-foreground">Email pembeli: {{ sesi.email }}</p>

        <div v-if="sesi.status === 'lunas'" class="space-y-4" role="status">
            <p class="flex items-center gap-2 font-semibold text-green-700 dark:text-green-400">
                <CircleCheck class="size-5" aria-hidden="true" /> Pembayaran berhasil
            </p>
            <Button v-if="sesi.url_sukses" as="a" :href="sesi.url_sukses" class="w-full">Kembali ke {{ sesi.klien }}</Button>
        </div>

        <div v-else-if="bisa_bayar" class="space-y-3">
            <p v-if="sesi.status === 'menunggu'" class="flex items-start gap-2 text-sm" role="status">
                <Clock class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                Menunggu pembayaran. Jika sudah mendapat nomor VA atau kode bayar, selesaikan pembayarannya lalu muat
                ulang halaman ini. {{ sesi.klien }} juga diberi tahu otomatis setelah dana masuk.
            </p>
            <Button class="w-full" :disabled="f.processing" @click="bayar">
                {{ sesi.status === "menunggu" ? "Lanjutkan pembayaran" : "Bayar sekarang" }}
            </Button>
            <p class="text-xs text-muted-foreground">
                VA bank, QRIS, e-wallet, atau kartu lewat Midtrans. Bayar sebelum {{ batas }}.
            </p>
        </div>

        <div v-else class="space-y-4" role="status">
            <p class="flex items-center gap-2 font-semibold" :class="gagal ? 'text-red-700 dark:text-red-400' : ''">
                <CircleX class="size-5" aria-hidden="true" />
                {{ gagal ? sesi.status_label : "Sesi pembayaran sudah berakhir" }}
            </p>
            <p class="text-sm text-muted-foreground">Silakan ulangi pemesanan dari {{ sesi.klien }}.</p>
        </div>

        <a v-if="sesi.status !== 'lunas' && sesi.url_batal" :href="sesi.url_batal" class="block text-center text-sm underline">
            Batal, kembali ke {{ sesi.klien }}
        </a>
    </div>
</template>
