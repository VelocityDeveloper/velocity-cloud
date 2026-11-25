<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PetaNode from '@/components/analitik/PetaNode.vue';
import PageHeader from '@/components/cloud/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { angka } from '@/lib/analitik';
import { NAMA_STATUS, WARNA } from '@/lib/petaKataKunci';
import type { Simpul, Tautan } from '@/lib/petaKataKunci';
import admin from '@/routes/admin';

const props = defineProps<{
    situs: string;
    peta: {
        diambil: string;
        galat: string | null;
        simpul: Simpul[];
        tautan: Tautan[];
        negatif: string[];
        disembunyikan: number;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin · SEO', href: admin.seo.index() },
            { title: 'Keyword Mapping', href: admin.seo.peta() },
        ],
    },
});

const situsLanding = computed(
    () => 'https://' + props.situs.replace('sc-domain:', ''),
);
const kata = computed(() =>
    props.peta.simpul.filter((s) => s.jenis === 'kata'),
);
const URUT = { target: 0, turunan: 1, gsc: 2, cadangan: 3 };
const tabel = computed(() =>
    [...kata.value].sort(
        (a, b) =>
            URUT[a.status!] - URUT[b.status!] ||
            (b.volume ?? 0) +
                (b.tayangan ?? 0) -
                ((a.volume ?? 0) + (a.tayangan ?? 0)),
    ),
);
const hitung = computed(() =>
    Object.fromEntries(
        Object.keys(URUT).map((k) => [
            k,
            kata.value.filter((s) => s.status === k).length,
        ]),
    ),
);
const dipilih = ref<Simpul | null>(null);
const anak = computed(() =>
    dipilih.value
        ? kata.value.filter((s) => s.induk?.includes(dipilih.value!.id))
        : [],
);
function pilih(id: string | null) {
    dipilih.value = kata.value.find((s) => s.id === id) ?? null;
}

// Fokus peta: null = ikhtisar (pilar + cabang); id = situs → kata kunci itu → seluruh cabangnya.
const fokus = ref<string | null>(null);
const pilar = computed(() =>
    kata.value
        .filter(
            (s) =>
                !s.induk?.length &&
                kata.value.some((c) => c.induk?.[0] === s.id),
        )
        .sort((a, b) => a.kata.localeCompare(b.kata)),
);

// Kata kunci negatif: disimpan di berkas yang juga dibaca Kang SEO; kata kunci yang memuat semua katanya hilang dari peta.
const negatifBaru = ref('');
function ubahNegatif(kata: string, hapus = false) {
    router.post(
        admin.seo.negatif().url,
        { kata, hapus },
        {
            preserveScroll: true,
            onSuccess: () => {
                negatifBaru.value = '';
                if (!hapus) dipilih.value = null;
            },
        },
    );
}
</script>

