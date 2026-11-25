<script setup lang="ts">
// Peta kata kunci gaya diagram /token-ai landing: kartu teks di latar titik, situs di tengah.
// Tata letak kelompok (bukan cincin global): tiap kata kunci dikelilingi rapat oleh anak-anaknya, condong ke arah luar;
// bila satu lapis penuh, anak berikutnya membentuk lapis baru. Tiap subpohon (≥2 anak) diberi lingkaran samar.
// Kata kunci ditaruh di bawah induk pertamanya, induk lain = garis putus-putus. Paket beranimasi hanya ke target.
// Geser = drag (mouse/jari), zoom = roda mouse / pinch / tombol; klik kartu (tanpa menggeser) = detail.
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { angka } from '@/lib/analitik';
import { NAMA_STATUS, WARNA } from '@/lib/petaKataKunci';
import type { Simpul } from '@/lib/petaKataKunci';

// fokus = id kata kunci: kata kunci itu di tengah, semua turunannya (lewat induk mana pun) mengelilinginya.
const props = defineProps<{
    simpul: Simpul[];
    dipilih: string | null;
    fokus?: string | null;
}>();
const emit = defineEmits<{ pilih: [id: string | null] }>();

const W = 168,
    H = 58,
    R0 = 90, // lingkaran yang memuat kartu induk (setengah diagonal 168×58 + sedikit)
    HP = 26, // tinggi pil daun
    PAD = 4; // celah antarlingkaran
