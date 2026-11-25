<script setup lang="ts">
import { computed } from 'vue';

// Warna status: hijau = beres, kuning = butuh tindakan, merah = masalah, abu = selesai/diam.
const props = defineProps<{ status: string; label: string }>();

const gaya = computed(() => {
    switch (props.status) {
        case 'aktif':
        case 'lunas':
        case 'dijawab':
            return 'bg-volt/15 text-volt-700';
        case 'menunggu_pembayaran':
        case 'belum_dibayar':
        case 'disiapkan':
        case 'terbuka':
        case 'dibuat': // sesi VDC Pay
        case 'menunggu':
            return 'bg-amber-100 text-amber-800';
        case 'gagal':
        case 'ditangguhkan':
        case 'ditolak':
            return 'bg-red-100 text-red-700';
        default:
            return 'bg-slate-100 text-slate-600';
    }
});
</script>

<template>
    <span
        :class="[
            'inline-block rounded-md px-2 py-0.5 text-xs font-semibold whitespace-nowrap',
            gaya,
        ]"
        >{{ label }}</span
    >
</template>
