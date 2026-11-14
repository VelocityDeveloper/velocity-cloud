<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import { Eye, EyeOff } from '@lucide/vue';
import { computed, onUnmounted, ref } from 'vue';
import LayananController from '@/actions/App/Http/Controllers/LayananController';
import DataTable from '@/components/cloud/DataTable.vue';
import PageHeader from '@/components/cloud/PageHeader.vue';
import StatusBadge from '@/components/cloud/StatusBadge.vue';
import TombolSalin from '@/components/cloud/TombolSalin.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { rupiah, tanggal } from '@/lib/format';
import layananRoute from '@/routes/layanan';
import tagihanRoute from '@/routes/tagihan';
import type { LayananLengkap, Tagihan } from '@/types';

type Pod = {
    status?: string;
    containers?: { name: string; state: string }[];
    pemakaian?: { container: string; cpu: string; memori: string }[];
    galat?: string;
};
const props = defineProps<{
    layanan: LayananLengkap;
    tagihan: Tagihan[];
    pod?: Pod | null;
    log?: Record<string, string>;
    perangkat?: { menunggu?: Perangkat[]; disetujui?: Perangkat[]; galat?: string } | null;
    token?: Token | null;
}>();
type Token = {
    endpoint: string;
    model: string | null;
    saldo: number;
    terpakai: number;
    pemakaian: { model: string; prompt: number; completion: number; total: number; created_at: string }[];
};
const angka = (n: number) => n.toLocaleString('id-ID');
const contohCurl = computed(() =>
    props.token
        ? `curl ${props.token.endpoint}/chat/completions \\
  -H "Authorization: Bearer API_KEY_ANDA" \\
  -H "Content-Type: application/json" \\
  -d '{"model":"${props.token.model}","messages":[{"role":"user","content":"Halo"}]}'`
        : '',
);
type Perangkat = { id: string | null; nama: string; ip: string | null; waktu: string | null };
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Layanan Saya', href: layananRoute.index() },
            { title: 'Detail', href: '#' },
        ],
    },
});

const lihatSandi = ref<Record<string, boolean>>({});
const namaKredensial: Record<string, string> = {
    pengguna: 'Pengguna',
    sandi: 'Sandi / API key',
    sandi_db: 'Sandi database',
    api_key: 'API key',
};
const namaSpek: Record<string, string> = {
    cpu: 'CPU',
    ram: 'RAM',
    disk: 'Storage',
    bandwidth: 'Bandwidth',
};
const spek = Object.entries(props.layanan.spek ?? {}).filter(
    ([k]) => k !== 'kategori_app',
);
const bisaAksi = props.layanan.kontrol && props.layanan.status === 'aktif';

// Status pod dari agent: Running = menyala; Exited/Stopped = mati; lainnya (Created/Degraded) = sedang berubah.
const menyala = computed(() => props.pod?.status === 'Running');
const statusPod = computed(() => {
    if (!props.pod) return { status: 'disiapkan', label: 'Memuat status…' };
    if (props.pod.galat) return { status: 'gagal', label: 'Status tidak terbaca' };
    if (menyala.value) return { status: 'aktif', label: 'Menyala' };
    if (['Exited', 'Stopped'].includes(props.pod.status ?? '')) return { status: 'nonaktif', label: 'Mati' };
    return { status: 'disiapkan', label: props.pod.status ?? '-' };
});
const pemakaian = computed(() => (props.pod?.pemakaian ?? []).filter((p) => !p.container.endsWith('-infra')));
const singkat = (nama: string) => nama.replace(/^svc-\d+-/, '');