const lebarPil = (s: Simpul) => Math.min(190, 34 + s.kata.length * 6.4);
const URUT: Record<string, number> = {
    target: 0,
    turunan: 1,
    gsc: 2,
    cadangan: 3,
};
const tata = computed(() => {
    const akar = props.simpul.find((s) => s.jenis === 'akar') ?? {
        id: '#akar',
        jenis: 'akar' as const,
        kata: 'situs',
    };
    const kata = props.simpul.filter((s) => s.jenis === 'kata');
    const ada = new Map(kata.map((s) => [s.id, s]));
    const calon = new Map<string, Simpul[]>();
    for (const s of kata) {
        const p = s.induk?.find((i) => ada.has(i)) ?? akar.id;
        calon.set(p, [...(calon.get(p) ?? []), s]);
    }
    // pohon dari akar (BFS); induk saling menunjuk (siklus) tidak terjangkau → gantung di akar
    const anak = new Map<string, Simpul[]>(),
        dalam = new Map<string, number>([[akar.id, 0]]);
    const jelajah = (dari: string[]) => {
        for (let i = 0; i < dari.length; i++)
            for (const c of calon.get(dari[i]) ?? [])
                if (!dalam.has(c.id)) {
                    dalam.set(c.id, dalam.get(dari[i])! + 1);
                    anak.set(dari[i], [...(anak.get(dari[i]) ?? []), c]);
                    dari.push(c.id);
                }
    };
    jelajah([akar.id]);
    for (const s of kata)
        if (!dalam.has(s.id)) {
            dalam.set(s.id, 1);
            anak.set(akar.id, [...(anak.get(akar.id) ?? []), s]);
            jelajah([s.id]);
        }
    for (const l of anak.values())
        l.sort(
            (a, b) =>
                URUT[a.status!] - URUT[b.status!] ||
                (b.volume ?? 0) +
                    (b.tayangan ?? 0) -
                    ((a.volume ?? 0) + (a.tayangan ?? 0)),
        );

    // pohon yang digambar: ikhtisar = seluruh pohon dari situs; fokus = BFS dari kata kunci fokus lewat induk mana pun
    const fokus = props.fokus && ada.has(props.fokus) ? props.fokus : null;
    const pusat = fokus ? ada.get(fokus)! : akar;
    let pohon = anak;
    if (fokus) {
        const turunan = new Map<string, Simpul[]>(),
            dikenal = new Set([fokus]);
        for (const s of kata)
            for (const i of s.induk ?? [])
                if (ada.has(i)) turunan.set(i, [...(turunan.get(i) ?? []), s]);
        pohon = new Map();
        for (const antre = [fokus]; antre.length;) {
            const id = antre.shift()!;
            for (const c of turunan.get(id) ?? [])
                if (!dikenal.has(c.id)) {
                    dikenal.add(c.id);
                    pohon.set(id, [...(pohon.get(id) ?? []), c]);
                    antre.push(c.id);
                }
        }
    }

    // susun(id) → posisi relatif turunan (kartu id di 0,0; arah luar = sudut 0) + jari-jari lingkaran subpohon.
    // Anak (lingkaran subpohonnya) ditempatkan rakus: jarak sekecil mungkin, sudut sedekat mungkin ke arah luar,
    // tanpa menimpa lingkaran lain; subpohon anak diputar ke arah tempatnya.
    // cx, cy = pusat lingkaran subpohon relatif ke kartunya (anak tumbuh ke satu sisi → pusat bergeser ke sana)
    type Hasil = {
        r: number;
        cx: number;
        cy: number;
        rel: Map<string, { x: number; y: number }>;
    };
    const putar = (x: number, y: number, a: number) => ({
        x: x * Math.cos(a) - y * Math.sin(a),
        y: x * Math.sin(a) + y * Math.cos(a),
    });
    const daun = (id: string) => id !== pusat.id && !pohon.get(id)?.length;
    const susun = (id: string, akarnya: boolean): Hasil => {
        const sub = (pohon.get(id) ?? [])
            .map((c) => ({ c, h: susun(c.id, false) }))
            .sort((a, b) => b.h.r - a.h.r);
        const terisi: { x: number; y: number; r: number }[] = [
            {
                x: 0,
                y: 0,
                r: daun(id)
                    ? Math.hypot(lebarPil(ada.get(id)!) / 2, HP / 2) + 1
                    : R0,
            },
        ];
        const rel = new Map<string, { x: number; y: number }>();
        const batasSudut = akarnya ? Math.PI : Math.PI * 0.85; // jangan tumbuh tepat ke arah induknya
        for (const { c, h } of sub) {
            let tempat: { x: number; y: number; a: number } | null = null;
            for (let jarak = R0 + h.r + PAD; !tempat; jarak += 6) {
                const langkah = Math.min(0.5, 6 / jarak);
                for (let n = 0; n * langkah <= batasSudut && !tempat; n++)
                    for (const a of n ? [n * langkah, -n * langkah] : [0]) {
                        const x = jarak * Math.cos(a),
                            y = jarak * Math.sin(a);
                        if (
                            terisi.every(
                                (o) =>
                                    Math.hypot(o.x - x, o.y - y) >=
                                    o.r + h.r + PAD,
                            )
                        ) {
                            tempat = { x, y, a };
                            break;
                        }
                    }
            }
            const t = tempat!;
            terisi.push({ x: t.x, y: t.y, r: h.r });
            // tempat = pusat lingkaran subpohon; kartu anak = tempat − geseran pusatnya (diputar)
            const g0 = putar(h.cx, h.cy, t.a),
                kx = t.x - g0.x,
                ky = t.y - g0.y;
            rel.set(c.id, { x: kx, y: ky });
            for (const [d, q] of h.rel) {
                const v = putar(q.x, q.y, t.a);
                rel.set(d, { x: kx + v.x, y: ky + v.y });
            }
        }
        // lingkaran subpohon: pusat = tengah kotak pembatas semua lingkaran, jari-jari = yang terjauh
        const cx =
                (Math.min(...terisi.map((o) => o.x - o.r)) +
                    Math.max(...terisi.map((o) => o.x + o.r))) /
                2,
            cy =
                (Math.min(...terisi.map((o) => o.y - o.r)) +
                    Math.max(...terisi.map((o) => o.y + o.r))) /
                2;
        const r = Math.max(
            ...terisi.map((o) => Math.hypot(o.x - cx, o.y - cy) + o.r),
        );
        return { r, cx, cy, rel };
    };
    const hasil = susun(pusat.id, true);
    const pos = new Map([[pusat.id, { x: 0, y: 0 }], ...hasil.rel]);

    // pemadatan: mulai dari susunan di atas, tiap kata kunci ditarik ke induknya (pegas), kartu yang bertabrakan
    // (persegi panjang + celah) didorong terpisah; kartu tengah diam. ±300 kartu × 160 putaran ≈ puluhan ms.
    const ukuran = (id: string) =>
        daun(id) ? [lebarPil(ada.get(id)!), HP] : [W, H];
    const n = [...pos.keys()].map((id) => ({
        id,
        ...pos.get(id)!,
        w: ukuran(id)[0],
        h: ukuran(id)[1],
    }));
    const indeks = new Map(n.map((v, i) => [v.id, i]));
    const pegas: [number, number][] = [];
    for (const [id, l] of pohon)
        for (const c of l)
            if (indeks.has(c.id))
                pegas.push([indeks.get(id)!, indeks.get(c.id)!]);
    for (let it = 0, PUTARAN = 160; it < PUTARAN + 40; it++) {
        const alfa = Math.max(0, 1 - it / PUTARAN); // 40 putaran terakhir: hanya pisahkan tabrakan
        for (const [a, b] of pegas) {
            const A = n[a],
                B = n[b];
            const dx = B.x - A.x,
                dy = B.y - A.y,
                d = Math.hypot(dx, dy) || 1;
            const L = (A.h + B.h) / 2 + 36,
                f = ((d - L) / d) * 0.12 * alfa;
            B.x -= dx * f;
            B.y -= dy * f;
            if (A.id !== pusat.id) {
                A.x += dx * f * 0.3;
                A.y += dy * f * 0.3;
            }
        }
        for (let i = 0; i < n.length; i++)
            for (let j = i + 1; j < n.length; j++) {
                const A = n[i],
                    B = n[j];
                const ox = (A.w + B.w) / 2 + 10 - Math.abs(A.x - B.x),
                    oy = (A.h + B.h) / 2 + 10 - Math.abs(A.y - B.y);
                if (ox <= 0 || oy <= 0) continue;
                const [gx, gy] =
                    ox < oy
                        ? [(A.x < B.x ? -ox : ox) / 2, 0]
                        : [0, (A.y < B.y ? -oy : oy) / 2];
                const bagiA = A.id === pusat.id ? 0 : B.id === pusat.id ? 2 : 1,
                    bagiB = 2 - bagiA;
                A.x += gx * bagiA;
                A.y += gy * bagiA;
                B.x -= gx * bagiB;
                B.y -= gy * bagiB;
            }
    }
    for (const v of n) pos.set(v.id, { x: v.x, y: v.y });

    // lingkaran gugus dari posisi akhir: kata kunci (≥2 anak) + semua turunannya
    const kelompok: { id: string; x: number; y: number; r: number }[] = [];
    const isi = (id: string): typeof n => [
        n[indeks.get(id)!],
        ...(pohon.get(id) ?? [])
            .filter((c) => indeks.has(c.id))
            .flatMap((c) => isi(c.id)),
    ];
    for (const v of n) {
        if (v.id === pusat.id || (pohon.get(v.id)?.length ?? 0) < 2) continue;
        const k = isi(v.id);
        const cx =
                (Math.min(...k.map((q) => q.x - q.w / 2)) +
                    Math.max(...k.map((q) => q.x + q.w / 2))) /
                2,
            cy =
                (Math.min(...k.map((q) => q.y - q.h / 2)) +
                    Math.max(...k.map((q) => q.y + q.h / 2))) /
                2;
        kelompok.push({
            id: v.id,
            x: cx,
            y: cy,
            r:
                Math.max(
                    ...k.map((q) =>
                        Math.hypot(
                            Math.abs(q.x - cx) + q.w / 2,
                            Math.abs(q.y - cy) + q.h / 2,
                        ),
                    ),
                ) + 6,
        });
    }

    const jalur = (dari: string, ke: string) => {
        const p = pos.get(dari)!,
            c = pos.get(ke)!;
        return `M${p.x} ${p.y} L${c.x} ${c.y}`;
    };
    const induknya = new Map<string, string>();
    for (const [id, l] of pohon) for (const c of l) induknya.set(c.id, id);
    const tampil = kata.filter((s) => pos.has(s.id) && s.id !== pusat.id);
    const garis = tampil.flatMap((s) => {
        const utama = induknya.get(s.id)!;
        const lain = (s.induk ?? []).filter((i) => i !== utama && pos.has(i));
        return [
            {
                id: `${utama}>${s.id}`,
                d: jalur(utama, s.id),
                utama: true,
                aktif: s.status === 'target',
            },
            ...lain.map((i) => ({
                id: `${i}>${s.id}`,
                d: jalur(i, s.id),
                utama: false,
                aktif: false,
            })),
        ];
    });
    const kartu = [pusat, ...tampil].map((s) => ({
        s,
        x: pos.get(s.id)!.x,
        y: pos.get(s.id)!.y,
        pusat: s.id === pusat.id,
        daun: daun(s.id),
        w: daun(s.id) ? lebarPil(s) : W,
        h: daun(s.id) ? HP : H,
    }));

    return { kartu, garis, kelompok };
});

