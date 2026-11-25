<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    Activity,
    AppWindow,
    BookOpen,
    Bot,
    CalendarClock,
    Globe,
    Package,
    Plus,
    Server,
    ServerCog,
    Settings2,
    Tag,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import DataTable from '@/components/cloud/DataTable.vue';
import PageHeader from '@/components/cloud/PageHeader.vue';
import StatusBadge from '@/components/cloud/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { rupiah, tanggal } from '@/lib/format';
import customer from '@/routes/customer';
import katalog from '@/routes/katalog';
import layananRoute from '@/routes/layanan';
import tagihanRoute from '@/routes/tagihan';
import type { Layanan, Tagihan } from '@/types';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: customer.dashboard() }] },
});
const props = defineProps<{
    layanan: Layanan[];
    tagihan: Tagihan[];
    saldo: number;
    token_ai: { id: number; label: string; terpakai: number; kuota: number; persen: number }[];
}>();
const angka = (n: number) => n.toLocaleString('id-ID');
const page = usePage();
const namaDepan = computed(() => page.props.auth.user?.name?.split(' ')[0] ?? '');
const waLink = (teks: string) => `https://wa.me/${page.props.whatsapp}?text=${encodeURIComponent(`${teks} -vc`)}`;

// Ringkasan dihitung dari data yang sudah ada di halaman (tanpa angka karangan).
const aktif = computed(() => props.layanan.filter((l) => l.status === 'aktif').length);
const totalTagihan = computed(() => props.tagihan.reduce((n, t) => n + t.total, 0));
const jatuhTempoBerikut = computed(
    () =>
        props.layanan
            .filter((l) => l.jatuh_tempo && ['aktif', 'ditangguhkan'].includes(l.status))
            .sort((a, b) => a.jatuh_tempo!.localeCompare(b.jatuh_tempo!))[0] ?? null,
);
// Layanan yang butuh tindakan pelanggan atau sedang diproses.
const bermasalah = computed(() => props.layanan.filter((l) => ['ditangguhkan', 'gagal'].includes(l.status)));
const disiapkan = computed(() => props.layanan.filter((l) => l.status === 'disiapkan'));

const kategori = [
    { judul: 'App 1-Klik', isi: 'n8n, WordPress, WAHA, dan app lain siap pakai tanpa setup server.', url: 'app', ikon: AppWindow },
    { judul: 'Cloud Server', isi: 'VPS dengan akses root penuh untuk Anda kelola sendiri.', url: 'cloud', ikon: Server },
    { judul: 'Managed Server', isi: 'Server dikelola tim Velocity Developer: setup, update, dan pemantauan.', url: 'managed-cloud', ikon: ServerCog },
    { judul: 'Shared Hosting', isi: 'Hosting website dengan panel DirectAdmin, mulai 1 GB, bayar tahunan.', url: 'hosting', ikon: Globe },
    { judul: 'Token AI', isi: 'API model AI format OpenAI dengan saldo prabayar tanpa masa berlaku.', url: 'ai', ikon: Bot },
];
</script>

