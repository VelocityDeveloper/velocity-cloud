<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowUp,
    Check,
    ChevronDown,
    History,
    RotateCcw,
    SquarePen,
    Square,
    Trash2,
    Wrench,
    X,
} from '@lucide/vue';
import { useLocalStorage } from '@vueuse/core';
import MarkdownIt from 'markdown-it';
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import ChatController from '@/actions/App/Http/Controllers/ChatController';
import TombolSalin from '@/components/cloud/TombolSalin.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { chat } from '@/routes';
import katalog from '@/routes/katalog';
import layanan from '@/routes/layanan';

type Kunci = {
    id: number;
    label: string;
    nama: string;
    model: string | null;
    saldo: number;
};
type Alat = { nama: string; ringkas: string; status: 'ok' | 'ditolak' };
type Pesan = {
    role: 'user' | 'assistant';
    content: string;
    galat?: boolean;
    alat?: Alat[];
};
type Ringkas = { uuid: string; judul: string; status?: Status; waktu: string };
// keadaan giliran AI di server (job JalankanChat); null = diam
type Status = 'jalan' | 'menunggu' | 'stop' | null;
type Keadaan = {
    status: Status;
    otomatis: boolean;
    sedang: string | null; // alat yang sedang dijalankan
    menunggu: string | null; // ringkasan alat yang menunggu persetujuan
};
type Hasil = Keadaan & {
    jumlah: number;
    akhir: Pesan;
    kunci?: Kunci[];
    percakapan?: Ringkas;
};

const props = defineProps<{
    kunci: Kunci[];
    daftar: Ringkas[];
    aktif:
        | ({
              uuid: string;
              service_id: number | null;
              pesan: Pesan[];
          } & Keadaan)
        | null;
}>();
defineOptions({
    layout: { breadcrumbs: [{ title: 'Chat AI', href: chat() }] },
});

const kunciL = ref(props.kunci);
const daftarL = ref(props.daftar);
const uuid = ref(props.aktif?.uuid ?? null);
const pesan = ref<Pesan[]>(props.aktif?.pesan ?? []);

// model = layanan token AI; percakapan lama memakai modelnya sendiri, chat baru memakai pilihan terakhir
const modelTerakhir = useLocalStorage<number | null>('chat-ai-layanan', null);
const ada = (id: number | null | undefined) =>
    props.kunci.some((k) => k.id === id);
const pilihId = ref<number | null>(
    ada(props.aktif?.service_id)
        ? props.aktif!.service_id
        : ada(modelTerakhir.value)
          ? modelTerakhir.value
          : (props.kunci[0]?.id ?? null),
);
const aktif = computed(
    () => kunciL.value.find((k) => k.id === pilihId.value) ?? null,
);
function pilihModel(id: number) {
    pilihId.value = id;
    modelTerakhir.value = id;
}

const isi = ref('');
const status = ref<Status>(props.aktif?.status ?? null);
const otomatis = ref(props.aktif?.otomatis ?? false);
const sedangAlat = ref(props.aktif?.sedang ?? '');
const menunggu = ref(props.aktif?.menunggu ?? null);
const sibuk = computed(() => status.value !== null);
// teks indikator berkedip di pesan AI terakhir selama giliran berjalan di server
const indikator = computed(() =>
    status.value !== 'jalan'
        ? ''
        : sedangAlat.value
          ? `Menjalankan: ${sedangAlat.value}`
          : pesan.value.at(-1)?.content
            ? 'Sedang menulis'
            : 'Sedang berpikir',
);
// status di riwayat: percakapan yang sedang dibuka memakai status langsung
const statusRiwayat = (r: Ringkas) =>
    r.uuid === uuid.value ? status.value : (r.status ?? null);
const galat = ref(''); // galat kirim/izin (mis. 409, saldo)
const putus = ref(false); // pemantauan status gagal, sedang mencoba lagi
const riwayatTerbuka = ref(false); // panel riwayat di layar kecil
const daftarEl = ref<HTMLElement | null>(null);
const gulir = () =>
    nextTick(() =>
        daftarEl.value?.scrollTo({ top: daftarEl.value.scrollHeight }),
    );
gulir();
// AI berjalan di server: meninggalkan halaman hanya menghentikan pemantauan, bukan prosesnya
let hidup = true;
let pemantau: ReturnType<typeof setTimeout> | undefined;
onBeforeUnmount(() => {
    hidup = false;
    clearTimeout(pemantau);
});