// Perintah dikirim ke antrean; status dimuat ulang tiap 4 dtk selama ±40 dtk.
const sibuk = ref(false);
let detak: ReturnType<typeof setInterval> | undefined;
function pantau() {
    clearInterval(detak);
    let n = 0;
    detak = setInterval(() => {
        router.reload({ only: ['pod'] });
        if (++n >= 10) clearInterval(detak);
    }, 4000);
}
onUnmounted(() => clearInterval(detak));
function aksi(a: 'start' | 'stop' | 'restart') {
    sibuk.value = true;
    router.post(
        LayananController.aksi(props.layanan.id).url,
        { aksi: a },
        { preserveScroll: true, onFinish: () => { sibuk.value = false; pantau(); } },
    );
}
// Pairing OpenClaw: browser baru menunggu persetujuan; daftar dibaca lewat CLI di container (±10 dtk).
const memuatPerangkat = ref(false);
const sudahMuatPerangkat = ref(false);
function muatPerangkat() {
    memuatPerangkat.value = true;
    sudahMuatPerangkat.value = true;
    router.reload({ only: ['perangkat'], onFinish: () => (memuatPerangkat.value = false) });
}
function setujui(id: string) {
    memuatPerangkat.value = true;
    router.post(LayananController.setujuiPerangkat({ service: props.layanan.id, permintaan: id }).url, {}, {
        preserveScroll: true,
        onFinish: () => muatPerangkat(),
    });
}
const waktu = (w: string | null) => (w ? new Date(w).toLocaleString('id-ID') : '');

const lihatLog = ref(false);
const memuatLog = ref(false);
function muatLog() {
    lihatLog.value = true;
    memuatLog.value = true;
    router.reload({ only: ['log'], onFinish: () => (memuatLog.value = false) });
}
</script>

