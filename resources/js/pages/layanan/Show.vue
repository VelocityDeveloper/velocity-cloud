<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import {
    Check,
    Eye,
    EyeOff,
    Power,
    Receipt,
    RefreshCw,
    RotateCw,
    Save,
    ScrollText,
    Search,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import LayananController from '@/actions/App/Http/Controllers/LayananController';
import GrafikTren from '@/components/analitik/GrafikTren.vue';
import AnalitikVps from '@/components/cloud/AnalitikVps.vue';
import type { TitikMetrik } from '@/components/cloud/AnalitikVps.vue';
import DataTable from '@/components/cloud/DataTable.vue';
import PageHeader from '@/components/cloud/PageHeader.vue';
import StatusBadge from '@/components/cloud/StatusBadge.vue';
import TombolSalin from '@/components/cloud/TombolSalin.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { rupiah, tanggal } from '@/lib/format';
import domainRoute from '@/routes/domain';
import layananRoute from '@/routes/layanan';
import tiketRoute from '@/routes/tiket';
import tagihanRoute from '@/routes/tagihan';
import type { LayananLengkap, Tagihan } from '@/types';

type Pod = {
    status?: string;
    containers?: { name: string; state: string }[];
    pemakaian?: { container: string; cpu: string; memori: string }[];
    galat?: string;
    info?: Record<string, string>;
    metrik?: {
        waktu: number;
        cpu_ns: number;
        ram: number;
        ram_total: number;
        proses: number;
        rx: number;
        tx: number;
    } | null;
};
const props = defineProps<{
    layanan: LayananLengkap;
    tagihan: Tagihan[];
    pod?: Pod | null;
    log?: Record<string, string>;
    perangkat?: {
        menunggu?: Perangkat[];
        disetujui?: Perangkat[];
        galat?: string;
    } | null;
    token?: Token | null;
    riwayat?: { rentang: string; titik: TitikMetrik[] };
    domain_saya: string[];
}>();

// Custom domain: pilih domain yang dibeli di VDC (+ subdomain opsional) atau ketik domain dari luar.
const domainVdcAwal = props.domain_saya.find(
    (d) =>
        props.layanan.custom_domain === d ||
        props.layanan.custom_domain?.endsWith('.' + d),
);
const sumberDomain = ref<'vdc' | 'luar'>(
    domainVdcAwal || (!props.layanan.custom_domain && props.domain_saya.length)
        ? 'vdc'
        : 'luar',
);
const domainVdc = ref(domainVdcAwal ?? props.domain_saya[0] ?? '');
const subVdc = ref(
    domainVdcAwal
        ? (props.layanan.custom_domain ?? '').slice(
              0,
              -(domainVdcAwal.length + 1),
          )
        : '',
);
const gabungVdc = computed(
    () =>
        (subVdc.value.trim()
            ? subVdc.value.trim().replace(/\.+$/, '') + '.'
            : '') + domainVdc.value,
);
const domainDariVdc = computed(() =>
    props.domain_saya.some(
        (d) =>
            props.layanan.custom_domain === d ||
            props.layanan.custom_domain?.endsWith('.' + d),
    ),
);
type Token = {
    endpoint: string;
    model: string | null;
    harga: number | null;
    harga_promo: number | null; // harga top-up saat promo berlaku
    harga_input: number | null;
    harga_cache: number | null;
    saldo: number;
    terpakai: number;
    tren: TrenToken[];
    tren_lalu: TrenToken[];
    pemakaian: {
        model: string;
        prompt: number;
        cache_hit: number;
        completion: number;
        potong: number;
        biaya: number;
        created_at: string;
    }[];
};
type TrenToken = { label: string; request: number; token: number };
const ukuranTren = ref<'token' | 'request'>('token');
const namaUkuranTren = { token: 'Token', request: 'Request' };
const angka = (n: number) => n.toLocaleString('id-ID');
const rupiahDesimal = (n: number) =>
    'Rp' + n.toLocaleString('id-ID', { maximumFractionDigits: 2 }); // biaya per request bisa di bawah Rp1
const contohCurl = computed(() =>
    props.token
        ? `curl ${props.token.endpoint}/chat/completions \\
  -H "Authorization: Bearer API_KEY_ANDA" \\
  -H "Content-Type: application/json" \\
  -d '{"model":"${props.token.model}","messages":[{"role":"user","content":"Halo"}]}'`
        : '',
);
type Perangkat = {
    id: string | null;
    nama: string;
    ip: string | null;
    waktu: string | null;
};
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
    if (props.pod.galat)
        return { status: 'gagal', label: 'Status tidak terbaca' };
    if (menyala.value) return { status: 'aktif', label: 'Menyala' };
    if (['Exited', 'Stopped'].includes(props.pod.status ?? ''))
        return { status: 'nonaktif', label: 'Mati' };
    return { status: 'disiapkan', label: props.pod.status ?? '-' };
});
const pemakaian = computed(() =>
    (props.pod?.pemakaian ?? []).filter((p) => !p.container.endsWith('-infra')),
);
const singkat = (nama: string) => nama.replace(/^svc-\d+-/, '');

