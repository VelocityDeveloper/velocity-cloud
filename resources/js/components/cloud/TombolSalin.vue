<script setup lang="ts">
import { useClipboard } from "@vueuse/core";
import { Check, Copy } from "@lucide/vue";

// ikon: tombol ikon saja (mis. di samping ikon mata kredensial)
const props = defineProps<{ teks: string; label: string; ikon?: boolean }>();
const { copy, copied } = useClipboard({ legacy: true });
</script>

<template>
    <button
        type="button"
        :class="
            props.ikon
                ? 'shrink-0 rounded p-0.5 text-muted-foreground hover:text-ink focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none'
                : 'inline-flex shrink-0 items-center gap-1 rounded-md border border-input bg-background px-2 py-1 text-xs font-medium text-ink hover:border-brand-600'
        "
        :aria-label="`Salin ${props.label}`"
        :title="copied ? 'Tersalin' : `Salin ${props.label}`"
        @click="copy(props.teks)"
    >
        <Check
            v-if="copied"
            :class="props.ikon ? 'size-4 text-green-600' : 'size-3.5 text-green-600'"
            aria-hidden="true"
        />
        <Copy v-else :class="props.ikon ? 'size-4' : 'size-3.5'" aria-hidden="true" />
        <template v-if="!props.ikon">{{ copied ? "Tersalin" : "Salin" }}</template>
    </button>
</template>