<template>
    <Head :title="layanan.label" />
    <div class="space-y-8 p-4 md:p-6">
        <PageHeader
            :title="layanan.label"
            :description="`${layanan.kategori_label} · ${layanan.paket}`"
        >
            <StatusBadge
                :status="layanan.status"
                :label="layanan.status_label"
            />
        </PageHeader>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-lg border border-border">
                <h2
                    class="border-b border-border px-5 py-3 font-semibold text-ink"
                >
                    Akses
                </h2>
                <dl class="divide-y divide-border text-sm">
                    <div
                        v-if="layanan.url"
                        class="grid grid-cols-[9rem_1fr] gap-2 px-5 py-3"
                    >
                        <dt class="text-muted-foreground">Alamat</dt>
                        <dd>
                            <a
                                :href="layanan.url"
                                target="_blank"
                                rel="noopener"
                                class="font-medium break-all text-brand-700 hover:text-brand-600"
                                >{{ layanan.url }}</a
                            >
                        </dd>
                    </div>
                    <div
                        v-else-if="layanan.host && layanan.host_port"
                        class="grid grid-cols-[9rem_1fr] gap-2 px-5 py-3"
                    >
                        <dt class="text-muted-foreground">Host</dt>
                        <dd class="font-mono">
                            {{ layanan.host }}:{{ layanan.host_port }}
                        </dd>
                    </div>
                    <template v-if="layanan.kredensial">
                        <div
                            v-for="(nilai, kunci) in layanan.kredensial"
                            :key="kunci"
                            class="grid grid-cols-[9rem_1fr] items-center gap-2 px-5 py-3"
                        >
                            <dt class="text-muted-foreground">
                                {{ namaKredensial[kunci] ?? kunci }}
                            </dt>
                            <dd class="flex items-start gap-2">
                                <span class="min-w-0 font-mono break-all">{{
                                    lihatSandi[kunci] ? nilai : '••••••••••••'
                                }}</span>
                                <button
                                    type="button"
                                    class="shrink-0 rounded p-0.5 text-muted-foreground hover:text-ink focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    :aria-label="`${lihatSandi[kunci] ? 'Sembunyikan' : 'Tampilkan'} ${namaKredensial[kunci] ?? kunci}`"
                                    :aria-pressed="!!lihatSandi[kunci]"
                                    @click="lihatSandi[kunci] = !lihatSandi[kunci]"
                                >
                                    <EyeOff v-if="lihatSandi[kunci]" class="size-4" aria-hidden="true" />
                                    <Eye v-else class="size-4" aria-hidden="true" />
                                </button>
                                <TombolSalin :teks="String(nilai)" :label="namaKredensial[kunci] ?? String(kunci)" ikon />
                            </dd>
                        </div>
                    </template>
                    <template v-if="token">
                        <div
                            v-for="[nama, nilai] in [['Base URL', token.endpoint], ['Model', token.model]].filter(([, v]) => v)"
                            :key="nama!"
                            class="grid grid-cols-[9rem_1fr] items-center gap-2 px-5 py-3"
                        >
                            <dt class="text-muted-foreground">{{ nama }}</dt>
                            <dd class="flex items-start gap-2">
                                <span class="min-w-0 font-mono break-all">{{ nilai }}</span>
                                <TombolSalin :teks="nilai!" :label="nama!" ikon />
                            </dd>
                        </div>
                    </template>
                    <div v-if="layanan.akses" class="px-5 py-3">
                        <p class="text-muted-foreground">
                            Info akses dari tim kami
                        </p>
                        <pre
                            class="mt-2 font-mono text-sm whitespace-pre-wrap text-ink"
                            >{{ layanan.akses }}</pre>
                    </div>
                    <p
                        v-if="layanan.status === 'menunggu_pembayaran'"
                        class="px-5 py-4 text-muted-foreground"
                    >
                        Layanan disiapkan setelah tagihan pertama lunas.
                    </p>
                    <p
                        v-else-if="layanan.status === 'disiapkan'"
                        class="px-5 py-4 text-muted-foreground"
                    >
                        {{
                            layanan.is_app
                                ? 'Container sedang dibuat. Muat ulang halaman ini beberapa saat lagi.'
                                : layanan.otomatis
                                  ? 'VPS sedang dibuat (±2-5 menit). Muat ulang halaman ini beberapa saat lagi.'
                                : 'Tim kami sedang menyiapkan server Anda. Info akses muncul di sini setelah siap.'
                        }}
                    </p>
                    <p
                        v-else-if="layanan.status === 'gagal'"
                        class="px-5 py-4 text-red-700"
                    >
                        Penyiapan gagal. Tim kami sudah diberi tahu dan akan
                        mengulanginya.
                    </p>
                    <p
                        v-else-if="layanan.status === 'ditangguhkan'"
                        class="px-5 py-4 text-red-700"
                    >
                        Layanan ditangguhkan karena tagihan belum dibayar.
                        Lunasi tagihan agar aktif kembali.
                    </p>
                </dl>
            </section>

            <section class="rounded-lg border border-border">
                <h2
                    class="border-b border-border px-5 py-3 font-semibold text-ink"
                >
                    Paket
                </h2>
                <dl class="divide-y divide-border text-sm tabular-nums">
                    <div class="flex justify-between px-5 py-3">
                        <dt class="text-muted-foreground">Harga</dt>
                        <dd class="font-semibold text-ink">
                            {{ rupiah(layanan.harga)
                            }}{{ token ? '/1 juta token' : '/bulan' }}
                        </dd>
                    </div>
                    <div v-if="!token" class="flex justify-between px-5 py-3">
                        <dt class="text-muted-foreground">Jatuh tempo</dt>
                        <dd>{{ tanggal(layanan.jatuh_tempo) }}</dd>
                    </div>
                    <div
                        v-for="[k, v] in spek"
                        :key="k"
                        class="flex justify-between px-5 py-3"
                    >
                        <dt class="text-muted-foreground">
                            {{ namaSpek[k] ?? k }}
                        </dt>
                        <dd>{{ v }}</dd>
                    </div>
                </dl>
            </section>
        </div>

        <section v-if="token && layanan.status === 'aktif'" class="rounded-lg border border-border">
            <h2 class="border-b border-border px-5 py-3 font-semibold text-ink">Token AI</h2>
            <div class="grid gap-6 px-5 py-4 text-sm lg:grid-cols-2">
                <div class="space-y-4">
                    <dl class="grid grid-cols-2 gap-4 tabular-nums">
                        <div>
                            <dt class="text-muted-foreground">Sisa saldo</dt>
                            <dd class="font-heading text-2xl font-bold" :class="token.saldo > 0 ? 'text-ink' : 'text-red-700'">
                                {{ angka(Math.max(0, token.saldo)) }}
                            </dd>
                            <dd class="text-xs text-muted-foreground">token</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Terpakai</dt>
                            <dd class="font-heading text-2xl font-bold text-ink">{{ angka(token.terpakai) }}</dd>
                            <dd class="text-xs text-muted-foreground">token</dd>
                        </div>
                    </dl>
                    <p class="text-muted-foreground">
                        Kompatibel OpenAI: isi Base URL dan API key di aplikasi atau SDK OpenAI. Input dan output dihitung sama.
                    </p>
                    <pre class="overflow-x-auto rounded-md bg-slate-950 p-3 font-mono text-xs leading-relaxed text-slate-100">{{ contohCurl }}</pre>
                </div>
                <div class="space-y-4">
                    <Form
                        v-bind="LayananController.topup.form(layanan.id)"
                        class="space-y-3 rounded-md border border-border p-4"
                        v-slot="{ errors, processing }"
                    >
                        <Label for="token_juta" class="font-semibold text-ink">Top-up saldo (juta token)</Label>
                        <div class="flex gap-2">
                            <Input id="token_juta" name="token_juta" type="number" min="1" :default-value="10" required class="w-32" />
                            <Button type="submit" :disabled="processing">Buat tagihan</Button>
                        </div>
                        <p class="text-xs text-muted-foreground">{{ rupiah(layanan.harga) }} per 1 juta token. Saldo bertambah setelah tagihan lunas.</p>
                        <InputError :message="errors.token_juta" />
                    </Form>
                    <div>
                        <p class="mb-2 font-semibold text-ink">Pemakaian terakhir</p>
                        <p v-if="!token.pemakaian.length" class="text-muted-foreground">Belum ada pemakaian.</p>
                        <table v-else class="w-full tabular-nums">
                            <thead class="text-xs text-muted-foreground">
                                <tr>
                                    <th class="py-1 text-left font-medium">Waktu</th>
                                    <th class="py-1 text-right font-medium">Input</th>
                                    <th class="py-1 text-right font-medium">Output</th>
                                    <th class="py-1 text-right font-medium">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(p, i) in token.pemakaian" :key="i" class="border-t border-border">
                                    <td class="py-1.5">{{ p.created_at }}</td>
                                    <td class="py-1.5 text-right">{{ angka(p.prompt) }}</td>
                                    <td class="py-1.5 text-right">{{ angka(p.completion) }}</td>
                                    <td class="py-1.5 text-right">{{ angka(p.total) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <section v-if="bisaAksi" class="rounded-lg border border-border">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-3">
                <div class="flex items-center gap-3">
                    <h2 class="font-semibold text-ink">{{ layanan.is_app ? 'Kontrol app' : 'Kontrol VPS' }}</h2>
                    <StatusBadge v-bind="statusPod" />
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        role="switch"
                        :aria-checked="menyala"
                        aria-label="Nyalakan atau matikan layanan"
                        :disabled="sibuk || !pod || !!pod.galat"
                        class="inline-flex items-center gap-2 text-sm font-semibold disabled:opacity-50"
                        @click="aksi(menyala ? 'stop' : 'start')"
                    >
                        <span class="relative inline-block h-6 w-11 rounded-full transition-colors" :class="menyala ? 'bg-brand-700' : 'bg-muted-foreground/30'">
                            <span class="absolute top-0.5 left-0.5 size-5 rounded-full bg-white transition-transform" :class="menyala ? 'translate-x-5' : ''" />
                        </span>
                        {{ menyala ? 'On' : 'Off' }}
                    </button>
                    <Button size="sm" variant="outline" :disabled="sibuk || !menyala" @click="aksi('restart')">Restart</Button>
                    <Button size="sm" variant="outline" @click="router.reload({ only: ['pod'] })">Segarkan</Button>
                </div>
            </div>
            <div class="space-y-4 px-5 py-4 text-sm">
                <p v-if="pod?.galat" class="text-red-700">{{ pod.galat }}</p>
                <table v-if="pemakaian.length" class="w-full tabular-nums">
                    <thead class="text-xs text-muted-foreground">
                        <tr><th class="py-1 text-left font-medium">Komponen</th><th class="py-1 text-right font-medium">CPU</th><th class="py-1 text-right font-medium">RAM</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in pemakaian" :key="p.container" class="border-t border-border">
                            <td class="py-1.5">{{ singkat(p.container) }}</td>
                            <td class="py-1.5 text-right">{{ p.cpu }}</td>
                            <td class="py-1.5 text-right">{{ p.memori.split(' / ')[0] }}</td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="layanan.is_app">
                    <Button size="sm" variant="outline" :disabled="memuatLog" @click="muatLog">{{ lihatLog ? 'Muat ulang log' : 'Lihat log' }}</Button>
                    <div v-if="lihatLog" class="mt-3 space-y-3">
                        <p v-if="memuatLog && !log" class="text-muted-foreground">Memuat log…</p>
                        <div v-for="(isi, nama) in log ?? {}" :key="nama">
                            <p class="mb-1 text-xs font-semibold text-muted-foreground">{{ singkat(String(nama)) }}</p>
                            <pre class="max-h-80 overflow-auto rounded-md bg-slate-950 p-3 font-mono text-xs leading-relaxed whitespace-pre-wrap text-slate-100 [overflow-wrap:anywhere]">{{ isi || '(kosong)' }}</pre>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section v-if="bisaAksi && layanan.pairing" class="rounded-lg border border-border">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-3">
                <h2 class="font-semibold text-ink">Perangkat</h2>
                <Button size="sm" variant="outline" :disabled="memuatPerangkat" @click="muatPerangkat">{{ memuatPerangkat ? 'Memuat…' : 'Cek permintaan' }}</Button>
            </div>
            <div class="space-y-4 px-5 py-4 text-sm">
                <p class="text-muted-foreground">
                    Buka alamat app, tempel sandi di kolom Gateway Token, lalu klik Connect. Browser baru akan menunggu persetujuan:
                    klik <strong class="text-ink">Cek permintaan</strong> di sini dan setujui browser Anda.
                </p>
                <p v-if="perangkat?.galat" class="text-red-700">{{ perangkat.galat }}</p>
                <template v-else-if="perangkat && sudahMuatPerangkat">
                    <p v-if="!perangkat.menunggu?.length" class="text-muted-foreground">Tidak ada permintaan yang menunggu.</p>
                    <ul v-else class="divide-y divide-border rounded-md border border-border">
                        <li v-for="d in perangkat.menunggu" :key="d.id ?? d.nama" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                            <div>
                                <p class="font-medium text-ink">{{ d.nama }}</p>
                                <p class="text-xs text-muted-foreground tabular-nums">{{ [d.ip, waktu(d.waktu)].filter(Boolean).join(' · ') }}</p>
                            </div>
                            <Button v-if="d.id" size="sm" :disabled="memuatPerangkat" @click="setujui(d.id)">Setujui</Button>
                        </li>
                    </ul>
                    <p v-if="perangkat.disetujui?.length" class="text-muted-foreground">{{ perangkat.disetujui.length }} perangkat sudah disetujui.</p>
                </template>
            </div>
        </section>

        <section>
            <h2 class="mb-3 text-lg font-semibold text-ink">Riwayat tagihan</h2>
            <DataTable
                :kosong="!tagihan.length"
                pesan-kosong="Belum ada tagihan."
            >
                <thead>
                    <tr>
                        <th>Nomor</th>
                        <th>Periode</th>
                        <th>Status</th>
                        <th class="text-right">Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="t in tagihan" :key="t.id">
                        <td>
                            <Link
                                :href="tagihanRoute.show(t.id)"
                                class="font-semibold text-brand-700 hover:text-brand-600"
                                >{{ t.nomor }}</Link
                            >
                        </td>
                        <td>{{ t.periode }}</td>
                        <td>
                            <StatusBadge
                                :status="t.status"
                                :label="t.status_label"
                            />
                        </td>
                        <td class="text-right">{{ rupiah(t.total) }}</td>
                    </tr>
                </tbody>
            </DataTable>
        </section>
    </div>
</template>
