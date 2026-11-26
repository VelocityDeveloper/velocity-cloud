<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Bell, Gift, Megaphone } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

// Dari HandleInertiaRequests: 10 notifikasi terbaru + jumlah belum dibaca.
type Notifikasi = { id: string; jenis: string; judul: string; isi: string; url: string | null; dibaca: boolean; waktu: string };
const page = usePage();
const data = computed(() => page.props.notifikasi as { belum: number; daftar: Notifikasi[] } | null);
const belum = computed(() => data.value?.belum ?? 0);

const waktu = (iso: string) =>
    new Date(iso).toLocaleString('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
const buka = (n: Notifikasi) => router.post(`/notifikasi/${n.id}/baca`);
const bacaSemua = () => router.post('/notifikasi/baca-semua', {}, { preserveScroll: true });
</script>

<template>
    <DropdownMenu v-if="data">
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="relative"
                :aria-label="belum ? `Notifikasi, ${belum} belum dibaca` : 'Notifikasi'"
            >
                <Bell aria-hidden="true" />
                <span
                    v-if="belum"
                    class="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] leading-none font-semibold text-white tabular-nums"
                    aria-hidden="true"
                    >{{ belum > 9 ? '9+' : belum }}</span
                >
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-80">
            <div class="flex items-center justify-between gap-2 pr-1">
                <DropdownMenuLabel>Notifikasi</DropdownMenuLabel>
                <button
                    v-if="belum"
                    type="button"
                    class="text-xs font-semibold text-brand-700 hover:text-brand-600"
                    @click="bacaSemua"
                >
                    Tandai semua dibaca
                </button>
            </div>
            <DropdownMenuSeparator />
            <p v-if="!data.daftar.length" class="px-2 py-6 text-center text-sm text-muted-foreground">Belum ada notifikasi.</p>
            <DropdownMenuItem
                v-for="n in data.daftar"
                :key="n.id"
                class="items-start gap-3 py-2"
                @select="buka(n)"
            >
                <component :is="n.jenis === 'bonus' ? Gift : Megaphone" class="mt-0.5 size-4 shrink-0 text-brand-700" aria-hidden="true" />
                <span class="min-w-0 flex-1">
                    <span class="block text-sm" :class="n.dibaca ? 'text-muted-foreground' : 'font-semibold text-ink'">{{ n.judul }}</span>
                    <span class="mt-0.5 block text-xs text-muted-foreground">{{ n.isi }}</span>
                    <span class="mt-1 block text-xs text-muted-foreground">{{ waktu(n.waktu) }}</span>
                </span>
                <span v-if="!n.dibaca" class="mt-1.5 size-2 shrink-0 rounded-full bg-brand-600" aria-label="Belum dibaca" />
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
