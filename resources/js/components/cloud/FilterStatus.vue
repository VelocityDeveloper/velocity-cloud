<script setup lang="ts">
import { router } from '@inertiajs/vue3';

const props = withDefaults(
    defineProps<{
        nilai: string;
        pilihan: { value: string; label: string }[];
        param?: string;
        judul?: string;
    }>(),
    { param: 'status', judul: 'Status' },
);

// Filter lain di URL ikut dibawa supaya beberapa filter bisa digabung.
function ganti(e: Event) {
    router.get(
        window.location.pathname,
        {
            ...Object.fromEntries(new URLSearchParams(window.location.search)),
            [props.param]: (e.target as HTMLSelectElement).value,
        },
        { preserveState: true, replace: true },
    );
}
</script>

<template>
    <label class="flex items-center gap-2 text-sm">
        <span class="text-muted-foreground">{{ props.judul }}</span>
        <select
            :value="props.nilai"
            class="h-9 rounded-md border border-input bg-white px-3 text-sm"
            @change="ganti"
        >
            <option value="semua">Semua</option>
            <option v-for="p in pilihan" :key="p.value" :value="p.value">
                {{ p.label }}
            </option>
        </select>
    </label>
</template>