const headerJson = () => ({
    'content-type': 'application/json',
    accept: 'application/json',
    'x-xsrf-token': decodeURIComponent(
        document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '',
    ),
    'x-requested-with': 'XMLHttpRequest',
});

/** Kirim ke server; galat HTTP dilempar dengan pesan dari server. */
async function api(url: string, body?: object): Promise<Hasil> {
    const res = await fetch(url, {
        method: body ? 'POST' : 'GET',
        headers: headerJson(),
        body: body ? JSON.stringify(body) : undefined,
    });
    const j = await res.json().catch(() => null);
    if (!res.ok) throw new Error(j?.message || `Gagal (HTTP ${res.status}).`);
    return j;
}

function terapkan(j: Hasil) {
    status.value = j.status;
    otomatis.value = j.otomatis;
    sedangAlat.value = j.sedang ?? '';
    menunggu.value = j.menunggu;
    // server hanya menambah/mengubah pesan terakhir giliran ini
    pesan.value = [...pesan.value.slice(0, j.jumlah - 1), j.akhir];
    if (j.kunci) kunciL.value = j.kunci;
    gulir();
}

function pantau(jeda = 1000) {
    clearTimeout(pemantau);
    if (!hidup || !uuid.value || !['jalan', 'stop'].includes(status.value!))
        return;
    const id = uuid.value;
    pemantau = setTimeout(async () => {
        try {
            const j = await api(ChatController.status(id).url);
            if (!hidup || uuid.value !== id) return;
            putus.value = false;
            terapkan(j);
            pantau();
        } catch {
            putus.value = true;
            pantau(3000);
        }
    }, jeda);
}
pantau(0); // percakapan dibuka saat AI masih berjalan

async function mulai(riwayat: Pesan[], body: object) {
    galat.value = '';
    const id = (uuid.value ??= crypto.randomUUID());
    const sebelum = pesan.value;
    pesan.value = [...riwayat, { role: 'assistant', content: '' }];
    status.value = 'jalan';
    gulir();
    try {
        const j = await api(ChatController.kirim(id).url, {
            service_id: pilihId.value,
            ...body,
        });
        if (!hidup) return;
        terapkan(j);
        daftarL.value = [
            j.percakapan!,
            ...daftarL.value.filter((d) => d.uuid !== id),
        ];
        // URL ikut percakapan tanpa memuat ulang halaman
        router.replace({
            url: chat(id).url,
            preserveState: true,
            preserveScroll: true,
            props: (p) => ({
                ...p,
                kunci: kunciL.value,
                daftar: daftarL.value,
                aktif: {
                    uuid: id,
                    service_id: pilihId.value,
                    pesan: pesan.value,
                    status: status.value,
                    otomatis: otomatis.value,
                    sedang: sedangAlat.value || null,
                    menunggu: menunggu.value,
                },
            }),
        });
        pantau();
    } catch (e) {
        pesan.value = sebelum;
        status.value = null;
        galat.value = e instanceof Error ? e.message : 'Gagal mengirim.';
    }
}

function kirim() {
    const teks = isi.value.trim();
    if (!aktif.value || !teks || sibuk.value) return;
    isi.value = '';
    mulai(
        [
            ...pesan.value.filter((p) => !p.galat),
            { role: 'user', content: teks },
        ],
        { teks },
    ).then(() => {
        if (galat.value) isi.value ||= teks; // gagal terkirim: teks dikembalikan
    });
}

// Coba lagi: jawaban galat dibuang, pesan user terakhir dikirim ulang (tanpa menggandakannya)
function ulangi() {
    if (sibuk.value || !aktif.value) return;
    const riwayat = pesan.value.filter((p) => !p.galat);
    if (riwayat.at(-1)?.role === 'user') mulai(riwayat, { ulang: true });
}

async function aksi(url: string, body?: object) {
    galat.value = '';
    try {
        terapkan(await api(url, body ?? {}));
        pantau(300);
    } catch (e) {
        galat.value = e instanceof Error ? e.message : 'Gagal.';
    }
}
const izin = (jalankan: boolean, sesi = false) =>
    aksi(ChatController.izin(uuid.value!).url, { jalankan, sesi });
const berhenti = () => aksi(ChatController.berhenti(uuid.value!).url);
const cabutOtomatis = () =>
    aksi(ChatController.otomatis(uuid.value!).url, { nyala: false });

