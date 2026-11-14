<script setup lang="ts">
import { router } from '@inertiajs/vue3';

const props = defineProps<{
    nilai: string;
    pilihan: { value: string; label: string }[];
}>();

function ganti(e: Event) {
    router.get(
        window.location.pathname,
        { status: (e.target as HTMLSelectElement).value },
        { preserveState: true, replace: true },
    );
}
</script>

<template>
    <label class="flex items-center gap-2 text-sm">
        <span class="text-muted-foreground">Status</span>
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
