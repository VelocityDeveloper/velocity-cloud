<script setup lang="ts">
// Latar grid piksel bergelombang: menyala mengikuti kursor + gelombang kejut saat klik/tap.
// Port dari landing (src/components/PixelWave.astro); induk wajib `relative overflow-hidden`.
import { onBeforeUnmount, onMounted, ref } from 'vue';

const cv = ref<HTMLCanvasElement | null>(null);
let bersihkan = () => {};

onMounted(() => {
    const kanvas = cv.value;
    const ctx = kanvas?.getContext('2d');
    const induk = kanvas?.parentElement;
    if (!kanvas || !ctx || !induk) return;

    const JARAK = 16, RADIUS = 180, UMUR = 1600;
    const VOLT = [116, 202, 51];
    const diam = matchMedia('(prefers-reduced-motion: reduce)').matches;
    const klik: { x: number; y: number; t: number }[] = [];
    let w = 0, h = 0, mx = -9999, my = -9999, kuat = 0, terlihat = true, raf = 0;

    const ukur = () => {
        const dpr = Math.min(devicePixelRatio || 1, 2);
        w = induk.clientWidth; h = induk.clientHeight;
        kanvas.width = w * dpr; kanvas.height = h * dpr;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    };

    const gambar = (t: number) => {
        ctx.clearRect(0, 0, w, h);
        const dasar = document.documentElement.classList.contains('dark') ? [148, 163, 184] : [15, 70, 102];
        kuat += ((mx > -999 ? 1 : 0) - kuat) * 0.08;
        while (klik.length && t - klik[0].t > UMUR) klik.shift();
        for (let y = JARAK / 2; y < h; y += JARAK) {
            for (let x = JARAK / 2; x < w; x += JARAK) {
                const gel = (Math.sin(x * 0.012 + y * 0.008 - t * 0.0012) + 1) / 2;
                const d = Math.hypot(x - mx, y - my);
                let dekat = d < RADIUS ? (1 - d / RADIUS) ** 1.5 * kuat : 0;
                for (const k of klik) {
                    const umur = t - k.t, depan = umur * 0.7;
                    const pita = 1 - Math.abs(Math.hypot(x - k.x, y - k.y) - depan) / 40;
                    if (pita > 0) dekat += pita * (1 - umur / UMUR);
                }
                dekat = Math.min(dekat, 1);
                const riak = dekat ? (Math.sin(d * 0.08 - t * 0.006) + 1) / 2 : 0;
                const a = 0.06 + gel * 0.12 + dekat * (0.35 + riak * 0.4);
                const s = 1.5 + gel * 1.5 + dekat * 3;
                const c = dekat > 0.15 ? VOLT : dasar;
                ctx.fillStyle = `rgba(${c[0]},${c[1]},${c[2]},${a})`;
                ctx.fillRect(x - s / 2, y - s / 2, s, s);
            }
        }
    };

    const loop = (t: number) => { gambar(t); raf = terlihat ? requestAnimationFrame(loop) : 0; };
    const mulai = () => { if (!raf && !diam) raf = requestAnimationFrame(loop); };
    const posisi = (e: PointerEvent) => {
        const r = induk.getBoundingClientRect();
        return { x: e.clientX - r.left, y: e.clientY - r.top };
    };
    const gerak = (e: PointerEvent) => { ({ x: mx, y: my } = posisi(e)); };
    const tekan = (e: PointerEvent) => { klik.push({ ...posisi(e), t: performance.now() }); if (klik.length > 6) klik.shift(); };
    const keluar = () => { mx = my = -9999; };

    ukur();
    const ro = new ResizeObserver(() => { ukur(); if (diam) gambar(0); });
    ro.observe(induk);
    const io = new IntersectionObserver(([en]) => { terlihat = en.isIntersecting; if (terlihat) mulai(); });
    if (diam) gambar(0);
    else {
        induk.addEventListener('pointermove', gerak, { passive: true });
        induk.addEventListener('pointerdown', tekan, { passive: true });
        induk.addEventListener('pointerleave', keluar);
        io.observe(induk);
        mulai();
    }
    bersihkan = () => {
        cancelAnimationFrame(raf); raf = 0; terlihat = false;
        ro.disconnect(); io.disconnect();
        induk.removeEventListener('pointermove', gerak);
        induk.removeEventListener('pointerdown', tekan);
        induk.removeEventListener('pointerleave', keluar);
    };
});
onBeforeUnmount(() => bersihkan());
</script>

<template>
    <canvas ref="cv" aria-hidden="true" class="pointer-events-none absolute inset-0 size-full" />
</template>
