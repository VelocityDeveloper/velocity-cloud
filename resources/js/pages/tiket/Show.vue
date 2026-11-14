<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { FileText } from '@lucide/vue';
import { computed } from 'vue';
import TiketController from '@/actions/App/Http/Controllers/TiketController';
import PageHeader from '@/components/cloud/PageHeader.vue';
import PilihLampiran from '@/components/cloud/PilihLampiran.vue';
import StatusBadge from '@/components/cloud/StatusBadge.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import admin from '@/routes/admin';
import tiketRoute from '@/routes/tiket';
import type { Tiket } from '@/types';

const props = defineProps<{
    tiket: Tiket;
    pesan: {
        id: number;
        isi: string;
        dari_admin: boolean;
        nama: string;
        waktu: string;
        lampiran: { id: number; nama: string; mime: string; ukuran: number; url: string }[];
    }[];
}>();
const page = usePage();
// admin membuka tiket pelanggan: breadcrumb ke daftar admin
const modeAdmin = computed(() => Boolean(page.props.auth.user?.is_admin) && props.tiket.email !== page.props.auth.user?.email);
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Support Ticket', href: tiketRoute.index() },
            { title: 'Detail', href: '#' },
        ],
    },
});

const f = useForm({ isi: '', lampiran: [] as File[] });
function kirim() {
    f.post(TiketController.balas(props.tiket.id).url, { preserveScroll: true, forceFormData: true, onSuccess: () => f.reset() });
}
function tutup() {
    router.post(TiketController.tutup(props.tiket.id).url, {}, { preserveScroll: true });
}
const waktu = (w: string) => w.slice(0, 16).replace('T', ' ');
</script>

<template>
    <Head :title="`Tiket #${tiket.id}`" />
    <div class="max-w-3xl space-y-6 p-4 md:p-6">
        <PageHeader
            :title="`#${tiket.id} ${tiket.judul}`"
            :description="[modeAdmin ? `${tiket.pelanggan} · ${tiket.email}` : '', tiket.layanan ?? ''].filter(Boolean).join(' · ') || undefined"
        >
            <StatusBadge :status="tiket.status" :label="tiket.status_label" />
        </PageHeader>

        <ol class="space-y-3">
            <li
                v-for="p in pesan"
                :key="p.id"
                class="rounded-lg border p-4 text-sm"
                :class="p.dari_admin ? 'border-brand-200 bg-brand-50' : 'border-border'"
            >
                <p class="flex flex-wrap justify-between gap-x-4 text-xs text-muted-foreground">
                    <span class="font-semibold text-ink">{{ p.nama }}</span>
                    <time :datetime="p.waktu">{{ waktu(p.waktu) }}</time>
                </p>
                <p class="mt-2 whitespace-pre-line break-words">{{ p.isi }}</p>
                <ul v-if="p.lampiran.length" class="mt-3 flex flex-wrap gap-2">
                    <li v-for="l in p.lampiran" :key="l.id">
                        <video v-if="l.mime.startsWith('video/')" :src="l.url" controls preload="metadata" class="max-h-64 max-w-full rounded border border-border" />
                        <a v-else :href="l.url" target="_blank" rel="noopener" class="block" :title="l.nama">
                            <img
                                v-if="l.mime.startsWith('image/')"
                                :src="l.url"
                                :alt="l.nama"
                                loading="lazy"
                                class="size-28 rounded border border-border bg-white object-cover"
                            />
                            <span v-else class="inline-flex items-center gap-1.5 rounded-md border border-border bg-background px-3 py-2 text-xs font-medium text-ink hover:border-brand-600">
                                <FileText class="size-4" aria-hidden="true" />{{ l.nama }}
                            </span>
                        </a>
                    </li>
                </ul>
            </li>
        </ol>

        <form class="space-y-3" @submit.prevent="kirim">
            <label for="isi" class="text-sm font-medium text-ink">{{
                tiket.status === 'ditutup' ? 'Balas untuk membuka lagi tiket ini' : 'Balas'
            }}</label>
            <textarea
                id="isi"
                v-model="f.isi"
                required
                rows="4"
                maxlength="5000"
                class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm"
            />
            <InputError :message="f.errors.isi" />
            <PilihLampiran id="lampiran" v-model="f.lampiran" :galat="Object.entries(f.errors).filter(([k]) => k.startsWith('lampiran')).map(([, v]) => v).join(' ') || undefined" />
            <div class="flex flex-wrap gap-2">
                <Button type="submit" :disabled="f.processing">Kirim balasan</Button>
                <Button v-if="tiket.status !== 'ditutup'" type="button" variant="outline" @click="tutup">Tutup tiket</Button>
                <Button as-child variant="ghost">
                    <Link :href="modeAdmin ? admin.tiket.index() : tiketRoute.index()">Kembali</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