function tombol(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
        e.preventDefault();
        kirim();
    }
}
function hapus(r: Ringkas) {
    if (!confirm(`Hapus percakapan "${r.judul}"?`)) return;
    const lain = r.uuid !== uuid.value;
    if (lain) daftarL.value = daftarL.value.filter((d) => d.uuid !== r.uuid);
    // percakapan lain dihapus: tetap di percakapan yang sedang dibuka tanpa kehilangan isi layar
    router.delete(ChatController.hapus(r.uuid).url, {
        data: { kembali: lain ? uuid.value : null },
        preserveState: lain,
        preserveScroll: lain,
    });
}

// kelompok ala ChatGPT: Hari ini / Kemarin / 7 hari / Lebih lama
const kelompok = computed(() => {
    const awal = new Date();
    awal.setHours(0, 0, 0, 0);
    const nama = (w: string) => {
        const t = new Date(w).getTime();
        return t >= awal.getTime()
            ? 'Hari ini'
            : t >= awal.getTime() - 864e5
              ? 'Kemarin'
              : t >= awal.getTime() - 7 * 864e5
                ? '7 hari terakhir'
                : 'Lebih lama';
    };
    const g: { nama: string; isi: Ringkas[] }[] = [];
    for (const r of daftarL.value) {
        const n = nama(r.waktu);
        if (g.at(-1)?.nama !== n) g.push({ nama: n, isi: [] });
        g.at(-1)!.isi.push(r);
    }
    return g;
});
const angka = (n: number) => n.toLocaleString('id-ID');

// Jawaban AI berformat markdown. html:false = tag HTML dari model di-escape, aman untuk v-html.
const md = new MarkdownIt({ html: false, linkify: true, breaks: true });
const linkAsli =
    md.renderer.rules.link_open ??
    ((t, i, o, _e, self) => self.renderToken(t, i, o));
md.renderer.rules.link_open = (t, i, o, e, self) => {
    t[i].attrSet('target', '_blank');
    t[i].attrSet('rel', 'noopener noreferrer');
    return linkAsli(t, i, o, e, self);
};
</script>

