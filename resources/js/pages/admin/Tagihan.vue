<script setup lang="ts">
import { Form, Head } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import TagihanController from "@/actions/App/Http/Controllers/Admin/TagihanController";
import DataTable from "@/components/cloud/DataTable.vue";
import FilterStatus from "@/components/cloud/FilterStatus.vue";
import PageHeader from "@/components/cloud/PageHeader.vue";
import StatusBadge from "@/components/cloud/StatusBadge.vue";
import InputError from "@/components/InputError.vue";
import { Button } from "@/components/ui/button";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { rupiah, tanggal } from "@/lib/format";
import admin from "@/routes/admin";
import type { Tagihan } from "@/types";

defineOptions({
    layout: {
        breadcrumbs: [
            { title: "Admin · Tagihan", href: admin.tagihan.index() },
        ],
    },
});
type Angka = { nominal: number; jumlah: number };
const props = defineProps<{
    ringkasan: Record<
        | "tagihan"
        | "belum_dibayar"
        | "dibayar"
        | "dibayar_bulan"
        | "tagihan_bulan"
        | "dibayar_hari"
        | "tagihan_hari",
        Angka
    >;
    status: string;
    jenis: string;
    pilihanJenis: { value: string; label: string }[];
    metode: string;
    pilihanMetode: { value: string; label: string }[];
    tagihan: (Tagihan & {
        pelanggan: string;
        email: string;
        midtrans_mode: "sandbox" | "production" | null;
        // null = tidak dikirim ke CRM (bukan lunas Midtrans / domain)
        crm: {
            terkirim: boolean;
            billing_id: number | null;
            bank_id: number | null;
            pada: string | null;
            galat: string | null;
        } | null;
    })[];
}>();

// Kartu ringkasan: angka besar = nominal, baris kecil = keterangan.
const kartu = computed(() => {
    const r = props.ringkasan;
    return [
        {
            judul: "Total tagihan",
            nilai: r.tagihan.nominal,
            ket: `${r.tagihan.jumlah} tagihan · ${rupiah(r.belum_dibayar.nominal)} belum dibayar`,
        },
        {
            judul: "Total dibayar",
            nilai: r.dibayar.nominal,
            ket: `${r.dibayar.jumlah} tagihan lunas`,
        },
        {
            judul: "Dibayar bulan ini",
            nilai: r.dibayar_bulan.nominal,
            ket: `${r.dibayar_bulan.jumlah} lunas · tagihan baru ${rupiah(r.tagihan_bulan.nominal)}`,
        },
        {
            judul: "Dibayar hari ini",
            nilai: r.dibayar_hari.nominal,
            ket: `${r.dibayar_hari.jumlah} lunas · tagihan baru ${rupiah(r.tagihan_hari.nominal)}`,
        },
    ];
});
const dipilih = ref<(Tagihan & { pelanggan: string }) | null>(null);
const pilihanStatus = [
    { value: "belum_dibayar", label: "Belum dibayar" },
    { value: "lunas", label: "Lunas" },
    { value: "dibatalkan", label: "Dibatalkan" },
];
</script>

