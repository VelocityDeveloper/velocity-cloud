<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import KatalogController from '@/actions/App/Http/Controllers/KatalogController';
import PageHeader from '@/components/cloud/PageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { rupiah } from '@/lib/format';
import katalog from '@/routes/katalog';
import type { Paket } from '@/types';

const props = defineProps<{
    paket: Paket;
    domain_app: string;
    beli_maks_juta: number;
    endpoint_ai: string;
    pilihan_os: Record<string, string> | null;
}>();
const ai = props.paket.kategori === 'ai';

// Token AI: pilihan cepat + jumlah bebas, ringkasan pesanan ikut berubah.
const pilihanJuta = [5, 10, 50, 100].filter((n) => n <= props.beli_maks_juta);
const juta = ref(10);
const jumlah = computed(() => Number(juta.value) || 0);
const jumlahSah = computed(
    () => Number.isInteger(jumlah.value) && jumlah.value >= 1 && jumlah.value <= props.beli_maks_juta,
);
const total = computed(() => (props.paket.harga ?? 0) * jumlah.value);
const angka = (n: number) => n.toLocaleString('id-ID');

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Pesan Layanan', href: katalog.index() },
            { title: 'Pesan', href: '#' },
        ],
    },
});

const spek = Object.entries(props.paket.spek ?? {}).filter(
    ([k]) => k !== 'kategori_app' && !(ai && k === 'satuan'),
);
const namaSpek: Record<string, string> = {
    model: 'Model',
    satuan: 'Satuan',
    cpu: 'CPU',
    ram: 'RAM',
    disk: 'Storage',
    bandwidth: 'Bandwidth',
};
</script>

