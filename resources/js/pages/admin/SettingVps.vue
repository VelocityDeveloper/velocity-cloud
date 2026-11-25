<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import SettingVpsController from '@/actions/App/Http/Controllers/Admin/SettingVpsController';
import PageHeader from '@/components/cloud/PageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{ os: { kunci: string; nama: string; aktif: boolean }[] }>();
const f = useForm({ os: props.os.filter((o) => o.aktif).map((o) => o.kunci) });
</script>

<template>
    <Head title="Admin · Setting VPS" />
    <div class="space-y-6 p-4 md:p-6">
        <PageHeader title="Setting VPS" description="Pengaturan Cloud Server yang dijual otomatis." />
        <section class="rounded-lg border border-border">
            <div class="border-b border-border px-5 py-3">
                <h2 class="font-semibold text-ink">Sistem operasi yang dijual</h2>
                <p class="text-xs text-muted-foreground">Pilihan OS di halaman pesan VPS. VPS yang sudah berjalan tidak terpengaruh.</p>
            </div>
            <form class="space-y-3 px-5 py-4 text-sm" @submit.prevent="f.put(SettingVpsController.update.url(), { preserveScroll: true })">
                <label v-for="o in os" :key="o.kunci" class="flex items-center gap-2">
                    <input v-model="f.os" type="checkbox" :value="o.kunci" class="size-4 accent-brand-700" />
                    {{ o.nama }}
                </label>
                <InputError :message="f.errors.os" />
                <Button type="submit" size="sm" :disabled="f.processing || !f.os.length">Simpan</Button>
            </form>
        </section>
    </div>
</template>
