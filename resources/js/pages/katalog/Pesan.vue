<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import KatalogController from '@/actions/App/Http/Controllers/KatalogController';
import DataTable from '@/components/cloud/DataTable.vue';
import PageHeader from '@/components/cloud/PageHeader.vue';
import PromoBanner from '@/components/cloud/PromoBanner.vue';
import type { PromoAktif } from '@/components/cloud/PromoBanner.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { rupiah } from '@/lib/format';
import katalog from '@/routes/katalog';
import type { Paket } from '@/types';

const props = defineProps<{
    paket: Paket;
    alternatif: Paket[];
    domain_app: string;
    beli_maks_juta: number;
    endpoint_ai: string;
    pilihan_os: Record<string, string> | null;
    promo: PromoAktif[];
    varian: (Paket & { nama_varian: string })[];
}>();
const ai = props.paket.kategori === 'ai';
const hosting = props.paket.kategori === 'hosting'; // tagihan tahunan

// Token AI: pilihan cepat + jumlah bebas, ringkasan pesanan ikut berubah.
const pilihanJuta = [5, 10, 50, 100].filter((n) => n <= props.beli_maks_juta);
const juta = ref(10);
const jumlah = computed(() => Number(juta.value) || 0);
const jumlahSah = computed(
    () => Number.isInteger(jumlah.value) && jumlah.value >= 1 && jumlah.value <= props.beli_maks_juta,
);
const hargaBeli = computed(() => props.paket.harga_promo ?? props.paket.harga ?? 0); // promo token AI bila berlaku
const total = computed(() => hargaBeli.value * jumlah.value);
const angka = (n: number) => n.toLocaleString('id-ID');
const tglWaktu = (v: string) => new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(v));

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Pesan Layanan', href: katalog.index() },
            { title: 'Pesan', href: '#' },
        ],
    },
});

// Token AI: harga paket = output; input & cache ditampilkan dalam rupiah.
const spek = Object.entries(props.paket.spek ?? {})
    .filter(([k]) => k !== 'kategori_app' && !(ai && k === 'satuan'))
    .map(([k, v]): [string, unknown] => [
        k,
        k.startsWith('harga_') ? `${rupiah(Number(v))}/1 jt` : k === 'konteks' ? `${Number(v).toLocaleString('id-ID')} token` : v,
    ]);