<template>
    <Head :title="ai ? `Beli token ${paket.nama}` : `Pesan ${paket.nama}`" />
    <Form
        v-bind="KatalogController.store.form(paket.slug)"
        class="grid gap-8 p-4 md:p-6 lg:grid-cols-[1fr_20rem]"
        v-slot="{ errors, processing }"
    >
        <div class="max-w-xl space-y-6">
            <PageHeader
                :title="ai ? `Beli token ${paket.nama}` : `Pesan ${paket.nama}`"
                :description="paket.ringkas ?? undefined"
            />

            <fieldset v-if="ai" class="grid gap-3">
                <legend class="text-sm font-medium text-ink">Jumlah token</legend>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <label
                        v-for="n in pilihanJuta"
                        :key="n"
                        class="cursor-pointer rounded-md border border-input px-3 py-2.5 transition-colors hover:border-brand-600 has-[:checked]:border-brand-700 has-[:checked]:bg-brand-50 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring"
                    >
                        <input v-model.number="juta" type="radio" :value="n" class="sr-only" />
                        <span class="block font-semibold text-ink tabular-nums">{{ n }} juta</span>
                        <span class="block text-xs text-muted-foreground tabular-nums">{{ rupiah((paket.harga ?? 0) * n) }}</span>
                    </label>
                </div>
                <div class="grid gap-2">
                    <Label for="token_juta" class="font-normal text-muted-foreground">Atau isi jumlah lain (juta token)</Label>
                    <Input
                        id="token_juta"
                        v-model.number="juta"
                        name="token_juta"
                        type="number"
                        inputmode="numeric"
                        min="1"
                        step="1"
                        :max="beli_maks_juta"
                        required
                        class="max-w-40 tabular-nums"
                    />
                    <p v-if="!jumlahSah" class="text-sm text-destructive">
                        Isi angka bulat 1 sampai {{ angka(beli_maks_juta) }} juta.
                    </p>
                    <InputError :message="errors.token_juta" />
                </div>
            </fieldset>

            <div v-if="pilihan_os" class="grid gap-2">
                <Label for="os">Sistem operasi</Label>
                <select
                    id="os"
                    name="os"
                    required
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                >
                    <option v-for="(nama, kunci) in pilihan_os" :key="kunci" :value="kunci">
                        {{ nama }}
                    </option>
                </select>
                <p class="text-xs text-muted-foreground">
                    VPS dibuat otomatis setelah lunas. Login root dan sandi tampil di halaman layanan.
                </p>
                <InputError :message="errors.os" />
            </div>

            <section v-if="ai" class="space-y-3 rounded-lg border border-border p-5 text-sm">
                <h2 class="font-semibold text-ink">Cara pakai setelah lunas</h2>
                <ol class="list-decimal space-y-1.5 pl-5 text-muted-foreground">
                    <li>API key <span class="font-mono text-ink">vc-…</span> tampil di halaman layanan.</li>
                    <li>Pasang di aplikasi yang mendukung API format OpenAI (n8n, chatbot, SDK OpenAI).</li>
                    <li>Saldo berkurang sesuai token yang terpakai. Top-up kapan saja dari halaman layanan.</li>
                </ol>
                <dl class="grid gap-1.5 border-t border-border pt-3">
                    <div class="flex flex-wrap justify-between gap-x-4">
                        <dt class="text-muted-foreground">Base URL</dt>
                        <dd class="font-mono text-ink break-all">{{ endpoint_ai }}</dd>
                    </div>
                    <div v-if="paket.spek?.model" class="flex flex-wrap justify-between gap-x-4">
                        <dt class="text-muted-foreground">Model</dt>
                        <dd class="font-mono text-ink">{{ paket.spek.model }}</dd>
                    </div>
                </dl>
            </section>

            <p v-if="paket.kategori === 'app'" class="rounded-md bg-brand-50 px-3 py-2 text-sm text-ink">
                Alamat app dibuat otomatis, misalnya
                <span class="font-mono">https://k7m2x9q4ra.{{ domain_app }}</span>.
                Custom domain sendiri menyusul.
            </p>

            <template v-if="!ai">
                <p v-if="paket.habis" class="text-sm text-destructive">
                    Stok paket ini sedang habis.
                </p>
                <Button
                    type="submit"
                    :disabled="processing || !paket.bisa_dipesan"
                    >Buat Pesanan</Button
                >
            </template>
        </div>

        <aside class="h-fit rounded-lg border border-border p-5 text-sm lg:sticky lg:top-6">
            <p class="font-semibold text-brand-700">{{ paket.nama }}</p>
            <p class="font-heading mt-1 text-2xl font-bold text-ink tabular-nums">
                {{ rupiah(paket.harga)
                }}<span class="text-sm font-normal text-muted-foreground">
                    {{ ai ? '/1 juta token' : '/bulan' }}</span
                >
            </p>
            <dl
                v-if="spek.length"
                class="mt-4 space-y-1.5 border-t border-border pt-4 tabular-nums"
            >
                <div
                    v-for="[k, v] in spek"
                    :key="k"
                    class="flex justify-between gap-4"
                >
                    <dt class="text-muted-foreground">
                        {{ namaSpek[k] ?? k }}
                    </dt>
                    <dd class="font-medium text-ink">{{ v }}</dd>
                </div>
            </dl>
            <ul
                v-if="paket.fitur?.length"
                class="mt-4 space-y-1.5 border-t border-border pt-4"
            >
                <li v-for="f in paket.fitur" :key="f">{{ f }}</li>
            </ul>

            <template v-if="ai">
                <dl class="mt-4 space-y-1.5 border-t border-border pt-4 tabular-nums">
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Jumlah</dt>
                        <dd class="font-medium text-ink">{{ angka(jumlah) }} juta token</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-4 pt-1">
                        <dt class="font-medium text-ink">Total</dt>
                        <dd class="font-heading text-xl font-bold text-ink">{{ rupiah(jumlahSah ? total : 0) }}</dd>
                    </div>
                </dl>
                <p v-if="paket.habis" class="mt-4 text-sm text-destructive">
                    Paket ini sedang tidak tersedia.
                </p>
                <Button
                    type="submit"
                    class="mt-4 w-full"
                    :disabled="processing || !paket.bisa_dipesan || !jumlahSah"
                    >{{ jumlahSah ? `Buat tagihan ${rupiah(total)}` : 'Buat tagihan' }}</Button
                >
            </template>

            <p
                class="mt-4 border-t border-border pt-4 text-xs text-muted-foreground"
            >
                <template v-if="ai">
                    Saldo prabayar: input dan output dihitung sama, saldo tidak
                    hangus. API key dan saldo aktif setelah tagihan lunas.
                </template>
                <template v-else>
                    Tagihan pertama untuk 1 bulan terbit setelah pesanan dibuat.
                    Layanan disiapkan setelah lunas.
                </template>
            </p>
        </aside>
    </Form>
</template>
