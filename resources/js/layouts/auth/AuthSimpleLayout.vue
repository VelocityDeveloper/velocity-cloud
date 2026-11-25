<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PixelWave from '@/components/PixelWave.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="relative flex min-h-svh flex-col items-center justify-center gap-6 overflow-hidden bg-background p-6 md:p-10"
    >
        <!-- Mask: area form di tengah dikosongkan agar input transparan tetap terbaca. -->
        <PixelWave class="[mask-image:radial-gradient(ellipse_340px_440px_at_center,transparent_55%,black_100%)]" />
        <div class="relative w-full max-w-sm">
            <div class="flex flex-col gap-8">
                <div class="flex flex-col items-center gap-4">
                    <Link
                        :href="home()"
                        class="flex flex-col items-center gap-2 font-medium"
                    >
                        <img
                            src="/images/velocity-logo-blue.webp"
                            alt="Velocity Data Center"
                            class="mb-1 h-auto w-62 dark:hidden"
                            width="200"
                            height="52"
                        />
                        <img
                            src="/images/velocity-logo-putih.webp"
                            alt="Velocity Data Center"
                            class="mb-1 hidden h-auto w-40 dark:block"
                            width="165"
                            height="39"
                        />
                        <span class="sr-only">Velocity Data Center</span>
                    </Link>
                    <div v-if="title" class="space-y-2 text-center">
                        <h1 class="text-xl font-medium">{{ title }}</h1>
                        <p v-if="description" class="text-center text-sm text-muted-foreground">
                            {{ description }}
                        </p>
                    </div>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
