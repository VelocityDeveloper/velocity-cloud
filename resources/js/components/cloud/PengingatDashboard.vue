<script setup lang="ts">
import { AlertTriangle, BellRing, Coins, X } from '@lucide/vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Pengingat dari server (HandleInertiaRequests → DaftarPengingat): jatuh tempo H-7, tagihan tertunda, saldo token menipis.
export type Pengingat = { jenis: 'jatuh_tempo' | 'tertunda' | 'token'; tingkat: 'peringatan' | 'bahaya'; judul: string; pesan: string; url: string };

const MAKS = 3;
const page = usePage();
const semua = computed(() => ((page.props.pengingat as Pengingat[] | undefined) ?? []).filter((p) => !tutup.value.includes(kunci(p))));

// ditutup hanya untuk sesi browser ini; muncul lagi di sesi berikutnya selama masalahnya belum selesai
const SIMPAN = 'pengingat-ditutup';
const kunci = (p: Pengingat) => `${p.url}|${p.judul}`;
const tutup = ref<string[]>(JSON.parse((typeof sessionStorage !== 'undefined' && sessionStorage.getItem(SIMPAN)) || '[]'));
const sembunyikan = (p: Pengingat) => {
    tutup.value = [...tutup.value, kunci(p)];
    sessionStorage.setItem(SIMPAN, JSON.stringify(tutup.value));
};

const tampil = computed(() => semua.value.slice(0, MAKS));
const ikon = (p: Pengingat) => (p.jenis === 'token' ? Coins : p.tingkat === 'bahaya' ? AlertTriangle : BellRing);
</script>

<template>
    <div v-if="tampil.length" class="flex flex-col gap-2 px-4 pt-4" role="region" aria-label="Pengingat">
        <div
            v-for="p in tampil"
            :key="kunci(p)"
            class="flex items-start gap-3 rounded-lg border px-3 py-2.5 text-sm"
            :class="p.tingkat === 'bahaya' ? 'border-destructive/40 bg-destructive/10' : 'border-amber-400/50 bg-amber-50 dark:bg-amber-950/30'"
        >
            <component
                :is="ikon(p)"
                class="mt-0.5 size-4 shrink-0"
                :class="p.tingkat === 'bahaya' ? 'text-destructive' : 'text-amber-600'"
                aria-hidden="true"
            />
            <div class="min-w-0 flex-1">
                <p class="font-medium">{{ p.judul }}</p>
                <p class="text-muted-foreground">{{ p.pesan }}</p>
            </div>
            <Link :href="p.url" class="shrink-0 self-center rounded-md border bg-background px-2.5 py-1 text-xs font-medium hover:bg-muted">
                {{ p.jenis === 'token' ? 'Isi ulang' : 'Bayar' }}
            </Link>
            <button type="button" class="shrink-0 self-center text-muted-foreground hover:text-foreground" aria-label="Tutup pengingat" @click="sembunyikan(p)">
                <X class="size-4" />
            </button>
        </div>
        <Link v-if="semua.length > MAKS" href="/tagihan" class="text-xs text-muted-foreground underline underline-offset-2">
            {{ semua.length - MAKS }} pengingat lain, lihat semua tagihan
        </Link>
    </div>
</template>
