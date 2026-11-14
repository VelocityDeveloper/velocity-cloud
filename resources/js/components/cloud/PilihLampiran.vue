<script setup lang="ts">
import { Paperclip, X } from '@lucide/vue';
import { ref } from 'vue';

// Pilih maks 3 berkas (gambar/video/PDF, 10 MB per berkas); daftar berkas dipegang induk lewat v-model.
const berkas = defineModel<File[]>({ required: true });
const props = defineProps<{ id: string; galat?: string }>();
const MAKS = 3;
const pilih = ref<HTMLInputElement | null>(null);

function tambah(e: Event) {
    const input = e.target as HTMLInputElement;
    berkas.value = [...berkas.value, ...Array.from(input.files ?? [])].slice(0, MAKS);
    input.value = '';
}
const mb = (n: number) => (n / 1048576).toFixed(1) + ' MB';
</script>

<template>
    <div class="grid gap-2">
        <button
            v-if="berkas.length < MAKS"
            type="button"
            class="inline-flex w-fit items-center gap-1.5 rounded-md border border-input px-3 py-1.5 text-sm text-ink hover:border-brand-600 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            @click="pilih?.click()"
        >
            <Paperclip class="size-4" aria-hidden="true" /> Lampirkan media
        </button>
        <!-- input asli disembunyikan total agar teks bawaan browser ("Tidak ada file yang dipilih") tidak muncul -->
        <input
            :id="props.id"
            ref="pilih"
            type="file"
            multiple
            accept="image/jpeg,image/png,image/webp,image/gif,application/pdf,video/mp4,video/webm,video/quicktime"
            class="hidden"
            @change="tambah"
        />
        <ul v-if="berkas.length" class="space-y-1 text-sm">
            <li v-for="(b, i) in berkas" :key="i" class="flex items-center justify-between gap-2 rounded-md border border-border px-3 py-1.5">
                <span class="min-w-0 truncate">{{ b.name }}</span>
                <span class="flex shrink-0 items-center gap-2 text-xs" :class="b.size > 10485760 ? 'text-destructive' : 'text-muted-foreground'">
                    {{ mb(b.size) }}
                    <button type="button" :aria-label="`Hapus ${b.name}`" class="rounded p-0.5 hover:bg-muted" @click="berkas.splice(i, 1)">
                        <X class="size-4" aria-hidden="true" />
                    </button>
                </span>
            </li>
        </ul>
        <p v-if="props.galat" class="text-sm text-destructive">{{ props.galat }}</p>
    </div>
</template>