// Status realtime: dimuat ulang tiap 5 dtk selama tab terlihat (pod/VM dari agent atau Incus). Tanpa tumpukan permintaan.
const sibuk = ref(false);
const terakhir = ref<Date | null>(null);
let detak: ReturnType<typeof setInterval> | undefined;
let memuatStatus = false;
function segarkan() {
    if (document.hidden || memuatStatus) return;
    memuatStatus = true;
    router.reload({
        only: ['pod'],
        onSuccess: () => (terakhir.value = new Date()),
        onFinish: () => (memuatStatus = false),
    });
}
// Analitik VPS: live = titik dari polling 5 dtk di browser (60 titik terakhir); rentang lain = sampel per menit dari server.
const punyaAnalitik = !props.layanan.is_app && bisaAksi;
const rentang = ref('live');
const memuatRiwayat = ref(false);
const live = ref<TitikMetrik[]>([]);
const vcpu = Math.max(
    1,
    parseInt(String(props.layanan.spek?.cpu ?? '1'), 10) || 1,
);
let lalu: NonNullable<Pod['metrik']> | null = null;
watch(
    () => props.pod?.metrik,
    (m) => {
        if (!m) return;
        const dt = lalu ? m.waktu - lalu.waktu : 0;
        if (lalu && dt <= 0) return;
        const laju = (a: number, b: number) =>
            lalu && dt > 0 && a >= b ? (a - b) / dt : null;
        live.value = [
            ...live.value,
            {
                t: m.waktu,
                cpu:
                    lalu && m.cpu_ns >= lalu.cpu_ns
                        ? Math.min(
                              100,
                              ((m.cpu_ns - lalu.cpu_ns) / (dt * vcpu * 1e9)) *
                                  100,
                          )
                        : null,
                ram: m.ram,
                ram_total: m.ram_total,
                proses: m.proses,
                rx: laju(m.rx, lalu?.rx ?? 0),
                tx: laju(m.tx, lalu?.tx ?? 0),
            },
        ].slice(-60);
        lalu = m;
    },
    { immediate: true },
);
const titikAnalitik = computed(() =>
    rentang.value === 'live'
        ? live.value
        : props.riwayat?.rentang === rentang.value
          ? props.riwayat.titik
          : [],
);
function pilihRentang(r: string) {
    rentang.value = r;
    if (r === 'live') return;
    memuatRiwayat.value = true;
    router.reload({
        only: ['riwayat'],
        data: { rentang: r },
        onFinish: () => (memuatRiwayat.value = false),
    });
}

