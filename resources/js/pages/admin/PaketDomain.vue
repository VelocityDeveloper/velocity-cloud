<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import PaketDomainController from '@/actions/App/Http/Controllers/Admin/PaketDomainController';
import DataTable from '@/components/cloud/DataTable.vue';
import PageHeader from '@/components/cloud/PageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { rupiah } from '@/lib/format';

type Tld = {
    tld: string;
    registrar: string | null;
    aktif: boolean;
    harga: { tahun: number; register: number | null; transfer: number | null }[];
};
const props = defineProps<{ tld: Tld[]; tidak_di_crm: string[]; galat: string | null }>();

// Saklar on/off langsung tersimpan (seperti Admin › Paket): kirim daftar TLD aktif sesudah diubah.
const menyimpan = ref<string | null>(null);
const jumlahAktif = computed(() => props.tld.filter((t) => t.aktif).length);
const galatSimpan = computed(() => usePage().props.errors?.tld);
function saklar(t: Tld) {
    menyimpan.value = t.tld;
    const aktif = props.tld.filter((x) => (x.tld === t.tld ? !x.aktif : x.aktif)).map((x) => x.tld);
    router.put(PaketDomainController.update.url(), { tld: aktif }, { preserveScroll: true, onFinish: () => (menyimpan.value = null) });
}
const cari = ref('');
const tampil = computed(() => props.tld.filter((t) => t.tld.includes(cari.value.trim().toLowerCase())));
const tahun1 = (t: Tld) => t.harga.find((h) => h.tahun === 1) ?? t.harga[0];
</script>

<template>
    <Head title="Admin · Paket Domain" />
    <div class="space-y-6 p-4 md:p-6">
        <PageHeader
            title="Domain"
            description="Ekstensi yang dijual di Pesan Domain. Daftar dan harga diambil dari Harga Domain CRM; mematikan di sini tidak mengubah CRM."
        />

        <p v-if="galat" class="text-sm text-destructive" role="alert">{{ galat }}</p>
        <p v-if="tidak_di_crm.length" class="rounded-md bg-amber-50 px-3 py-2 text-sm text-ink">
            Dinyalakan tapi sudah tidak dijual di CRM (tidak bisa dipesan): {{ tidak_di_crm.join(', ') }}
        </p>

        <div class="flex flex-wrap items-center gap-3 text-sm">
            <Input v-model="cari" placeholder="Cari ekstensi, mis. .id" class="max-w-60" aria-label="Cari ekstensi" />
            <span class="text-muted-foreground tabular-nums">{{ jumlahAktif }} dari {{ tld.length }} dijual</span>
        </div>
        <InputError :message="galatSimpan" />

        <DataTable>
            <thead>
                <tr>
                    <th scope="col">Dijual</th>
                    <th scope="col">Ekstensi</th>
                    <th scope="col">Registrar</th>
                    <th scope="col" class="text-right">Daftar baru</th>
                    <th scope="col" class="text-right">Transfer</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="t in tampil" :key="t.tld">
                    <td>
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="t.aktif"
                            :aria-label="`${t.tld}: ${t.aktif ? 'on' : 'off'}`"
                            :disabled="menyimpan !== null"
                            class="inline-flex items-center gap-2 text-xs font-semibold disabled:opacity-50"
                            @click="saklar(t)"
                        >
                            <span
                                class="relative inline-block h-5 w-9 rounded-full transition-colors"
                                :class="t.aktif ? 'bg-brand-700' : 'bg-muted-foreground/30'"
                            >
                                <span
                                    class="absolute top-0.5 left-0.5 size-4 rounded-full bg-white transition-transform"
                                    :class="t.aktif ? 'translate-x-4' : ''"
                                />
                            </span>
                            {{ t.aktif ? 'On' : 'Off' }}
                        </button>
                    </td>
                    <th scope="row" class="font-mono text-ink">{{ t.tld }}</th>
                    <td class="text-muted-foreground">{{ t.registrar ?? 'manual' }}</td>
                    <td class="text-right tabular-nums">
                        <template v-if="tahun1(t)?.register != null">
                            {{ rupiah(tahun1(t)!.register!) }}<span class="text-muted-foreground">/{{ tahun1(t)!.tahun }} th</span>
                        </template>
                        <span v-else class="text-muted-foreground">-</span>
                    </td>
                    <td class="text-right tabular-nums">
                        <template v-if="tahun1(t)?.transfer != null">
                            {{ rupiah(tahun1(t)!.transfer!) }}<span class="text-muted-foreground">/{{ tahun1(t)!.tahun }} th</span>
                        </template>
                        <span v-else class="text-muted-foreground">-</span>
                    </td>
                </tr>
                <tr v-if="!tampil.length">
                    <td colspan="5" class="text-center text-muted-foreground">Tidak ada ekstensi.</td>
                </tr>
            </tbody>
        </DataTable>
        <p class="text-xs text-muted-foreground">
            Registrar "manual" = domain tidak didaftarkan otomatis; tim mendaftarkannya di CRM setelah lunas.
        </p>
    </div>
</template>