<template>
    <Head title="Dashboard" />
    <div class="space-y-8 p-4 md:p-6">
        <PageHeader
            :title="namaDepan ? `Halo, ${namaDepan}` : 'Dashboard'"
            description="Ringkasan layanan dan tagihan Anda."
        >
            <div class="flex flex-wrap gap-2">
                <Button as-child variant="outline"
                    ><Link href="/panduan"><BookOpen aria-hidden="true" />Panduan</Link></Button
                >
                <Button as-child
                    ><Link :href="katalog.index()"
                        ><Plus aria-hidden="true" />Pesan Layanan</Link
                    ></Button
                >
            </div>
        </PageHeader>

        <!-- Pengguna baru: arahkan langsung ke kategori, bukan tabel kosong. -->
        <section v-if="!layanan.length" class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold text-ink">Mulai dari mana?</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Anda belum punya layanan. Pilih yang paling sesuai kebutuhan Anda.
                </p>
            </div>
            <ul class="grid gap-3 sm:grid-cols-2">
                <li v-for="k in kategori" :key="k.url">
                    <Link
                        :href="katalog.index(k.url)"
                        class="flex h-full gap-4 rounded-lg border border-border p-4 transition-colors hover:border-brand-600 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <component :is="k.ikon" class="mt-0.5 size-5 shrink-0 text-brand-700" aria-hidden="true" />
                        <span>
                            <span class="block font-semibold text-ink">{{ k.judul }}</span>
                            <span class="mt-1 block text-sm text-muted-foreground">{{ k.isi }}</span>
                        </span>
                    </Link>
                </li>
            </ul>
            <p class="text-sm text-muted-foreground">
                Masih ragu?
                <a
                    :href="waLink('Halo, saya mau konsultasi layanan Velocity Data Center.')"
                    target="_blank"
                    rel="noopener"
                    class="font-semibold text-brand-700 hover:text-brand-600"
                    >Konsultasi via WhatsApp</a
                >.
            </p>
        </section>

        <template v-else>
            <dl class="grid divide-y divide-border rounded-lg border border-border lg:grid-cols-4 lg:divide-x lg:divide-y-0">
                <div class="p-4">
                    <dt class="text-sm text-muted-foreground">Layanan aktif</dt>
                    <dd class="font-heading mt-1 text-2xl font-bold text-ink tabular-nums">
                        {{ aktif }}<span class="text-sm font-normal text-muted-foreground"> dari {{ layanan.length }}</span>
                    </dd>
                </div>
                <div class="p-4">
                    <dt class="text-sm text-muted-foreground">Belum dibayar</dt>
                    <dd class="font-heading mt-1 text-2xl font-bold tabular-nums" :class="tagihan.length ? 'text-amber-700' : 'text-ink'">
                        {{ rupiah(totalTagihan) }}
                    </dd>
                    <dd v-if="tagihan.length" class="text-xs text-muted-foreground">{{ tagihan.length }} tagihan</dd>
                </div>
                <div class="p-4">
                    <dt class="text-sm text-muted-foreground">Jatuh tempo berikutnya</dt>
                    <template v-if="jatuhTempoBerikut">
                        <dd class="font-heading mt-1 text-2xl font-bold text-ink">{{ tanggal(jatuhTempoBerikut.jatuh_tempo) }}</dd>
                        <dd class="truncate text-xs text-muted-foreground">{{ jatuhTempoBerikut.label }}</dd>
                    </template>
                    <dd v-else class="font-heading mt-1 text-2xl font-bold text-ink">-</dd>
                </div>
                <div class="p-4">
                    <dt class="text-sm text-muted-foreground">Saldo</dt>
                    <dd class="font-heading mt-1 text-2xl font-bold text-ink tabular-nums">{{ rupiah(saldo) }}</dd>
                </div>
            </dl>

            <section
                v-if="tagihan.length"
                class="rounded-lg border border-amber-200 bg-amber-50 p-5"
            >
                <h2 class="font-semibold text-ink">
                    {{ tagihan.length }} tagihan menunggu pembayaran
                </h2>
                <ul class="mt-3 divide-y divide-amber-200">
                    <li
                        v-for="t in tagihan"
                        :key="t.id"
                        class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm"
                    >
                        <span>
                            <strong class="text-ink">{{ t.layanan }}</strong>
                            <span class="block text-muted-foreground">{{ t.nomor }} · jatuh tempo {{ tanggal(t.jatuh_tempo) }}</span>
                        </span>
                        <Button as-child size="sm"
                            ><Link :href="tagihanRoute.show(t.id)">Bayar {{ rupiah(t.total) }}</Link></Button
                        >
                    </li>
                </ul>
            </section>

            <section v-if="bermasalah.length" class="rounded-lg border border-red-200 bg-red-50 p-5">
                <h2 class="font-semibold text-ink">Layanan perlu perhatian</h2>
                <ul class="mt-3 divide-y divide-red-200">
                    <li
                        v-for="l in bermasalah"
                        :key="l.id"
                        class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm"
                    >
                        <span>
                            <strong class="text-ink">{{ l.label }}</strong>
                            <span class="block text-muted-foreground">
                                {{ l.status === 'ditangguhkan' ? 'Ditangguhkan. Lunasi tagihan agar layanan aktif lagi.' : 'Gagal disiapkan. Hubungi kami agar segera dibantu.' }}
                            </span>
                        </span>
                        <Link
                            v-if="l.status === 'ditangguhkan'"
                            :href="layananRoute.show(l.id)"
                            class="font-semibold text-brand-700 hover:text-brand-600"
                            >Lihat layanan</Link
                        >
                        <a
                            v-else
                            :href="waLink(`Halo, layanan ${l.label} gagal disiapkan.`)"
                            target="_blank"
                            rel="noopener"
                            class="font-semibold text-brand-700 hover:text-brand-600"
                            >Hubungi via WhatsApp</a
                        >
                    </li>
                </ul>
            </section>

            <section v-if="token_ai.length" class="rounded-lg border border-border p-5">
                <h2 class="font-semibold text-ink">Pemakaian token AI</h2>
                <ul class="mt-3 space-y-4">
                    <li v-for="t in token_ai" :key="t.id" class="text-sm">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-4">
                            <Link :href="layananRoute.show(t.id)" class="font-semibold text-ink hover:text-brand-700">{{ t.label }}</Link>
                            <span class="text-muted-foreground tabular-nums">
                                <strong class="text-ink">{{ t.persen.toLocaleString('id-ID') }}%</strong>
                                · {{ angka(t.terpakai) }} dari {{ angka(t.kuota) }} token
                            </span>
                        </div>
                        <div
                            class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100"
                            role="progressbar"
                            :aria-valuenow="t.persen"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            :aria-label="`Pemakaian token ${t.label}`"
                        >
                            <div
                                class="h-full rounded-full"
                                :class="t.persen >= 90 ? 'bg-red-600' : t.persen >= 75 ? 'bg-amber-500' : 'bg-brand-600'"
                                :style="{ width: `${Math.min(100, t.persen)}%` }"
                            />
                        </div>
                    </li>
                </ul>
            </section>

            <p v-if="disiapkan.length" class="rounded-md bg-brand-50 px-4 py-3 text-sm text-ink">
                {{ disiapkan.map((l) => l.label).join(', ') }} sedang disiapkan. Akses akan tampil di halaman layanan setelah siap.
            </p>

            <section>
                <div class="mb-3 flex items-baseline justify-between gap-4">
                    <h2 class="text-lg font-semibold text-ink">Layanan</h2>
                    <Link :href="layananRoute.index()" class="text-sm font-semibold text-brand-700 hover:text-brand-600">Lihat semua</Link>
                </div>
                <DataTable>
                    <thead>
                        <tr>
                            <th>
                                <span class="inline-flex items-center gap-1.5"
                                    ><Tag class="size-4 text-brand-700" aria-hidden="true" />Nama</span
                                >
                            </th>
                            <th>
                                <span class="inline-flex items-center gap-1.5"
                                    ><Package class="size-4 text-brand-700" aria-hidden="true" />Paket</span
                                >
                            </th>
                            <th>
                                <span class="inline-flex items-center gap-1.5"
                                    ><Globe class="size-4 text-brand-700" aria-hidden="true" />Alamat</span
                                >
                            </th>
                            <th>
                                <span class="inline-flex items-center gap-1.5"
                                    ><Activity class="size-4 text-brand-700" aria-hidden="true" />Status</span
                                >
                            </th>
                            <th>
                                <span class="inline-flex items-center gap-1.5"
                                    ><CalendarClock class="size-4 text-brand-700" aria-hidden="true" />Jatuh tempo</span
                                >
                            </th>
                            <th class="text-right">
                                <span class="inline-flex items-center gap-1.5"
                                    ><Wallet class="size-4 text-brand-700" aria-hidden="true" />Harga</span
                                >
                            </th>
                            <th class="text-right"><span class="sr-only">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="l in layanan" :key="l.id">
                            <td>
                                <Link
                                    :href="layananRoute.show(l.id)"
                                    class="font-semibold text-brand-700 hover:text-brand-600"
                                    >{{ l.label }}</Link
                                >
                            </td>
                            <td>{{ l.kategori_label }} · {{ l.paket }}</td>
                            <td>
                                <a
                                    v-if="l.url"
                                    :href="l.url"
                                    target="_blank"
                                    rel="noopener"
                                    class="text-brand-700 hover:text-brand-600"
                                    >{{ l.url.replace('https://', '') }}</a
                                ><span v-else class="text-muted-foreground">-</span>
                            </td>
                            <td>
                                <StatusBadge
                                    :status="l.status"
                                    :label="l.status_label"
                                />
                            </td>
                            <td>{{ tanggal(l.jatuh_tempo) }}</td>
                            <td class="text-right">
                                {{ rupiah(l.harga) }}<span class="text-muted-foreground">{{ l.kategori === 'ai' ? '/jt token' : l.kategori === 'hosting' ? '/thn' : l.kategori === 'domain' ? '' : '/bln' }}</span>
                            </td>
                            <td class="text-right">
                                <Button size="sm" variant="outline" as-child>
                                    <Link :href="layananRoute.show(l.id)"><Settings2 aria-hidden="true" />Kelola</Link>
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </DataTable>
            </section>
        </template>
    </div>
</template>
