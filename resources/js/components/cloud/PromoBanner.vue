<script setup lang="ts">
import { Gift } from "@lucide/vue";
import { computed } from "vue";

export type PromoAktif = { nama: string; bonus_token_juta: number; min_pembelian: number; kategori: string[]; selesai: string | null };
const props = defineProps<{ promo: PromoAktif[]; kategori: string }>();

const cocok = computed(() => props.promo.filter((p) => p.kategori.includes(props.kategori)));
const sampai = (s: string | null) =>
    s ? ` s/d ${new Date(s).toLocaleDateString("id-ID", { day: "numeric", month: "long", year: "numeric" })}` : "";
</script>

<template>
    <p
        v-for="p in cocok"
        :key="p.nama"
        class="flex items-start gap-2 rounded-md bg-volt/15 px-3 py-2 text-sm text-ink"
    >
        <Gift class="mt-0.5 size-4 shrink-0 text-volt-700" aria-hidden="true" />
        <span>
            <strong>{{ p.nama }}:</strong> gratis {{ p.bonus_token_juta }} juta token AI DeepSeek untuk setiap pembelian baru
            minimal Rp{{ p.min_pembelian.toLocaleString("id-ID") }}{{ sampai(p.selesai) }}. Saldo token masuk otomatis
            setelah tagihan lunas.
        </span>
    </p>
</template>
