<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10"
    >
        <div class="w-full max-w-sm">
            <div class="flex flex-col gap-8">
                <div class="flex flex-col items-center gap-4">
                    <Link
                        :href="home()"
                        class="flex flex-col items-center gap-2 font-medium"
                    >
                        <img
                            src="/images/velocity-logo-blue.webp"
                            alt="Velocity Cloud"
                            class="mb-1 h-auto w-40 dark:hidden"
                            width="200"
                            height="52"
                        />
                        <img
                            src="/images/velocity-logo-putih.webp"
                            alt="Velocity Cloud"
                            class="mb-1 hidden h-auto w-40 dark:block"
                            width="165"
                            height="39"
                        />
                        <span class="sr-only">Velocity Cloud</span>
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
