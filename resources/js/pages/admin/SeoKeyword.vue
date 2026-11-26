<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import DataTable from '@/components/cloud/DataTable.vue';
import PageHeader from '@/components/cloud/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { rupiah } from '@/lib/format';
import admin from '@/routes/admin';

type Ide = {
    kata: string;
    volume: number | null;
    persaingan: 'rendah' | 'sedang' | 'tinggi' | null;
    indeks: number | null;
    cpc_rendah: number | null;
    cpc_tinggi: number | null;
    tren: [string, number][];
    dipetakan: boolean;
};
const props = defineProps<{
    isian: { kata: string; url: string; lokasi: string; bahasa: string };
    pilihanLokasi: Record<string, string>;
    pilihanBahasa: Record<string, string>;
    starter: { nama: string; kata: string; url: string }[];
    siap: boolean;
    hasil: Ide[] | null;
    galat: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin · SEO', href: admin.seo.index() },
            { title: 'Keyword Planner', href: admin.seo.keyword() },
        ],
    },
});

const f = reactive({ ...props.isian });
const contohUrl = 'https://velocitydatacenter.com/cloud-server/'; // di script: "//" di atribut template salah dibaca vue-tsc
const memuat = ref(false);
function cari() {
    router.get(admin.seo.keyword().url, { ...f }, { preserveScroll: true, onStart: () => (memuat.value = true), onFinish: () => (memuat.value = false) });
}

// Starter: isi form dengan kata kunci + URL halaman landing, lalu langsung cari (lokasi & bahasa tetap pilihan saat ini).
function pakaiStarter(s: { kata: string; url: string }) {
    f.kata = s.kata;
    f.url = s.url;
    cari();
}
const starterAktif = (s: { kata: string; url: string }) => props.isian.kata === s.kata && props.isian.url === s.url;

// Saring & urut di browser: hasil Google sudah lengkap (maks 500 baris).
const saring = ref('');
const minVolume = ref(0);
const sembunyikanDipetakan = ref(false);
type Kolom = 'kata' | 'volume' | 'indeks' | 'cpc_tinggi';
const urut = ref<{ kolom: Kolom; turun: boolean }>({ kolom: 'volume', turun: true });
function urutkan(kolom: Kolom) {
    urut.value = urut.value.kolom === kolom ? { kolom, turun: !urut.value.turun } : { kolom, turun: kolom !== 'kata' };
}
const baris = computed(() => {
    const q = saring.value.trim().toLowerCase();
    const { kolom, turun } = urut.value;
    return (props.hasil ?? [])
        .filter((r) => (!q || r.kata.includes(q)) && (r.volume ?? 0) >= minVolume.value && !(sembunyikanDipetakan.value && r.dipetakan))
        .sort((a, b) => {
            const x = a[kolom] ?? -1;
            const y = b[kolom] ?? -1;
            const beda = typeof x === 'string' ? x.localeCompare(String(y)) : x - (y as number);
            return turun ? -beda : beda;
        });
});
const tandaUrut = (k: Kolom) => (urut.value.kolom === k ? (urut.value.turun ? ' ↓' : ' ↑') : '');
const ariaUrut = (k: Kolom) => (urut.value.kolom === k ? (urut.value.turun ? 'descending' : 'ascending') : 'none');

const angka = (n: number | null) => (n == null ? '-' : n.toLocaleString('id-ID'));
const warnaPersaingan = { rendah: 'text-green-700', sedang: 'text-amber-700', tinggi: 'text-destructive' };

// Sparkline 12 bulan: titik dinormalkan ke 60×18.
function garis(tren: [string, number][]) {
    if (tren.length < 2) return '';
    const maks = Math.max(...tren.map((t) => t[1]), 1);
    return tren.map((t, i) => `${((i / (tren.length - 1)) * 60).toFixed(1)},${(17 - (t[1] / maks) * 16).toFixed(1)}`).join(' ');
}
const ringkasTren = (tren: [string, number][]) => tren.map((t) => `${t[0]}: ${t[1].toLocaleString('id-ID')}`).join(', ');

