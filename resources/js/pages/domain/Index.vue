<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import DomainController from '@/actions/App/Http/Controllers/DomainController';
import PageHeader from '@/components/cloud/PageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { rupiah } from '@/lib/format';

type Kontak = { nama?: string; email?: string; hp?: string; perusahaan?: string; alamat?: string; kota?: string; provinsi?: string; kode_pos?: string };
type Info = {
    domain: string;
    tld: string;
    tersedia: boolean | null;
    tercatat: boolean;
    harga: { tahun: number; register: number | null; transfer: number | null }[];
};

const props = defineProps<{ kontak: Kontak; tld: string[]; q: string }>();

defineOptions({ layout: { breadcrumbs: [{ title: 'Pesan Domain', href: '#' }] } });

const cari = ref('');
const memuat = ref(false);
const galat = ref('');
const info = ref<Info | null>(null);
const jenis = ref<'Register' | 'Transfer'>('Register');
const periode = ref(1);

async function cek() {
    galat.value = '';
    info.value = null;
    if (!cari.value.trim()) return;
    memuat.value = true;
    try {
        const res = await fetch(DomainController.cek.url({ query: { domain: cari.value.trim() } }), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await res.json();
        if (!res.ok) {
            galat.value = data.errors?.domain?.[0] ?? data.message ?? 'Cek domain gagal.';
            return;
        }
        info.value = data;
        jenis.value = data.tersedia === false ? 'Transfer' : 'Register';
    } catch {
        galat.value = 'Koneksi terputus, coba lagi.';
    } finally {
        memuat.value = false;
    }
}

// Dari landing /domain/: "toko.com" = langsung dicek; ".com" (tombol Pesan per ekstensi) = contoh di placeholder.
const placeholder = ref('tokoanda.com');
onMounted(() => {
    const q = props.q.trim();
    if (q.startsWith('.')) {
        placeholder.value = `tokoanda${q}`;
        document.getElementById('cari')?.focus();
    } else if (q) {
        cari.value = q;
        cek();
    }
});

const kunci = computed(() => (jenis.value === 'Transfer' ? 'transfer' : 'register'));
const pilihan = computed(() => (info.value?.harga ?? []).filter((h) => h[kunci.value] != null));
const total = computed(() => pilihan.value.find((h) => h.tahun === periode.value)?.[kunci.value] ?? null);
watch(pilihan, (p) => {
    if (!p.some((h) => h.tahun === periode.value)) periode.value = p[0]?.tahun ?? 1;
});

// Daftar baru hanya bila belum terdaftar; transfer hanya bila sudah terdaftar. Tidak bisa dicek (null) = keduanya boleh.
const bolehRegister = computed(() => info.value?.tersedia !== false);
const bolehTransfer = computed(() => info.value?.tersedia !== true);
const bisaPesan = computed(() => info.value && !info.value.tercatat && total.value != null);

const kolomKontak: { nama: keyof Kontak; label: string; tipe?: string; auto?: string; opsional?: boolean }[] = [
    { nama: 'nama', label: 'Nama lengkap', auto: 'name' },
    { nama: 'email', label: 'Email', tipe: 'email', auto: 'email' },
    { nama: 'hp', label: 'Nomor HP/WhatsApp', tipe: 'tel', auto: 'tel' },
    { nama: 'perusahaan', label: 'Perusahaan (opsional)', auto: 'organization', opsional: true },
    { nama: 'alamat', label: 'Alamat', auto: 'street-address' },
    { nama: 'kota', label: 'Kota/Kabupaten', auto: 'address-level2' },
    { nama: 'provinsi', label: 'Provinsi', auto: 'address-level1' },
    { nama: 'kode_pos', label: 'Kode pos', auto: 'postal-code' },
];
</script>

<template>
    <Head title="Pesan Domain" />
    <div class="grid gap-8 p-4 md:p-6 lg:grid-cols-[1fr_20rem] [&>*]:min-w-0">
        <div class="max-w-xl min-w-0 space-y-6">
            <PageHeader title="Pesan Domain" description="Daftar domain baru atau transfer domain Anda ke Velocity." />

            <form class="grid gap-2" @submit.prevent="cek">
                <Label for="cari">Nama domain</Label>
                <div class="flex gap-2">
                    <Input
                        id="cari"
                        v-model="cari"
                        :placeholder="placeholder"
                        autocomplete="off"
                        autocapitalize="none"
                        spellcheck="false"
                        required
                        class="max-w-80"
                    />
                    <Button type="submit" :disabled="memuat">{{ memuat ? 'Mengecek…' : 'Cek' }}</Button>
                </div>
                <p v-if="tld.length" class="text-xs text-muted-foreground">Ekstensi tersedia: {{ tld.join(', ') }}</p>
                <p v-else class="text-sm text-muted-foreground">Belum ada ekstensi domain yang dijual.</p>
                <p v-if="galat" class="text-sm text-destructive" role="alert">{{ galat }}</p>
            </form>

            <div v-if="info" aria-live="polite" class="rounded-md border border-border p-4 text-sm">
                <p class="font-semibold text-ink break-all">{{ info.domain }}</p>
                <p v-if="info.tercatat" class="mt-1 text-destructive">
                    Domain ini sudah tercatat sebagai layanan Velocity. Hubungi support bila ini domain Anda.
                </p>
                <p v-else-if="info.tersedia === true" class="mt-1 text-green-700">Tersedia, bisa didaftarkan.</p>
                <p v-else-if="info.tersedia === false" class="mt-1 text-muted-foreground">
                    Sudah terdaftar. Bila domain ini milik Anda, pindahkan ke Velocity lewat transfer.
                </p>
                <p v-else class="mt-1 text-muted-foreground">Ketersediaan belum bisa dicek otomatis, tim kami akan memastikan.</p>
            </div>

            <Form
                v-if="info && !info.tercatat"
                v-bind="DomainController.store.form()"
                class="space-y-6"
                v-slot="{ errors, processing }"
            >
                <input type="hidden" name="domain" :value="info.domain" />
                <InputError :message="errors.domain" />

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium text-ink">Jenis pesanan</legend>
                    <div class="grid grid-cols-2 gap-2">
                        <label
                            v-for="j in [
                                { v: 'Register', t: 'Daftar baru', boleh: bolehRegister },
                                { v: 'Transfer', t: 'Transfer masuk', boleh: bolehTransfer },
                            ]"
                            :key="j.v"
                            class="cursor-pointer rounded-md border border-input px-3 py-2.5 transition-colors hover:border-brand-600 has-[:checked]:border-brand-700 has-[:checked]:bg-brand-50 has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-50 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring"
                        >
                            <input v-model="jenis" type="radio" name="jenis" :value="j.v" :disabled="!j.boleh" class="sr-only" />
                            <span class="font-medium text-ink">{{ j.t }}</span>
                        </label>
                    </div>
                </fieldset>

                <div class="grid gap-2">
                    <Label for="periode">Lama registrasi</Label>
                    <select
                        id="periode"
                        v-model.number="periode"
                        name="periode"
                        class="h-9 max-w-60 rounded-md border border-input bg-transparent px-3 text-sm"
                    >
                        <option v-for="h in pilihan" :key="h.tahun" :value="h.tahun">
                            {{ h.tahun }} tahun · {{ rupiah(h[kunci] ?? 0) }}
                        </option>
                    </select>
                    <p v-if="!pilihan.length" class="text-sm text-destructive">Jenis pesanan ini belum dijual untuk ekstensi {{ info.tld }}.</p>
                    <InputError :message="errors.periode" />
                </div>

                <div v-if="jenis === 'Transfer'" class="grid gap-2">
                    <Label for="epp">Kode EPP / auth code</Label>
                    <Input id="epp" name="epp" required autocomplete="off" spellcheck="false" class="max-w-80 font-mono" />
                    <p class="text-xs text-muted-foreground">Minta kode ini di registrar lama, dan pastikan domain tidak terkunci.</p>
                    <InputError :message="errors.epp" />
                </div>

                <fieldset class="grid gap-4">
                    <legend class="mb-1 text-sm font-medium text-ink">Data pemilik domain</legend>
                    <p class="-mt-2 text-xs text-muted-foreground">Dipakai sebagai kontak WHOIS di registrar dan penerima tagihan perpanjangan.</p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div
                            v-for="k in kolomKontak"
                            :key="k.nama"
                            class="grid gap-2"
                            :class="{ 'sm:col-span-2': k.nama === 'alamat' }"
                        >
                            <Label :for="`kontak-${k.nama}`">{{ k.label }}</Label>
                            <Input
                                :id="`kontak-${k.nama}`"
                                :name="`kontak[${k.nama}]`"
                                :type="k.tipe ?? 'text'"
                                :autocomplete="k.auto"
                                :default-value="props.kontak[k.nama] ?? ''"
                                :required="!k.opsional"
                                :inputmode="k.nama === 'kode_pos' ? 'numeric' : undefined"
                            />
                            <InputError :message="errors[`kontak.${k.nama}`]" />
                        </div>
                    </div>
                </fieldset>

                <Button type="submit" :disabled="processing || !bisaPesan">Buat Pesanan</Button>
            </Form>
        </div>

        <aside class="h-fit rounded-lg border border-border p-5 text-sm lg:sticky lg:top-6">
            <p class="font-semibold text-brand-700">Ringkasan</p>
            <template v-if="info && total != null">
                <p class="mt-2 break-all text-ink">{{ info.domain }}</p>
                <p class="text-muted-foreground">{{ jenis === 'Transfer' ? 'Transfer masuk' : 'Daftar baru' }} · {{ periode }} tahun</p>
                <p class="font-heading mt-3 text-2xl font-bold text-ink tabular-nums">{{ rupiah(total) }}</p>
                <p class="mt-3 text-xs text-muted-foreground">
                    Domain didaftarkan otomatis setelah pembayaran lunas. Tagihan perpanjangan berikutnya dikirim ke email pemilik domain.
                </p>
            </template>
            <p v-else class="mt-2 text-muted-foreground">Cek nama domain untuk melihat harga.</p>
        </aside>
    </div>
</template>