<template>
    <Head title="Chat AI" />
    <div class="relative flex h-[calc(100svh-4rem)] min-h-0">
        <!-- Riwayat -->
        <aside
            class="absolute inset-y-0 left-0 z-20 w-72 flex-col border-r bg-background md:static md:flex md:w-64 md:shrink-0"
            :class="riwayatTerbuka ? 'flex shadow-xl' : 'hidden'"
            aria-label="Riwayat percakapan"
        >
            <div class="flex items-center gap-2 p-3">
                <Button
                    as-child
                    variant="outline"
                    class="flex-1 justify-start gap-2"
                >
                    <Link :href="chat()"
                        ><SquarePen class="size-4" aria-hidden="true" />Chat
                        baru</Link
                    >
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    class="md:hidden"
                    aria-label="Tutup riwayat"
                    @click="riwayatTerbuka = false"
                    ><X class="size-4"
                /></Button>
            </div>
            <nav class="min-h-0 flex-1 overflow-y-auto px-2 pb-3 text-sm">
                <p
                    v-if="!daftarL.length"
                    class="px-2 py-4 text-muted-foreground"
                >
                    Belum ada riwayat.
                </p>
                <section
                    v-for="g in kelompok"
                    :key="g.nama"
                    class="mt-3 first:mt-0"
                >
                    <h2
                        class="px-2 py-1 text-xs font-medium text-muted-foreground"
                    >
                        {{ g.nama }}
                    </h2>
                    <ul>
                        <li
                            v-for="r in g.isi"
                            :key="r.uuid"
                            class="group relative"
                        >
                            <Link
                                :href="chat(r.uuid)"
                                class="block truncate rounded-md py-2 pr-8 pl-2 hover:bg-muted"
                                :class="
                                    r.uuid === uuid
                                        ? 'bg-muted font-medium text-ink'
                                        : ''
                                "
                                :aria-current="
                                    r.uuid === uuid ? 'page' : undefined
                                "
                                :title="r.judul"
                                ><span
                                    v-if="statusRiwayat(r) === 'jalan'"
                                    class="titik-jalan mr-2 inline-block size-2 rounded-full bg-primary align-middle"
                                    title="AI sedang berjalan"
                                /><span
                                    v-else-if="statusRiwayat(r) === 'menunggu'"
                                    class="mr-2 inline-block size-2 rounded-full bg-amber-500 align-middle"
                                    title="Menunggu persetujuan Anda"
                                />{{ r.judul }}</Link
                            >
                            <button
                                type="button"
                                class="absolute top-1/2 right-1 -translate-y-1/2 rounded p-1 text-muted-foreground opacity-0 group-hover:opacity-100 hover:text-destructive focus-visible:opacity-100"
                                :aria-label="`Hapus ${r.judul}`"
                                @click="hapus(r)"
                            >
                                <Trash2 class="size-4" />
                            </button>
                        </li>
                    </ul>
                </section>
            </nav>
        </aside>

        <!-- Percakapan -->
        <section class="flex min-w-0 flex-1 flex-col">
            <div class="flex items-center gap-2 px-3 py-2">
                <Button
                    variant="ghost"
                    size="icon"
                    class="md:hidden"
                    aria-label="Buka riwayat"
                    @click="riwayatTerbuka = true"
                    ><History class="size-5"
                /></Button>
                <DropdownMenu v-if="kunciL.length">
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="ghost"
                            class="gap-1 text-base font-semibold"
                            :disabled="sibuk"
                        >
                            {{ aktif?.nama ?? 'Pilih model'
                            }}<ChevronDown
                                class="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" class="w-72">
                        <DropdownMenuLabel class="text-xs text-muted-foreground"
                            >Model dari langganan Anda</DropdownMenuLabel
                        >
                        <DropdownMenuItem
                            v-for="k in kunciL"
                            :key="k.id"
                            class="items-start gap-2"
                            @select="pilihModel(k.id)"
                        >
                            <span class="flex-1">
                                <span class="block font-medium">{{
                                    k.nama
                                }}</span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ k.label }} ·
                                    {{ angka(k.saldo) }} token</span
                                >
                            </span>
                            <Check
                                v-if="k.id === pilihId"
                                class="mt-0.5 size-4"
                                aria-hidden="true"
                            />
                        </DropdownMenuItem>
                        <DropdownMenuItem as-child>
                            <Link :href="katalog.index('ai')" class="text-xs"
                                >Tambah model / beli token</Link
                            >
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <span v-if="aktif" class="ml-auto text-xs text-muted-foreground"
                    >Saldo {{ angka(aktif.saldo) }} token</span
                >
            </div>

            <div
                v-if="!kunciL.length"
                class="m-auto max-w-sm p-6 text-center text-sm"
            >
                <p class="text-muted-foreground">
                    Belum ada layanan Token AI yang aktif.
                </p>
                <Button as-child class="mt-4"
                    ><Link :href="katalog.index('ai')"
                        >Beli Token AI</Link
                    ></Button
                >
            </div>

            <template v-else>
                <div
                    ref="daftarEl"
                    class="min-h-0 flex-1 overflow-y-auto"
                    aria-live="polite"
                >
                    <div
                        v-if="!pesan.length"
                        class="flex h-full items-center justify-center p-6"
                    >
                        <div class="text-center">
                            <h1 class="text-2xl font-semibold text-ink">
                                Apa yang bisa saya bantu?
                            </h1>
                            <p class="mt-2 text-sm text-muted-foreground">
                                Bisa juga mengelola layanan Anda, mis. “cek
                                status WordPress saya” atau “restart n8n”.
                            </p>
                        </div>
                    </div>
                    <ol v-else class="mx-auto max-w-3xl space-y-6 px-4 py-6">
                        <li
                            v-for="(p, i) in pesan"
                            :key="i"
                            class="flex"
                            :class="
                                p.role === 'user'
                                    ? 'justify-end'
                                    : 'justify-start'
                            "
                        >
                            <div
                                v-if="p.role === 'user'"
                                class="max-w-[80%] rounded-3xl bg-muted px-5 py-2.5 text-sm break-words whitespace-pre-wrap"
                            >
                                {{ p.content }}
                            </div>
                            <div
                                v-else
                                class="group w-full text-sm leading-relaxed"
                            >
                                <ul
                                    v-if="p.alat?.length"
                                    class="mb-2 space-y-1"
                                    aria-label="Alat yang dijalankan AI"
                                >
                                    <li
                                        v-for="(a, j) in p.alat"
                                        :key="j"
                                        class="flex items-center gap-2 text-xs text-muted-foreground"
                                    >
                                        <Wrench
                                            class="size-3.5 shrink-0"
                                            aria-hidden="true"
                                        />
                                        <code
                                            class="truncate"
                                            :title="a.ringkas"
                                            >{{ a.ringkas }}</code
                                        >
                                        <span
                                            v-if="a.status === 'ditolak'"
                                            class="shrink-0 text-destructive"
                                            >ditolak</span
                                        >
                                    </li>
                                </ul>
                                <div
                                    v-if="menunggu && i === pesan.length - 1"
                                    class="mb-3 rounded-lg border bg-muted/40 p-3"
                                    role="group"
                                    aria-label="Persetujuan aksi AI"
                                >
                                    <p class="font-medium text-ink">
                                        AI ingin menjalankan aksi ini di layanan
                                        Anda:
                                    </p>
                                    <pre
                                        class="mt-2 rounded bg-background p-2 text-xs break-all whitespace-pre-wrap"
                                        >{{ menunggu }}</pre>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <Button
                                            type="button"
                                            size="sm"
                                            @click="izin(true)"
                                            >Jalankan</Button
                                        >
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="secondary"
                                            @click="izin(true, true)"
                                            >Jalankan untuk sesi ini</Button
                                        >
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            @click="izin(false)"
                                            >Tolak</Button
                                        >
                                    </div>
                                </div>
                                <div
                                    v-if="p.galat"
                                    class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border border-destructive/40 bg-destructive/5 px-4 py-2 text-destructive"
                                >
                                    <p class="flex-1">{{ p.content }}</p>
                                    <Button
                                        v-if="i === pesan.length - 1"
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        class="gap-1.5"
                                        :disabled="
                                            sibuk || !aktif || aktif.saldo <= 0
                                        "
                                        @click="ulangi"
                                    >
                                        <RotateCcw
                                            class="size-4"
                                            aria-hidden="true"
                                        />Coba lagi
                                    </Button>
                                </div>
                                <p
                                    v-else-if="p.content"
                                    class="md break-words"
                                    v-html="md.render(p.content)"
                                />
                                <p
                                    v-if="indikator && i === pesan.length - 1"
                                    class="mt-2 flex items-center gap-2 text-xs text-muted-foreground"
                                    role="status"
                                >
                                    <span class="kedip" aria-hidden="true"
                                        ><span /><span /><span
                                    /></span>
                                    <span class="truncate">{{
                                        indikator
                                    }}</span>
                                </p>
                                <div
                                    v-if="
                                        p.content &&
                                        !p.galat &&
                                        !(sibuk && i === pesan.length - 1)
                                    "
                                    class="mt-1 opacity-60 group-hover:opacity-100"
                                >
                                    <TombolSalin
                                        :teks="p.content"
                                        label="jawaban"
                                        ikon
                                    />
                                </div>
                            </div>
                        </li>
                    </ol>
                </div>

                <div class="mx-auto w-full max-w-3xl px-4 pb-4">
                    <p
                        v-if="aktif && aktif.saldo <= 0"
                        class="mb-2 text-sm text-destructive"
                    >
                        Saldo token model ini habis.
                        <Link
                            :href="layanan.show(aktif.id)"
                            class="font-medium underline"
                            >Top-up</Link
                        >
                        atau pilih model lain.
                    </p>
                    <p
                        v-if="otomatis"
                        class="mb-2 text-xs text-muted-foreground"
                    >
                        Aksi AI di percakapan ini dijalankan tanpa bertanya.
                        <button
                            type="button"
                            class="font-medium text-ink underline"
                            @click="cabutOtomatis"
                        >
                            Minta persetujuan lagi
                        </button>
                    </p>
                    <p v-if="galat" class="mb-2 text-xs text-destructive">
                        {{ galat }}
                    </p>
                    <p
                        v-if="putus && sibuk"
                        class="mb-2 text-xs text-muted-foreground"
                    >
                        Koneksi terputus, mencoba lagi… AI tetap berjalan di
                        server.
                    </p>
                    <p
                        v-else-if="sibuk"
                        class="mb-2 text-xs text-muted-foreground"
                    >
                        AI berjalan di server: aman pindah halaman atau menutup
                        tab, hasilnya tersimpan di riwayat.
                    </p>
                    <form
                        class="flex items-end gap-2 rounded-3xl border bg-background p-2 pl-4 shadow-sm focus-within:ring-2 focus-within:ring-ring"
                        @submit.prevent="kirim"
                    >
                        <label for="isi-chat" class="sr-only">Pesan</label>
                        <textarea
                            id="isi-chat"
                            v-model="isi"
                            rows="1"
                            maxlength="20000"
                            :placeholder="
                                aktif
                                    ? `Kirim pesan ke ${aktif.nama}`
                                    : 'Pilih model dulu'
                            "
                            class="field-sizing-content max-h-52 min-h-9 flex-1 resize-none bg-transparent py-2 text-sm focus:outline-none"
                            @keydown="tombol"
                        />
                        <Button
                            v-if="sibuk"
                            type="button"
                            size="icon"
                            class="rounded-full"
                            aria-label="Berhenti"
                            :disabled="status === 'stop'"
                            @click="berhenti"
                        >
                            <Square class="size-4 fill-current" />
                        </Button>
                        <Button
                            v-else
                            type="submit"
                            size="icon"
                            class="rounded-full"
                            aria-label="Kirim"
                            :disabled="
                                !isi.trim() || !aktif || aktif.saldo <= 0
                            "
                        >
                            <ArrowUp class="size-4" />
                        </Button>
                    </form>
                    <p class="mt-2 text-center text-xs text-muted-foreground">
                        AI bisa keliru. Aksi yang mengubah layanan selalu minta
                        persetujuan Anda. Setiap pesan memotong saldo token.
                    </p>
                </div>
            </template>
        </section>
    </div>
