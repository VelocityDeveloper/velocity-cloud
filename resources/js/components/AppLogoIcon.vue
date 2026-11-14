<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <img
        src="/favicon.png"
        alt="Velocity Cloud"
        width="32"
        height="32"
        class="object-contain"
        :class="className"
        v-bind="$attrs"
    />
</template>