<template>
    <Head title="Admin · Keyword Mapping" />
    <div class="space-y-6 p-4 md:p-6">
        <PageHeader
            title="Keyword Mapping"
            description="Target kata kunci, turunannya, dan halaman tujuan. Dari riset Kang SEO + kata kunci yang sudah tampil di Google (90 hari)."
        >
            <Button
                variant="outline"
                size="sm"
                @click="
                    router.get(
                        admin.seo.peta().url,
                        { segarkan: 1 },
                        { preserveScroll: true, replace: true },
                    )
                "
                >Segarkan</Button
            >
        </PageHeader>

        <div
            v-if="peta.galat"
            class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800"
        >
            Data Google gagal diambil, peta hanya dari riset: {{ peta.galat }}
        </div>

        <div
            class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
        >
            <span
                v-for="k in ['target', 'turunan', 'cadangan', 'gsc']"
                :key="k"
                class="flex items-center gap-1.5"
            >
                <span
                    class="size-2.5 rounded-full"
                    :style="{ background: WARNA[k] }"
                />{{ NAMA_STATUS[k] }} ({{ hitung[k] }})
            </span>
            <span class="flex items-center gap-1.5"
                ><span
                    class="w-5 border-t-2 border-dashed border-brand-100"
                />Induk lain</span
            >
        </div>

        <div class="flex flex-wrap gap-2 text-xs">
            <button
                v-for="p in [{ id: null, kata: 'Semua' }, ...pilar]"
                :key="p.id ?? '#'"
                type="button"
                class="rounded-full border px-3 py-1"
                :class="
                    fokus === p.id
                        ? 'border-volt bg-volt/15 font-semibold text-volt-700'
                        : 'border-border text-muted-foreground hover:border-brand-600'
                "
                @click="fokus = p.id"
            >
                {{ p.kata }}
            </button>
        </div>

        <section
            class="grid items-start gap-4"
            :class="dipilih ? 'lg:grid-cols-[minmax(0,1fr)_18rem]' : ''"
        >
            <PetaNode
                :simpul="peta.simpul"
                :dipilih="dipilih?.id ?? null"
                :fokus="fokus"
                @pilih="pilih"
            />
            <aside
                v-if="dipilih"
                class="rounded-xl border border-border bg-card p-4 text-sm lg:sticky lg:top-4"
            >
                <div class="flex items-start justify-between gap-2">
                    <p class="font-semibold text-foreground">
                        {{ dipilih.kata }}
                    </p>
                    <button
                        type="button"
                        class="text-muted-foreground hover:text-foreground"
                        aria-label="Tutup"
                        @click="dipilih = null"
                    >
                        ✕
                    </button>
                </div>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ NAMA_STATUS[dipilih.status!]
                    }}<template v-if="dipilih.niat">
                        · niat {{ dipilih.niat }}</template
                    >
                </p>
                <dl class="mt-3 grid grid-cols-2 gap-2 text-xs">
                    <div>
                        <dt class="text-muted-foreground">Volume/bln</dt>
                        <dd class="tabular-nums">
                            {{ dipilih.volume ?? '-' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">KD</dt>
                        <dd class="tabular-nums">{{ dipilih.kd ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Tayangan 90h</dt>
                        <dd class="tabular-nums">
                            {{ angka(dipilih.tayangan ?? 0) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Klik · posisi</dt>
                        <dd class="tabular-nums">
                            {{ angka(dipilih.klik ?? 0) }} ·
                            {{ dipilih.posisi ?? '-' }}
                        </dd>
                    </div>
                </dl>
                <p class="mt-3 text-xs text-muted-foreground">Halaman</p>
                <a
                    v-if="dipilih.url"
                    :href="situsLanding + dipilih.url"
                    target="_blank"
                    rel="noopener"
                    class="break-all text-brand-700 underline"
                    >{{ dipilih.url }}</a
                >
                <p v-else class="text-xs">Belum ada halaman.</p>
                <template v-if="dipilih.induk?.length">
                    <p class="mt-3 text-xs text-muted-foreground">Induk</p>
                    <p>{{ dipilih.induk.join(', ') }}</p>
                </template>
                <template v-if="anak.length">
                    <p class="mt-3 text-xs text-muted-foreground">
                        Cabang ({{ anak.length }})
                    </p>
                    <ul class="list-inside list-disc">
                        <li v-for="a in anak" :key="a.id">{{ a.kata }}</li>
                    </ul>
                </template>
                <p
                    v-if="dipilih.catatan"
                    class="mt-3 text-xs text-muted-foreground"
                >
                    Catatan riset: {{ dipilih.catatan }}
                </p>
                <Button
                    v-if="anak.length && fokus !== dipilih.id"
                    variant="outline"
                    size="sm"
                    class="mt-4 w-full"
                    @click="fokus = dipilih.id"
                >
                    Fokus ke cabang ini
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    class="mt-2 w-full text-red-700 hover:text-red-800"
                    @click="ubahNegatif(dipilih.kata)"
                >
                    Negatifkan kata kunci ini
                </Button>
            </aside>
        </section>

        <section class="rounded-xl border border-border bg-card p-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold text-foreground">
                    Kata kunci negatif ({{ peta.negatif.length }})
                </h2>
                <p class="text-xs text-muted-foreground">
                    Kata kunci yang memuat semua katanya disembunyikan ({{
                        peta.disembunyikan
                    }}) dan tidak dipakai Kang SEO.
                </p>
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
                <span
                    v-for="n in peta.negatif"
                    :key="n"
                    class="inline-flex items-center gap-1 rounded-full border border-red-200 bg-red-50 py-0.5 pr-1 pl-2.5 text-xs text-red-800"
                >
                    {{ n }}
                    <button
                        type="button"
                        class="rounded-full px-1 hover:bg-red-100"
                        :aria-label="`Batalkan negatif ${n}`"
                        @click="ubahNegatif(n, true)"
                    >
                        ✕
                    </button>
                </span>
                <span
                    v-if="!peta.negatif.length"
                    class="text-xs text-muted-foreground"
                    >Belum ada.</span
                >
            </div>
            <form
                class="mt-3 flex max-w-md gap-2"
                @submit.prevent="negatifBaru.trim() && ubahNegatif(negatifBaru)"
            >
                <input
                    v-model="negatifBaru"
                    maxlength="100"
                    placeholder="mis. vps windows"
                    aria-label="Kata kunci negatif baru"
                    class="h-8 min-w-0 flex-1 rounded-md border border-input bg-transparent px-3 text-sm"
                />
                <Button type="submit" variant="outline" size="sm"
                    >Negatifkan</Button
                >
            </form>
        </section>

        <section
            class="overflow-x-auto rounded-lg border border-border bg-white"
        >
            <table class="w-full text-sm">
                <thead
                    class="border-b border-border text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="px-4 py-2 font-medium">Kata kunci</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th class="px-4 py-2 font-medium">Induk</th>
                        <th class="px-4 py-2 font-medium">Halaman</th>
                        <th class="px-4 py-2 text-right font-medium">Volume</th>
                        <th class="px-4 py-2 text-right font-medium">KD</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Tayangan
                        </th>
                        <th class="px-4 py-2 text-right font-medium">Klik</th>
                        <th class="px-4 py-2 text-right font-medium">Posisi</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="s in tabel"
                        :key="s.id"
                        class="border-b border-border last:border-0"
                    >
                        <td class="px-4 py-2 font-medium text-ink">
                            {{ s.kata }}
                        </td>
                        <td class="px-4 py-2">
                            <span class="inline-flex items-center gap-1.5"
                                ><span
                                    class="size-2 rounded-full"
                                    :style="{ background: WARNA[s.status!] }"
                                />{{ NAMA_STATUS[s.status!] }}</span
                            >
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">
                            {{ s.induk?.join(', ') || '-' }}
                        </td>
                        <td class="px-4 py-2">
                            <a
                                v-if="s.url"
                                :href="situsLanding + s.url"
                                target="_blank"
                                rel="noopener"
                                class="text-brand-700 underline"
                                >{{ s.url }}</a
                            ><span v-else class="text-muted-foreground">-</span>
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ s.volume ?? '-' }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ s.kd ?? '-' }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ angka(s.tayangan ?? 0) }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ angka(s.klik ?? 0) }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ s.posisi ?? '-' }}
                        </td>
                    </tr>
                    <tr v-if="!tabel.length">
                        <td
                            colspan="9"
                            class="px-4 py-6 text-center text-muted-foreground"
                        >
                            Belum ada kata kunci. Riset Kang SEO kosong dan
                            Google belum menampilkan situs.
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
        <p class="text-xs text-muted-foreground">
            Cabang dari kolom Induk riset Kang SEO + otomatis (kata kunci pendek
            jadi induk kata kunci panjang yang memuat semua katanya). Diambil
            {{ new Date(peta.diambil).toLocaleString('id-ID') }}, disimpan 3
            jam.
        </p>
    </div>
</template>