// ---------- zoom & geser ----------
const layar = ref<HTMLDivElement | null>(null);
const k = ref(1),
    tx = ref(0),
    ty = ref(0);
const titik = computed(() => {
    let t = 22 * k.value;
    while (t < 11) t *= 2;
    while (t > 44) t /= 2;
    return t;
});
const batas = (v: number) => Math.min(3, Math.max(0.05, v));
function zoomDi(f: number, px: number, py: number) {
    const baru = batas(k.value * f);
    tx.value = px - ((px - tx.value) * baru) / k.value;
    ty.value = py - ((py - ty.value) * baru) / k.value;
    k.value = baru;
}
function zoomTengah(f: number) {
    const el = layar.value!;
    zoomDi(f, el.clientWidth / 2, el.clientHeight / 2);
}
function pas(lantai = 0) {
    const el = layar.value;
    if (!el) return;
    const kt = tata.value.kartu;
    const [x0, x1, y0, y1] = [
        Math.min(...kt.map((c) => c.x - c.w / 2)),
        Math.max(...kt.map((c) => c.x + c.w / 2)),
        Math.min(...kt.map((c) => c.y - c.h / 2)),
        Math.max(...kt.map((c) => c.y + c.h / 2)),
    ];
    const muat = Math.min(
        1,
        (el.clientWidth - 32) / (x1 - x0),
        (el.clientHeight - 32) / (y1 - y0),
    );
    k.value = batas(Math.max(muat, lantai));
    const [cx, cy] = k.value > muat ? [0, 0] : [(x0 + x1) / 2, (y0 + y1) / 2];
    tx.value = el.clientWidth / 2 - k.value * cx;
    ty.value = el.clientHeight / 2 - k.value * cy;
}
function roda(e: WheelEvent) {
    const r = layar.value!.getBoundingClientRect();
    zoomDi(Math.exp(-e.deltaY * 0.0015), e.clientX - r.left, e.clientY - r.top);
}