function unduhCsv() {
    const kepala = ['kata_kunci', 'volume_bulanan', 'persaingan', 'indeks_persaingan', 'cpc_rendah', 'cpc_tinggi', 'di_keyword_mapping'];
    const isi = baris.value.map((r) => [`"${r.kata.replace(/"/g, '""')}"`, r.volume ?? '', r.persaingan ?? '', r.indeks ?? '', r.cpc_rendah ?? '', r.cpc_tinggi ?? '', r.dipetakan ? 'ya' : ''].join(','));
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([[kepala.join(','), ...isi].join('\n')], { type: 'text/csv' }));
    a.download = `keyword-planner-${(props.isian.kata || 'url').slice(0, 40).replace(/[^a-z0-9]+/gi, '-')}.csv`;
    a.click();
    URL.revokeObjectURL(a.href);
}
</script>

<template>
    <Head title="Admin · Keyword Planner" />
    <div class="space-y-6 p-4 md:p-6">
        <PageHeader title="Keyword Planner" description="Ide kata kunci, volume pencarian bulanan, dan persaingan dari Google Ads Keyword Planner." />

        <p v-if="!siap" class="rounded-md bg-amber-50 px-3 py-2 text-sm text-ink">
            Kredensial Keyword Planner (KP_*) belum diisi di .env velocity-cloud.
        </p>

        <section v-if="starter.length" aria-labelledby="judul-starter" class="space-y-2">
            <h2 id="judul-starter" class="text-sm font-medium text-ink">Starter halaman</h2>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-for="s in starter"
                    :key="s.url"
                    type="button"
                    size="sm"
                    :variant="starterAktif(s) ? 'default' : 'outline'"
                    :disabled="memuat || !siap"
                    :title="s.kata"
                    @click="pakaiStarter(s)"
                    >{{ s.nama }}</Button
                >
            </div>
            <p class="text-xs text-muted-foreground">Mengisi kata kunci dasar + URL halaman landing, lalu langsung mencari.</p>
        </section>

        <form class="grid gap-4 rounded-lg border border-border p-5 md:grid-cols-2" @submit.prevent="cari">
            <div class="grid gap-2 md:col-span-2">
                <Label for="kata">Kata kunci dasar</Label>
                <textarea
                    id="kata"
                    v-model="f.kata"
                    rows="2"
                    placeholder="sewa vps, hosting n8n, domain murah"
                    class="rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                />
                <p class="text-xs text-muted-foreground">Pisahkan dengan koma atau baris baru, maks 20.</p>
            </div>
            <div class="grid gap-2 md:col-span-2">
                <Label for="url">Atau URL halaman (opsional)</Label>
                <Input id="url" v-model="f.url" type="url" :placeholder="contohUrl" />
            </div>
            <div class="grid gap-2">
                <Label for="lokasi">Lokasi</Label>
                <select id="lokasi" v-model="f.lokasi" class="h-9 rounded-md border border-input bg-transparent px-3 text-sm">
                    <option v-for="(nama, id) in pilihanLokasi" :key="id" :value="id">{{ nama }}</option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="bahasa">Bahasa</Label>
                <select id="bahasa" v-model="f.bahasa" class="h-9 rounded-md border border-input bg-transparent px-3 text-sm">
                    <option v-for="(nama, id) in pilihanBahasa" :key="id" :value="id">{{ nama }}</option>
                </select>
            </div>
            <div class="md:col-span-2">
                <Button type="submit" :disabled="memuat || !siap || (!f.kata.trim() && !f.url.trim())">{{ memuat ? 'Mencari…' : 'Cari ide kata kunci' }}</Button>
            </div>
        </form>

        <p v-if="galat" class="text-sm text-destructive" role="alert">{{ galat }}</p>

        <section v-if="hasil" class="space-y-3">
            <div class="flex flex-wrap items-end gap-3 text-sm">
                <div class="grid gap-1">
                    <Label for="saring" class="text-xs">Saring</Label>
                    <Input id="saring" v-model="saring" placeholder="mis. murah" class="h-8 w-44" />
                </div>
                <div class="grid gap-1">
                    <Label for="min" class="text-xs">Volume minimal</Label>
                    <Input id="min" v-model.number="minVolume" type="number" min="0" step="10" class="h-8 w-28" />
                </div>
                <label class="flex h-8 items-center gap-2">
                    <input v-model="sembunyikanDipetakan" type="checkbox" class="size-4 accent-brand-700" />
                    Sembunyikan yang sudah di Keyword Mapping
                </label>
                <span class="ml-auto text-muted-foreground tabular-nums">{{ baris.length }} dari {{ hasil.length }} ide</span>
                <Button size="sm" variant="outline" :disabled="!baris.length" @click="unduhCsv">Unduh CSV</Button>
            </div>

            <DataTable>
                <thead>
                    <tr>
                        <th scope="col" :aria-sort="ariaUrut('kata')">
                            <button type="button" class="font-semibold" @click="urutkan('kata')">Kata kunci{{ tandaUrut('kata') }}</button>
                        </th>
                        <th scope="col" class="text-right" :aria-sort="ariaUrut('volume')">
                            <button type="button" class="font-semibold" @click="urutkan('volume')">Volume/bln{{ tandaUrut('volume') }}</button>
                        </th>
                        <th scope="col">Tren 12 bln</th>
                        <th scope="col" :aria-sort="ariaUrut('indeks')">
                            <button type="button" class="font-semibold" @click="urutkan('indeks')">Persaingan{{ tandaUrut('indeks') }}</button>
                        </th>
                        <th scope="col" class="text-right" :aria-sort="ariaUrut('cpc_tinggi')">
                            <button type="button" class="font-semibold" @click="urutkan('cpc_tinggi')">CPC atas halaman{{ tandaUrut('cpc_tinggi') }}</button>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in baris" :key="r.kata">
                        <th scope="row" class="font-normal text-ink">
                            {{ r.kata }}
                            <span v-if="r.dipetakan" class="ml-1 rounded bg-brand-50 px-1.5 py-0.5 text-xs text-brand-700">di mapping</span>
                        </th>
                        <td class="text-right font-semibold tabular-nums">{{ angka(r.volume) }}</td>
                        <td>
                            <svg v-if="r.tren.length > 1" width="60" height="18" viewBox="0 0 60 18" role="img" :aria-label="`Tren: ${ringkasTren(r.tren)}`">
                                <title>{{ ringkasTren(r.tren) }}</title>
                                <polyline :points="garis(r.tren)" fill="none" stroke="currentColor" stroke-width="1.5" class="text-brand-700" />
                            </svg>
                        </td>
                        <td>
                            <span v-if="r.persaingan" :class="warnaPersaingan[r.persaingan]">{{ r.persaingan }}</span>
                            <span v-if="r.indeks != null" class="text-xs text-muted-foreground tabular-nums"> ({{ r.indeks }})</span>
                        </td>
                        <td class="text-right whitespace-nowrap tabular-nums">
                            <template v-if="r.cpc_rendah != null || r.cpc_tinggi != null">{{ rupiah(r.cpc_rendah ?? 0) }} – {{ rupiah(r.cpc_tinggi ?? 0) }}</template>
                            <span v-else class="text-muted-foreground">-</span>
                        </td>
                    </tr>
                    <tr v-if="!baris.length">
                        <td colspan="5" class="text-center text-muted-foreground">Tidak ada ide yang cocok.</td>
                    </tr>
                </tbody>
            </DataTable>
            <p class="text-xs text-muted-foreground">
                Volume = rata-rata pencarian bulanan 12 bulan terakhir (Google membulatkan). CPC = kisaran tawaran untuk tampil di atas halaman. Hasil disimpan 24 jam.
            </p>
        </section>
    </div>
</template>
