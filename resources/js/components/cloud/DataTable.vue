<script setup lang="ts">
// Wadah tabel: border garis, scroll horizontal di HP. `relative` agar elemen sr-only tidak melebarkan halaman.
defineProps<{ kosong?: boolean; pesanKosong?: string }>();
</script>

<template>
    <div class="relative overflow-x-auto rounded-lg border border-border">
        <table
            v-if="!kosong"
            class="w-full text-left text-sm tabular-nums [&_tbody_tr]:border-t [&_tbody_tr]:border-border [&_td]:px-4 [&_td]:py-3 [&_td]:whitespace-nowrap [&_th]:px-4 [&_th]:py-3 [&_th]:font-semibold [&_th]:whitespace-nowrap [&_thead]:bg-brand-50 [&_thead]:text-ink"
        >
            <slot />
        </table>
        <p v-else class="px-4 py-10 text-center text-sm text-muted-foreground">
            <slot name="kosong">{{ pesanKosong }}</slot>
        </p>
    </div>
</template>