const jari = new Map<number, { x: number; y: number }>();
let digeser = false,
    awal = { x: 0, y: 0 };
function turun(e: PointerEvent) {
    jari.set(e.pointerId, { x: e.clientX, y: e.clientY });
    if (jari.size === 1)
        [digeser, awal] = [false, { x: e.clientX, y: e.clientY }];
}
function gerak(e: PointerEvent) {
    const l = jari.get(e.pointerId);
    if (!l) return;
    if (jari.size === 1) {
        tx.value += e.clientX - l.x;
        ty.value += e.clientY - l.y;
        if (Math.hypot(e.clientX - awal.x, e.clientY - awal.y) > 4)
            digeser = true;
    } else {
        const lain = [...jari].find(([id]) => id !== e.pointerId)![1];
        const r = layar.value!.getBoundingClientRect();
        const f =
            Math.hypot(e.clientX - lain.x, e.clientY - lain.y) /
            (Math.hypot(l.x - lain.x, l.y - lain.y) || 1);
        zoomDi(
            f,
            (e.clientX + lain.x) / 2 - r.left,
            (e.clientY + lain.y) / 2 - r.top,
        );
        digeser = true;
    }
    jari.set(e.pointerId, { x: e.clientX, y: e.clientY });
}
const naik = (e: PointerEvent) => jari.delete(e.pointerId);
function klik(s: Simpul) {
    if (!digeser)
        emit(
            'pilih',
            props.dipilih === s.id || s.jenis === 'akar' ? null : s.id,
        );
}