const namaSpek: Record<string, string> = {
    model: 'Model',
    satuan: 'Satuan',
    provider: 'Provider',
    konteks: 'Konteks',
    harga_input: 'Harga input',
    harga_cache: 'Harga cache',
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
        class="grid gap-8 p-4 md:p-6 lg:grid-cols-[1fr_20rem] [&>*]:min-w-0"
        v-slot="{ errors, processing }"
    >
        <div class="max-w-xl min-w-0 space-y-6">
            <PageHeader
                :title="ai ? `Beli token ${paket.nama}` : `Pesan ${paket.nama}`"
                :description="paket.ringkas ?? undefined"
            />
            <PromoBanner :promo="promo" :kategori="paket.kategori" />

            <section v-if="varian.length > 1" aria-labelledby="judul-varian" class="grid gap-3">
                <h2 id="judul-varian" class="text-sm font-medium text-ink">Pilih paket</h2>
                <DataTable>
                    <thead>
                        <tr>
                            <th scope="col">Paket</th>
                            <th scope="col">CPU</th>
                            <th scope="col">RAM</th>
                            <th scope="col">Penyimpanan</th>
                            <th scope="col" class="text-right">Harga/bulan</th>
                            <th scope="col" class="text-right">Pilih</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="v in varian"
                            :key="v.slug"
                            :class="{ 'bg-volt/10 shadow-[inset_3px_0_0_var(--color-volt-600)]': v.slug === paket.slug }"
                        >
                            <th scope="row" class="text-ink">
                                {{ v.nama_varian }}
                                <span v-if="!v.bisa_dipesan" class="block text-xs font-normal text-muted-foreground">{{ v.habis ? 'Stok habis' : 'Belum tersedia' }}</span>
                            </th>
                            <td>{{ v.spek?.cpu }}</td>
                            <td>{{ v.spek?.ram }}</td>
                            <td>Wajar (fair use)</td>
                            <td class="text-right font-semibold text-ink">{{ rupiah(v.harga) }}</td>
                            <td class="text-right">
                                <Button v-if="v.slug === paket.slug" size="sm" variant="outline" disabled>Dipilih</Button>
                                <Button v-else-if="!v.bisa_dipesan" size="sm" variant="outline" disabled>Pilih</Button>
                                <Button v-else as-child size="sm">
                                    <Link :href="katalog.show(v.slug)" preserve-scroll>Pilih</Link>
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </DataTable>
                <p class="text-xs text-muted-foreground">
                    CPU dan RAM adalah batas maksimum pod.<span class="sm:hidden"> Geser tabel ke samping untuk melihat harga.</span>
                </p>
            </section>

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
                        <span class="block text-xs text-muted-foreground tabular-nums">{{ rupiah(hargaBeli * n) }}</span>
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

            <div v-if="hosting" class="grid gap-2">
                <Label for="domain">Nama domain</Label>
                <Input
                    id="domain"
                    name="domain"
                    required
                    placeholder="contoh.com"
                    autocomplete="off"
                    autocapitalize="none"
                    spellcheck="false"
                    class="max-w-80"
                />
                <p class="text-xs text-muted-foreground">
                    Domain utama akun hosting. Akun DirectAdmin dibuat otomatis setelah lunas, info login tampil di halaman layanan.
                </p>
                <InputError :message="errors.domain" />
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
                <span class="font-mono break-all">https://k7m2x9q4ra.{{ domain_app }}</span>.
                Custom domain sendiri menyusul.
            </p>

            <template v-if="!ai">
                <div v-if="paket.habis && alternatif.length" class="rounded-md border border-brand-100 bg-brand-50 p-4 text-sm">
                    <p class="font-medium text-ink">Paket ini sedang habis. Rekomendasi paket yang tersedia:</p>
                    <ul class="mt-3 grid gap-2">
                        <li v-for="a in alternatif" :key="a.slug" class="flex items-center justify-between gap-4 rounded-md bg-white px-3 py-2">
                            <span>
                                <span class="font-semibold text-brand-700">{{ a.nama }}</span>
                                <span class="block text-xs text-muted-foreground">
                                    {{ [a.spek?.cpu, a.spek?.ram, a.spek?.disk].filter(Boolean).join(' · ') }}
                                </span>
                            </span>
                            <span class="flex items-center gap-3">
                                <span class="font-medium tabular-nums">{{ rupiah(a.harga) }}</span>
                                <Button as-child size="sm"><Link :href="katalog.show(a.slug)">Pilih</Link></Button>
                            </span>
                        </li>
                    </ul>
                </div>
                <p v-if="paket.habis && !alternatif.length" class="text-sm text-destructive">
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
                <s v-if="paket.harga_promo != null" class="mr-1 text-base font-normal text-muted-foreground">{{ rupiah(paket.harga) }}</s
                >{{ rupiah(paket.harga_promo ?? paket.harga)
                }}<span class="text-sm font-normal text-muted-foreground">
                    {{ ai ? '/1 juta token output' : hosting ? '/tahun' : '/bulan' }}</span
                >
            </p>
            <p v-if="paket.harga_promo != null" class="mt-1 text-xs font-semibold text-brand-700">
                Harga promo{{ paket.promo_selesai ? ` sampai ${tglWaktu(paket.promo_selesai)} WIB` : '' }}
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
                    Tagihan pertama untuk 1 {{ hosting ? 'tahun' : 'bulan' }} terbit setelah pesanan dibuat.
                    Layanan disiapkan setelah lunas.
                </template>
            </p>
        </aside>
    </Form>
</template>