onMounted(() => {
    if (bisaAksi) detak = setInterval(segarkan, 5000);
});
onUnmounted(() => clearInterval(detak));
function aksi(a: 'start' | 'stop' | 'restart') {
    sibuk.value = true;
    router.post(
        LayananController.aksi(props.layanan.id).url,
        { aksi: a },
        {
            preserveScroll: true,
            onFinish: () => {
                sibuk.value = false;
                segarkan();
            },
        },
    );
}
// Pairing OpenClaw: browser baru menunggu persetujuan; daftar dibaca lewat CLI di container (±10 dtk).
const memuatPerangkat = ref(false);
const sudahMuatPerangkat = ref(false);
function muatPerangkat() {
    memuatPerangkat.value = true;
    sudahMuatPerangkat.value = true;
    router.reload({
        only: ['perangkat'],
        onFinish: () => (memuatPerangkat.value = false),
    });
}
function setujui(id: string) {
    memuatPerangkat.value = true;
    router.post(
        LayananController.setujuiPerangkat({
            service: props.layanan.id,
            permintaan: id,
        }).url,
        {},
        {
            preserveScroll: true,
            onFinish: () => muatPerangkat(),
        },
    );
}
const waktu = (w: string | null) =>
    w ? new Date(w).toLocaleString('id-ID') : '';

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
                                    @click="
                                        lihatSandi[kunci] = !lihatSandi[kunci]
                                    "
                                >
                                    <EyeOff
                                        v-if="lihatSandi[kunci]"
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    <Eye
                                        v-else
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                </button>
                                <TombolSalin
                                    :teks="String(nilai)"
                                    :label="
                                        namaKredensial[kunci] ?? String(kunci)
                                    "
                                    ikon
                                />
                            </dd>
                        </div>
                    </template>
                    <template v-if="token">
                        <div
                            v-for="[nama, nilai] in [
                                ['Base URL', token.endpoint],
                                ['Model', token.model],
                            ].filter(([, v]) => v)"
                            :key="nama!"
                            class="grid grid-cols-[9rem_1fr] items-center gap-2 px-5 py-3"
                        >
                            <dt class="text-muted-foreground">{{ nama }}</dt>
                            <dd class="flex items-start gap-2">
                                <span class="min-w-0 font-mono break-all">{{
                                    nilai
                                }}</span>
                                <TombolSalin
                                    :teks="nilai!"
                                    :label="nama!"
                                    ikon
                                />
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
                                : layanan.is_hosting
                                  ? 'Akun hosting sedang dibuat (±1 menit). Muat ulang halaman ini beberapa saat lagi.'
                                  : layanan.is_domain
                                    ? 'Domain sedang didaftarkan ke registrar (±1 menit). Muat ulang halaman ini beberapa saat lagi.'
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
                            {{ rupiah(token ? token.harga : layanan.harga)
                            }}{{
                                token
                                    ? '/1 juta token output'
                                    : layanan.kategori === 'hosting'
                                      ? '/tahun'
                                      : layanan.kategori === 'domain'
                                        ? ''
                                        : '/bulan'
                            }}
                        </dd>
                    </div>
                    <div
                        v-if="token?.harga_input != null"
                        class="flex justify-between px-5 py-3"
                    >
                        <dt class="text-muted-foreground">Input · cache</dt>
                        <dd>
                            {{ rupiah(token.harga_input) }} ·
                            {{
                                rupiah(token.harga_cache ?? token.harga_input)
                            }}/1 juta token
                        </dd>
                    </div>
                    <div v-if="!token" class="flex justify-between px-5 py-3">
                        <dt class="text-muted-foreground">Jatuh tempo</dt>
                        <dd>{{ tanggal(layanan.jatuh_tempo) }}</dd>
                    </div>
                    <div
                        v-for="[nama, nilai, salin] in layanan.info"
                        :key="nama"
                        class="flex items-start justify-between gap-3 px-5 py-3"
                    >
                        <dt class="shrink-0 text-muted-foreground">
                            {{ nama }}
                        </dt>
                        <dd
                            class="flex min-w-0 items-start gap-2 text-right"
                            :class="salin ? 'font-mono' : ''"
                        >
                            <span class="break-all">{{ nilai }}</span>
                            <TombolSalin
                                v-if="salin"
                                :teks="nilai"
                                :label="nama"
                                ikon
                            />
                        </dd>
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

        <section
            v-if="token && layanan.status === 'aktif'"
            class="rounded-lg border border-border"
        >
            <h2 class="border-b border-border px-5 py-3 font-semibold text-ink">
                Token AI
            </h2>
            <div class="border-b border-border px-5 py-4">
                <div
                    class="mb-4 flex flex-wrap items-center justify-between gap-3"
                >
                    <div>
                        <h3 class="font-semibold text-ink">Grafik pemakaian</h3>
                        <p class="text-xs text-muted-foreground">
                            Per hari, 30 hari terakhir.
                        </p>
                    </div>
                    <div
                        class="flex rounded-md border border-border p-0.5 text-sm"
                        role="tablist"
                    >
                        <button
                            v-for="(nama, k) in namaUkuranTren"
                            :key="k"
                            type="button"
                            role="tab"
                            :aria-selected="ukuranTren === k"
                            class="rounded px-3 py-1"
                            :class="
                                ukuranTren === k
                                    ? 'bg-brand-700 text-white'
                                    : 'text-muted-foreground hover:text-ink'
                            "
                            @click="ukuranTren = k"
                        >
                            {{ nama }}
                        </button>
                    </div>
                </div>
                <GrafikTren
                    :label="token.tren.map((t) => t.label)"
                    :kini="token.tren.map((t) => t[ukuranTren])"
                    :lalu="token.tren_lalu.map((t) => t[ukuranTren])"
                    :nama="namaUkuranTren[ukuranTren]"
                />
            </div>
            <div class="grid gap-6 px-5 py-4 text-sm lg:grid-cols-2">
                <div class="space-y-4">
                    <dl class="grid grid-cols-2 gap-4 tabular-nums">
                        <div>
                            <dt class="text-muted-foreground">Sisa saldo</dt>
                            <dd
                                class="font-heading text-2xl font-bold"
                                :class="
                                    token.saldo > 0
                                        ? 'text-ink'
                                        : 'text-red-700'
                                "
                            >
                                {{ angka(Math.max(0, token.saldo)) }}
                            </dd>
                            <dd class="text-xs text-muted-foreground">
                                token<template v-if="token.saldo > 0">
                                    · setara
                                    {{
                                        rupiahDesimal(
                                            Math.floor(
                                                (token.saldo *
                                                    (token.harga ?? 0)) /
                                                    1_000_000,
                                            ),
                                        )
                                    }}</template
                                >
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Terpakai</dt>
                            <dd
                                class="font-heading text-2xl font-bold text-ink"
                            >
                                {{ angka(token.terpakai) }}
                            </dd>
                            <dd class="text-xs text-muted-foreground">token</dd>
                        </div>
                    </dl>
                    <p class="text-muted-foreground">
                        Kompatibel OpenAI: isi Base URL dan API key di aplikasi
                        atau SDK OpenAI.
                    </p>
                    <pre
                        class="overflow-x-auto rounded-md bg-slate-950 p-3 font-mono text-xs leading-relaxed text-slate-100"
                        >{{ contohCurl }}</pre>
                </div>
                <div class="space-y-4">
                    <Form
                        v-bind="LayananController.topup.form(layanan.id)"
                        class="space-y-3 rounded-md border border-border p-4"
                        v-slot="{ errors, processing }"
                    >
                        <Label for="token_juta" class="font-semibold text-ink"
                            >Top-up saldo (juta token)</Label
                        >
                        <div class="flex gap-2">
                            <Input
                                id="token_juta"
                                name="token_juta"
                                type="number"
                                min="1"
                                :default-value="10"
                                required
                                class="w-32"
                            />
                            <Button type="submit" :disabled="processing"
                                ><Receipt aria-hidden="true" />Buat
                                tagihan</Button
                            >
                        </div>
                        <p class="text-xs text-muted-foreground">
                            <template v-if="token.harga_promo != null"
                                ><s>{{ rupiah(token.harga) }}</s>
                                <strong class="text-brand-700">{{
                                    rupiah(token.harga_promo)
                                }}</strong>
                                per 1 juta token (harga promo).</template
                            ><template v-else
                                >{{ rupiah(token.harga) }} per 1 juta
                                token.</template
                            >
                            Saldo bertambah setelah tagihan lunas.
                            <template v-if="token.harga_input != null"
                                >Saldo dihitung setara token output: token input
                                dan cache memotong saldo lebih sedikit sesuai
                                harganya.</template
                            >
                        </p>
                        <InputError :message="errors.token_juta" />
                    </Form>
                </div>
            </div>
            <div class="border-t border-border px-5 py-4 text-sm">
                <h3 class="font-semibold text-ink">Detail pemakaian</h3>
                <p class="mb-3 text-xs text-muted-foreground">
                    20 request terakhir. Input termasuk token cache.<template
                        v-if="token.harga_input != null"
                    >
                        Harga per 1 juta token: input
                        {{ rupiah(token.harga_input) }}, cache
                        {{ rupiah(token.harga_cache ?? token.harga_input) }},
                        output {{ rupiah(token.harga) }}.</template
                    >
                </p>
                <p v-if="!token.pemakaian.length" class="text-muted-foreground">
                    Belum ada pemakaian.
                </p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[640px] tabular-nums">
                        <thead class="text-xs text-muted-foreground">
                            <tr>
                                <th class="py-1 text-left font-medium">
                                    Waktu
                                </th>
                                <th class="py-1 text-left font-medium">
                                    Model
                                </th>
                                <th class="py-1 text-right font-medium">
                                    Input
                                </th>
                                <th class="py-1 text-right font-medium">
                                    Cache
                                </th>
                                <th class="py-1 text-right font-medium">
                                    Output
                                </th>
                                <th class="py-1 text-right font-medium">
                                    Potong saldo
                                </th>
                                <th class="py-1 text-right font-medium">
                                    Biaya
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(p, i) in token.pemakaian"
                                :key="i"
                                class="border-t border-border"
                            >
                                <td class="py-1.5 whitespace-nowrap">
                                    {{ p.created_at }}
                                </td>
                                <td class="py-1.5 font-mono text-xs">
                                    {{ p.model }}
                                </td>
                                <td class="py-1.5 text-right">
                                    {{ angka(p.prompt) }}
                                </td>
                                <td
                                    class="py-1.5 text-right text-muted-foreground"
                                >
                                    {{ angka(p.cache_hit) }}
                                </td>
                                <td class="py-1.5 text-right">
                                    {{ angka(p.completion) }}
                                </td>
                                <td class="py-1.5 text-right">
                                    {{ angka(p.potong) }}
                                </td>
                                <td
                                    class="py-1.5 text-right font-medium text-ink"
                                >
                                    {{ rupiahDesimal(p.biaya) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section
            v-if="layanan.domain_didukung && layanan.status === 'aktif'"
            class="rounded-lg border border-border"
        >
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-3"
            >
                <h2 class="font-semibold text-ink">Custom domain</h2>
                <StatusBadge
                    v-if="layanan.custom_domain && layanan.domain_relay"
                    :status="
                        layanan.custom_domain_aktif ? 'aktif' : 'disiapkan'
                    "
                    :label="
                        layanan.custom_domain_aktif
                            ? 'DNS terhubung'
                            : 'Menunggu DNS'
                    "
                />
            </div>
            <div class="space-y-4 px-5 py-4 text-sm">
                <Form
                    v-bind="LayananController.domain.form(layanan.id)"
                    class="space-y-3"
                    v-slot="{ errors, processing }"
                >
                    <div
                        class="flex flex-wrap gap-2"
                        role="group"
                        aria-label="Sumber domain"
                    >
                        <Button
                            v-for="(nama, k) in {
                                vdc: 'Domain dari VDC',
                                luar: 'Domain dari luar',
                            }"
                            :key="k"
                            type="button"
                            size="sm"
                            :variant="
                                sumberDomain === k ? 'default' : 'outline'
                            "
                            :aria-pressed="sumberDomain === k"
                            @click="sumberDomain = k"
                        >
                            {{ nama }}
                        </Button>
                    </div>
                    <template v-if="sumberDomain === 'vdc'">
                        <template v-if="domain_saya.length">
                            <input
                                type="hidden"
                                name="domain"
                                :value="gabungVdc"
                            />
                            <div class="flex flex-wrap items-end gap-2">
                                <div class="grid gap-1">
                                    <Label for="sub_vdc" class="text-xs"
                                        >Subdomain (opsional)</Label
                                    >
                                    <Input
                                        id="sub_vdc"
                                        v-model="subVdc"
                                        placeholder="www"
                                        class="w-32"
                                    />
                                </div>
                                <div class="grid gap-1">
                                    <Label for="domain_vdc" class="text-xs"
                                        >Domain Anda di VDC</Label
                                    >
                                    <select
                                        id="domain_vdc"
                                        v-model="domainVdc"
                                        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                                    >
                                        <option
                                            v-for="d in domain_saya"
                                            :key="d"
                                            :value="d"
                                        >
                                            {{ d }}
                                        </option>
                                    </select>
                                </div>
                                <Button type="submit" :disabled="processing"
                                    ><Save aria-hidden="true" />Simpan</Button
                                >
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Akan dipasang: <code>{{ gabungVdc }}</code>
                            </p>
                        </template>
                        <p v-else class="text-muted-foreground">
                            Anda belum punya domain di VDC.
                            <Link
                                :href="domainRoute.index()"
                                class="text-brand-700 underline"
                                >Beli domain</Link
                            >
                            atau pilih <b>Domain dari luar</b>.
                        </p>
                    </template>
                    <template v-else>
                        <Label for="domain" class="font-semibold text-ink"
                            >Domain Anda</Label
                        >
                        <div class="flex flex-wrap gap-2">
                            <Input
                                id="domain"
                                name="domain"
                                placeholder="toko.contoh.com"
                                :default-value="
                                    domainDariVdc
                                        ? ''
                                        : (layanan.custom_domain ?? '')
                                "
                                class="max-w-xs"
                            />
                            <Button type="submit" :disabled="processing"
                                ><Save aria-hidden="true" />Simpan</Button
                            >
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Kosongkan lalu simpan untuk melepas domain.
                        </p>
                    </template>
                    <InputError :message="errors.domain" />
                </Form>
                <Button
                    v-if="layanan.custom_domain && sumberDomain === 'vdc'"
                    size="sm"
                    variant="ghost"
                    @click="
                        router.put(
                            LayananController.domain.url(layanan.id),
                            { domain: '' },
                            { preserveScroll: true },
                        )
                    "
                >
                    Lepas domain
                </Button>
                <template v-if="layanan.custom_domain">
                    <div
                        v-if="layanan.relay_ip"
                        class="space-y-1 rounded-md bg-muted/50 p-3"
                    >
                        <p class="font-semibold text-ink">
                            {{
                                domainDariVdc
                                    ? 'Atur DNS domain Anda'
                                    : 'Atur DNS di penyedia domain Anda'
                            }}
                        </p>
                        <p>
                            Buat <b>A record</b> untuk
                            <code>{{ layanan.custom_domain }}</code> ke
                            <code>{{ layanan.relay_ip }}</code>
                            <TombolSalin
                                :teks="layanan.relay_ip"
                                label="Salin IP"
                                ikon
                            />
                        </p>
                        <p
                            v-if="domainDariVdc"
                            class="text-xs text-muted-foreground"
                        >
                            Domain dibeli di VDC: bila belum punya akses
                            pengaturan DNS, kirim
                            <Link
                                :href="tiketRoute.index()"
                                class="text-brand-700 underline"
                                >Support Ticket</Link
                            >
                            berisi domain dan IP di atas, tim kami yang
                            membuatkan A record.
                        </p>
                        <p
                            v-if="layanan.domain_relay"
                            class="text-xs text-muted-foreground"
                        >
                            Jika memakai Cloudflare, matikan proxy (awan
                            abu-abu) dulu. SSL gratis diterbitkan otomatis
                            setelah DNS terhubung.
                        </p>
                    </div>
                    <p v-else class="text-muted-foreground">
                        Domain sudah dicatat. Tim kami akan menghubungi Anda
                        untuk langkah penyambungan.
                    </p>
                    <Button
                        v-if="layanan.domain_relay"
                        size="sm"
                        variant="outline"
                        @click="
                            router.post(
                                LayananController.periksaDomain.url(layanan.id),
                                {},
                                { preserveScroll: true },
                            )
                        "
                    >
                        <Search aria-hidden="true" />Periksa DNS
                    </Button>
                    <p
                        v-if="
                            layanan.domain_relay && layanan.custom_domain_aktif
                        "
                    >
                        Alamat:
                        <a
                            class="text-brand-700 underline"
                            :href="'https://' + layanan.custom_domain"
                            target="_blank"
                            rel="noopener"
                            >https://{{ layanan.custom_domain }}</a
                        >
                    </p>
                    <p
                        v-if="layanan.domain_relay && !layanan.is_app"
                        class="text-xs text-muted-foreground"
                    >
                        Pasang web server (nginx/Apache/aaPanel) di VPS pada
                        <b>port 80</b>. SSL dipasang di relay kami, VPS menerima
                        HTTP biasa dengan header
                        <code>X-Forwarded-Proto: https</code> dan
                        <code>X-Forwarded-For</code> berisi IP pengunjung.
                    </p>
                    <p
                        v-if="layanan.is_app"
                        class="text-xs text-muted-foreground"
                    >
                        Catatan: sebagian app (mis. WordPress) menyimpan alamat
                        situs sendiri; ubah alamat itu di pengaturan app ke
                        domain Anda.
                    </p>
                </template>
            </div>
        </section>

        <AnalitikVps
            v-if="punyaAnalitik"
            :titik="titikAnalitik"
            :rentang="rentang"
            :memuat="memuatRiwayat"
            @rentang="pilihRentang"
        />

        <section v-if="bisaAksi" class="rounded-lg border border-border">
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-3"
            >
                <div class="flex items-center gap-3">
                    <h2 class="font-semibold text-ink">
                        {{ layanan.is_app ? 'Kontrol app' : 'Kontrol VPS' }}
                    </h2>
                    <StatusBadge v-bind="statusPod" />
                    <span
                        class="flex items-center gap-1.5 text-xs text-muted-foreground"
                        aria-live="off"
                    >
                        <span
                            class="size-1.5 animate-pulse rounded-full bg-volt"
                        />
                        Live<template v-if="terakhir">
                            ·
                            {{ terakhir.toLocaleTimeString('id-ID') }}</template
                        >
                    </span>
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
                        <Power class="size-4" aria-hidden="true" />
                        <span
                            class="relative inline-block h-6 w-11 rounded-full transition-colors"
                            :class="
                                menyala
                                    ? 'bg-brand-700'
                                    : 'bg-muted-foreground/30'
                            "
                        >
                            <span
                                class="absolute top-0.5 left-0.5 size-5 rounded-full bg-white transition-transform"
                                :class="menyala ? 'translate-x-5' : ''"
                            />
                        </span>
                        {{ menyala ? 'On' : 'Off' }}
                    </button>
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="sibuk || !menyala"
                        @click="aksi('restart')"
                        ><RotateCw aria-hidden="true" />Restart</Button
                    >
                    <Button size="sm" variant="outline" @click="segarkan"
                        ><RefreshCw aria-hidden="true" />Segarkan</Button
                    >
                </div>
            </div>
            <div class="space-y-4 px-5 py-4 text-sm">
                <p v-if="pod?.galat" class="text-red-700">{{ pod.galat }}</p>
                <dl
                    v-if="pod?.info && Object.keys(pod.info).length"
                    class="grid grid-cols-2 gap-3 tabular-nums sm:grid-cols-4"
                >
                    <div
                        v-for="(nilai, nama) in pod.info"
                        :key="nama"
                        class="rounded-md bg-muted/50 px-3 py-2"
                    >
                        <dt class="text-xs text-muted-foreground">
                            {{ nama }}
                        </dt>
                        <dd class="font-semibold text-ink">{{ nilai }}</dd>
                    </div>
                </dl>
                <table v-if="pemakaian.length" class="w-full tabular-nums">
                    <thead class="text-xs text-muted-foreground">
                        <tr>
                            <th class="py-1 text-left font-medium">Komponen</th>
                            <th class="py-1 text-right font-medium">CPU</th>
                            <th class="py-1 text-right font-medium">RAM</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="p in pemakaian"
                            :key="p.container"
                            class="border-t border-border"
                        >
                            <td class="py-1.5">{{ singkat(p.container) }}</td>
                            <td class="py-1.5 text-right">{{ p.cpu }}</td>
                            <td class="py-1.5 text-right">
                                {{ p.memori.split(' / ')[0] }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="layanan.is_app">
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="memuatLog"
                        @click="muatLog"
                        ><ScrollText aria-hidden="true" />{{
                            lihatLog ? 'Muat ulang log' : 'Lihat log'
                        }}</Button
                    >
                    <div v-if="lihatLog" class="mt-3 space-y-3">
                        <p
                            v-if="memuatLog && !log"
                            class="text-muted-foreground"
                        >
                            Memuat log…
                        </p>
                        <div v-for="(isi, nama) in log ?? {}" :key="nama">
                            <p
                                class="mb-1 text-xs font-semibold text-muted-foreground"
                            >
                                {{ singkat(String(nama)) }}
                            </p>
                            <pre
                                class="max-h-80 overflow-auto rounded-md bg-slate-950 p-3 font-mono text-xs leading-relaxed [overflow-wrap:anywhere] whitespace-pre-wrap text-slate-100"
                                >{{ isi || '(kosong)' }}</pre>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section
            v-if="bisaAksi && layanan.pairing"
            class="rounded-lg border border-border"
        >
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-3"
            >
                <h2 class="font-semibold text-ink">Perangkat</h2>
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="memuatPerangkat"
                    @click="muatPerangkat"
                    ><Search aria-hidden="true" />{{
                        memuatPerangkat ? 'Memuat…' : 'Cek permintaan'
                    }}</Button
                >
            </div>
            <div class="space-y-4 px-5 py-4 text-sm">
                <p class="text-muted-foreground">
                    Buka alamat app, tempel sandi di kolom Gateway Token, lalu
                    klik Connect. Browser baru akan menunggu persetujuan: klik
                    <strong class="text-ink">Cek permintaan</strong> di sini dan
                    setujui browser Anda.
                </p>
                <p v-if="perangkat?.galat" class="text-red-700">
                    {{ perangkat.galat }}
                </p>
                <template v-else-if="perangkat && sudahMuatPerangkat">
                    <p
                        v-if="!perangkat.menunggu?.length"
                        class="text-muted-foreground"
                    >
                        Tidak ada permintaan yang menunggu.
                    </p>
                    <ul
                        v-else
                        class="divide-y divide-border rounded-md border border-border"
                    >
                        <li
                            v-for="d in perangkat.menunggu"
                            :key="d.id ?? d.nama"
                            class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                        >
                            <div>
                                <p class="font-medium text-ink">{{ d.nama }}</p>
                                <p
                                    class="text-xs text-muted-foreground tabular-nums"
                                >
                                    {{
                                        [d.ip, waktu(d.waktu)]
                                            .filter(Boolean)
                                            .join(' · ')
                                    }}
                                </p>
                            </div>
                            <Button
                                v-if="d.id"
                                size="sm"
                                :disabled="memuatPerangkat"
                                @click="setujui(d.id)"
                                ><Check aria-hidden="true" />Setujui</Button
                            >
                        </li>
                    </ul>
                    <p
                        v-if="perangkat.disetujui?.length"
                        class="text-muted-foreground"
                    >
                        {{ perangkat.disetujui.length }} perangkat sudah
                        disetujui.
                    </p>
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