<template>
    <Head title="Admin · Tagihan" />
    <div class="space-y-6 p-4 md:p-6">
        <PageHeader
            title="Tagihan"
            description="Tandai lunas setelah pembayaran dikonfirmasi. Layanan app langsung diprovisioning."
        >
            <div class="flex flex-wrap items-center gap-3">
                <FilterStatus
                    :nilai="jenis"
                    :pilihan="pilihanJenis"
                    param="jenis"
                    judul="Jenis produk"
                />
                <FilterStatus
                    :nilai="metode"
                    :pilihan="pilihanMetode"
                    param="metode"
                    judul="Metode"
                />
                <FilterStatus :nilai="status" :pilihan="pilihanStatus" />
            </div>
        </PageHeader>
        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            <div
                v-for="k in kartu"
                :key="k.judul"
                class="rounded-lg border border-border bg-white p-4"
            >
                <p class="text-xs text-muted-foreground">{{ k.judul }}</p>
                <p
                    class="mt-1 font-heading text-xl font-bold text-ink tabular-nums md:text-2xl"
                >
                    {{ rupiah(k.nilai) }}
                </p>
                <p class="mt-1 text-xs text-muted-foreground tabular-nums">
                    {{ k.ket }}
                </p>
            </div>
        </div>
        <DataTable
            :kosong="!tagihan.length"
            pesan-kosong="Tidak ada tagihan dengan filter ini."
        >
            <thead>
                <tr>
                    <th>Nomor</th>
                    <th>Pelanggan</th>
                    <th>Layanan</th>
                    <th>Jatuh tempo</th>
                    <th>Tanggal dibayar</th>
                    <th>Status</th>
                    <th class="text-right">Jumlah</th>
                    <th><span class="sr-only">Aksi</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="t in tagihan" :key="t.id">
                    <td class="font-semibold text-ink">{{ t.nomor }}</td>
                    <td>
                        {{ t.pelanggan
                        }}<span class="block text-xs text-muted-foreground">{{
                            t.email
                        }}</span>
                    </td>
                    <td>{{ t.layanan }}</td>
                    <td>{{ tanggal(t.jatuh_tempo) }}</td>
                    <td>
                        <template v-if="t.dibayar_pada"
                            >{{ tanggal(t.dibayar_pada.slice(0, 10)) }}
                            <span class="block text-xs text-muted-foreground"
                                >{{ t.dibayar_pada.slice(11, 16) }} ·
                                {{ t.metode }}
                                <span
                                    v-if="t.midtrans_mode"
                                    :class="
                                        t.midtrans_mode === 'sandbox'
                                            ? 'font-semibold text-amber-600'
                                            : 'text-emerald-700'
                                    "
                                    >·
                                    {{
                                        t.midtrans_mode === "sandbox"
                                            ? "Sandbox"
                                            : "Production"
                                    }}</span
                                ></span
                            ></template
                        >
                        <template v-else>-</template>
                    </td>
                    <td>
                        <StatusBadge
                            :status="t.status"
                            :label="t.status_label"
                        />
                        <span
                            v-if="t.crm"
                            class="mt-1 block text-xs"
                            :class="
                                t.crm.terkirim
                                    ? 'text-muted-foreground'
                                    : t.crm.galat
                                      ? 'text-destructive'
                                      : 'text-amber-600'
                            "
                            :title="
                                t.crm.terkirim
                                    ? `Billing #${t.crm.billing_id} · Bank #${t.crm.bank_id} · ${t.crm.pada}`
                                    : (t.crm.galat ?? '')
                            "
                            >{{
                                t.crm.terkirim
                                    ? "Terkirim CRM"
                                    : t.crm.galat
                                      ? "Gagal kirim CRM"
                                      : "Belum ke CRM"
                            }}</span
                        >
                    </td>
                    <td class="text-right font-semibold text-ink">
                        {{ rupiah(t.total) }}
                    </td>
                    <td class="text-right">
                        <Button
                            v-if="t.status === 'belum_dibayar'"
                            size="sm"
                            variant="outline"
                            @click="dipilih = t"
                            >Tandai lunas</Button
                        >
                        <Form
                            v-else-if="t.crm && !t.crm.terkirim"
                            v-bind="TagihanController.kirimCrm.form(t.id)"
                            v-slot="{ processing }"
                        >
                            <Button
                                type="submit"
                                size="sm"
                                variant="outline"
                                :disabled="processing"
                                >{{
                                    t.crm.galat ? "Kirim ulang" : "Kirim ke CRM"
                                }}</Button
                            >
                        </Form>
                    </td>
                </tr>
            </tbody>
        </DataTable>

        <Dialog
            :open="dipilih !== null"
            @update:open="(v) => !v && (dipilih = null)"
        >
            <DialogContent v-if="dipilih">
                <DialogHeader>
                    <DialogTitle>Tandai {{ dipilih.nomor }} lunas</DialogTitle>
                    <DialogDescription
                        >{{ dipilih.pelanggan }} · {{ rupiah(dipilih.total) }}.
                        Pastikan dana sudah masuk.</DialogDescription
                    >
                </DialogHeader>
                <Form
                    v-bind="TagihanController.lunas.form(dipilih.id)"
                    class="space-y-4"
                    v-slot="{ errors, processing }"
                    @success="dipilih = null"
                >
                    <div class="grid gap-2">
                        <Label for="metode">Metode</Label>
                        <select
                            id="metode"
                            name="metode"
                            class="h-9 rounded-md border border-input bg-white px-3 text-sm"
                            required
                        >
                            <option value="transfer">Transfer bank</option>
                            <option value="qris">QRIS</option>
                            <option value="tunai">Tunai</option>
                            <option value="lainnya">Lainnya</option>
                        </select>
                        <InputError :message="errors.metode" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="referensi">Referensi (opsional)</Label>
                        <Input
                            id="referensi"
                            name="referensi"
                            maxlength="100"
                            placeholder="Nomor transaksi / catatan"
                        />
                    </div>
                    <DialogFooter
                        ><Button type="submit" :disabled="processing"
                            >Tandai Lunas</Button
                        ></DialogFooter
                    >
                </Form>
            </DialogContent>
        </Dialog>
    </div>
</template>