onMounted(() => {
    pas();
    window.addEventListener('pointermove', gerak);
    window.addEventListener('pointerup', naik);
    window.addEventListener('pointercancel', naik);
});
onBeforeUnmount(() => {
    window.removeEventListener('pointermove', gerak);
    window.removeEventListener('pointerup', naik);
    window.removeEventListener('pointercancel', naik);
});
watch(tata, () => nextTick(() => pas()));

const nilaiJual = (s: Simpul) =>
    s.catatan?.match(/nilai jual (\w+)/)?.[1] ?? null;
</script>

<template>
    <figure
        class="relative overflow-hidden rounded-xl border border-border bg-card"
    >
        <div
            ref="layar"
            class="peta-layar relative h-[560px] cursor-grab touch-none select-none active:cursor-grabbing lg:h-[680px]"
            :style="{
                backgroundSize: `${titik}px ${titik}px`,
                backgroundPosition: `${tx}px ${ty}px`,
            }"
            @pointerdown="turun"
            @wheel.prevent="roda"
        >
            <div
                class="absolute top-0 left-0 origin-top-left"
                :style="{
                    transform: `translate(${tx}px, ${ty}px) scale(${k})`,
                }"
            >
                <svg
                    class="pointer-events-none absolute top-0 left-0 overflow-visible"
                    width="1"
                    height="1"
                    aria-hidden="true"
                >
                    <circle
                        v-for="g in tata.kelompok"
                        :key="'k' + g.id"
                        :cx="g.x"
                        :cy="g.y"
                        :r="g.r"
                        class="peta-kelompok"
                    />
                    <g v-for="(g, i) in tata.garis" :key="g.id">
                        <path
                            :d="g.d"
                            class="peta-jalur"
                            :class="{ 'peta-lain': !g.utama }"
                        />
                        <path
                            v-if="g.aktif"
                            :d="g.d"
                            pathLength="100"
                            class="peta-paket"
                            :style="{ animationDelay: (i % 6) * 0.45 + 's' }"
                        />
                    </g>
                </svg>
                <button
                    v-for="c in tata.kartu"
                    :key="c.s.id"
                    type="button"
                    class="absolute flex border text-left shadow-sm transition-colors hover:border-brand-600"
                    :class="[
                        c.daun
                            ? 'items-center gap-1.5 rounded-full px-2.5'
                            : 'flex-col justify-center rounded-lg px-2.5 py-1.5',
                        c.pusat
                            ? 'items-center border-volt bg-brand-50 text-center shadow-lg'
                            : 'bg-card',
                        dipilih === c.s.id
                            ? 'border-volt ring-2 ring-volt/40'
                            : c.pusat
                              ? ''
                              : 'border-border',
                    ]"
                    :style="{
                        left: c.x - c.w / 2 + 'px',
                        top: c.y - c.h / 2 + 'px',
                        width: c.w + 'px',
                        height: c.h + 'px',
                    }"
                    :title="c.s.kata"
                    @click="klik(c.s)"
                >
                    <template v-if="c.s.jenis === 'akar'">
                        <span class="text-xs text-muted-foreground">Situs</span>
                        <span
                            class="max-w-full truncate text-sm font-semibold text-ink"
                            >{{ c.s.kata }}</span
                        >
                    </template>
                    <template v-else-if="c.daun">
                        <span
                            class="size-2 shrink-0 rounded-full"
                            :style="{ background: WARNA[c.s.status!] }"
                        />
                        <span class="truncate text-xs text-foreground">{{
                            c.s.kata
                        }}</span>
                    </template>
                    <template v-else>
                        <span class="flex items-center gap-1.5">
                            <span
                                class="size-2 shrink-0 rounded-full"
                                :style="{ background: WARNA[c.s.status!] }"
                            />
                            <span
                                class="truncate text-xs font-semibold text-foreground"
                                >{{ c.s.kata }}</span
                            >
                            <span
                                v-if="nilaiJual(c.s)"
                                class="ml-auto shrink-0 rounded-full bg-volt/15 px-1.5 text-[10px] font-semibold text-volt-700"
                                >{{ nilaiJual(c.s) }}</span
                            >
                        </span>
                        <span
                            class="truncate text-[10px] text-muted-foreground"
                        >
                            {{ NAMA_STATUS[c.s.status!]
                            }}<template v-if="c.s.volume != null">
                                · vol {{ angka(c.s.volume) }}</template
                            ><template v-if="c.s.kd != null">
                                · KD {{ c.s.kd }}</template
                            ><template v-if="c.s.tayangan">
                                · {{ angka(c.s.tayangan) }} tayang</template
                            >
                        </span>
                        <code
                            v-if="c.s.url"
                            class="truncate text-[10px] text-brand-700"
                            >{{ c.s.url }}</code
                        >
                        <span
                            v-else
                            class="text-[10px] text-muted-foreground/70"
                            >Belum ada halaman</span
                        >
                    </template>
                </button>
            </div>
        </div>
        <div
            class="absolute top-3 right-3 flex overflow-hidden rounded-lg border border-border bg-card text-sm shadow-sm"
        >
            <button
                type="button"
                class="px-3 py-1.5 hover:bg-brand-50"
                aria-label="Perbesar"
                @click="zoomTengah(1.25)"
            >
                +
            </button>
            <button
                type="button"
                class="border-x border-border px-3 py-1.5 hover:bg-brand-50"
                aria-label="Perkecil"
                @click="zoomTengah(0.8)"
            >
                −
            </button>
            <button
                type="button"
                class="px-3 py-1.5 hover:bg-brand-50"
                @click="pas()"
            >
                Pas
            </button>
        </div>
        <p
            class="pointer-events-none absolute bottom-3 left-3 rounded bg-card/80 px-2 py-1 text-xs text-muted-foreground"
        >
            <span class="sm:hidden"
                >Seret · cubit untuk zoom · ketuk kartu</span
            >
            <span class="hidden sm:inline"
                >Seret untuk menggeser · gulir / cubit untuk zoom · klik kartu
                untuk detail</span
            >
        </p>
    </figure>
</template>

<style scoped>
.peta-layar {
    background-image: radial-gradient(
        circle,
        var(--border) 1px,
        transparent 1px
    );
}
.peta-kelompok {
    fill: var(--color-brand-50);
    fill-opacity: 0.35;
    stroke: var(--color-brand-100);
    stroke-dasharray: 3 6;
}
.peta-jalur {
    fill: none;
    stroke: var(--color-brand-100);
    stroke-width: 2;
    vector-effect: non-scaling-stroke;
}
.peta-lain {
    stroke-dasharray: 4 6;
}
.peta-paket {
    fill: none;
    stroke: var(--color-volt-600);
    stroke-width: 3;
    stroke-linecap: round;
    stroke-dasharray: 10 90;
    stroke-dashoffset: 100;
    animation: peta-jalan 2.6s linear infinite;
}
@keyframes peta-jalan {
    to {
        stroke-dashoffset: 0;
    }
}
@media (prefers-reduced-motion: reduce) {
    .peta-paket {
        animation: none;
        stroke-dasharray: none;
        opacity: 0.25;
    }
}
</style>