</template>

<style scoped>
/* Indikator AI berjalan: tiga titik berkedip bergantian; titik riwayat berdenyut */
.kedip {
    display: inline-flex;
    gap: 4px;
}
.kedip span {
    width: 6px;
    height: 6px;
    border-radius: 999px;
    background: var(--primary);
    animation: kedip 1.2s ease-in-out infinite;
}
.kedip span:nth-child(2) {
    animation-delay: 0.2s;
}
.kedip span:nth-child(3) {
    animation-delay: 0.4s;
}
@keyframes kedip {
    0%,
    80%,
    100% {
        opacity: 0.25;
        transform: scale(0.8);
    }
    40% {
        opacity: 1;
        transform: scale(1);
    }
}
.titik-jalan {
    animation: kedip 1.2s ease-in-out infinite;
}
@media (prefers-reduced-motion: reduce) {
    .kedip span,
    .titik-jalan {
        animation: none;
        opacity: 0.7;
    }
}
/* Format jawaban AI (tanpa plugin typography) */
.md :deep(> * + *) {
    margin-top: 0.75em;
}
.md :deep(h1),
.md :deep(h2),
.md :deep(h3),
.md :deep(h4) {
    font-weight: 600;
    color: var(--ink, inherit);
    margin-top: 1.25em;
}
.md :deep(h1) {
    font-size: 1.25rem;
}
.md :deep(h2) {
    font-size: 1.125rem;
}
.md :deep(h3),
.md :deep(h4) {
    font-size: 1rem;
}
.md :deep(> :first-child) {
    margin-top: 0;
}
.md :deep(ul) {
    list-style: disc;
    padding-left: 1.5em;
}
.md :deep(ol) {
    list-style: decimal;
    padding-left: 1.5em;
}
.md :deep(li + li),
.md :deep(li > ul),
.md :deep(li > ol) {
    margin-top: 0.25em;
}
.md :deep(strong) {
    font-weight: 600;
}
.md :deep(a) {
    color: inherit;
    text-decoration: underline;
    text-underline-offset: 2px;
    word-break: break-all;
}
.md :deep(code) {
    font-size: 0.85em;
    background: var(--muted);
    border-radius: 0.25rem;
    padding: 0.1em 0.35em;
}
.md :deep(pre) {
    background: var(--muted);
    border: 1px solid var(--border);
    border-radius: 0.5rem;
    padding: 0.75rem 1rem;
    overflow-x: auto;
}
.md :deep(pre code) {
    background: none;
    padding: 0;
    font-size: 0.8rem;
}
.md :deep(blockquote) {
    border-left: 3px solid var(--border);
    padding-left: 0.75em;
    color: var(--muted-foreground);
}
.md :deep(table) {
    display: block;
    max-width: 100%;
    overflow-x: auto;
    border-collapse: collapse;
}
.md :deep(th),
.md :deep(td) {
    border: 1px solid var(--border);
    padding: 0.35em 0.6em;
    text-align: left;
}
.md :deep(th) {
    background: var(--muted);
    font-weight: 600;
}
.md :deep(hr) {
    border-color: var(--border);
}
</style>
