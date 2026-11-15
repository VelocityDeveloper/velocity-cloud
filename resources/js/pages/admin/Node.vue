<script setup lang="ts">
import { Head, router, useForm } from "@inertiajs/vue3";
import { PlugZap } from "@lucide/vue";
import { ref } from "vue";
import NodeController from "@/actions/App/Http/Controllers/Admin/NodeController";
import DataTable from "@/components/cloud/DataTable.vue";
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
import admin from "@/routes/admin";

type NodeRow = {
    id: number;
    nama: string;
    jenis: "pod" | "incus" | "directadmin";
    agent_url: string;
    da_user: string | null;
    kapasitas_pod: number;
    ram_pod_mb: number | null;
    aktif: boolean;
    services_count: number;
};
defineOptions({
    layout: {
        breadcrumbs: [{ title: "Admin · Node", href: admin.node.index() }],
    },
});
const props = defineProps<{
    node: NodeRow[];
    jenis: Record<NodeRow["jenis"], string>;
}>();

const edit = ref<Partial<NodeRow> | null>(null);
// dipisah: vue-tsc salah baca "//" di atribut template
const contohUrl = {
    pod: "http" + "://10.0.0.5:8700",
    directadmin: "https" + "://sanke.velocity.ai.id:2222",
    incus: "https" + "://192.168.72.50:8443",
};
const f = useForm({
    nama: "",
    jenis: "pod" as NodeRow["jenis"],
    agent_url: "",
    da_user: "",
    token: "",
    kapasitas_pod: 20,
    ram_pod_mb: null as number | null,
    aktif: true,
});

function buka(n: Partial<NodeRow>) {
    f.clearErrors();
    f.defaults({
        nama: n.nama ?? "",
        jenis: n.jenis ?? "pod",
        agent_url: n.agent_url ?? "",
        da_user: n.da_user ?? "",
        token: "",
        kapasitas_pod: n.kapasitas_pod ?? 20,
        ram_pod_mb: n.ram_pod_mb ?? null,
        aktif: n.aktif ?? true,
    });
    f.reset();
    edit.value = n;
}

function simpan() {
    const tujuan = edit.value?.id
        ? NodeController.update(edit.value.id)
        : NodeController.store();
    f.submit(tujuan, { onSuccess: () => (edit.value = null) });
}

const menguji = ref<number | null>(null);
function uji(n: NodeRow) {
    menguji.value = n.id;
    router.post(
        NodeController.uji(n.id).url,
        {},
        { preserveScroll: true, onFinish: () => (menguji.value = null) },
    );
}
</script>

<template>
    <Head title="Admin · Node" />
    <div class="space-y-6 p-4 md:p-6">
        <PageHeader
            title="Node"
            description="Server app pelanggan: pod lewat agent (Bun + Elysia + Podman), atau akun hosting DirectAdmin untuk app PHP (WordPress, Laravel)."
        >
            <Button @click="buka({ kapasitas_pod: 20, aktif: true })"
                >Tambah Node</Button
            >
        </PageHeader>
        <DataTable
            :kosong="!node.length"
            pesan-kosong="Belum ada node. App yang lunas akan gagal disiapkan sampai ada node aktif."
        >
            <thead>
                <tr>
                    <th>Nama</th>
                    <th>Jenis</th>
                    <th>URL</th>
                    <th class="text-right">Terpakai</th>
                    <th>Status</th>
                    <th><span class="sr-only">Aksi</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="n in node" :key="n.id">
                    <td class="font-semibold text-ink">{{ n.nama }}</td>
                    <td>
                        {{ { directadmin: "DirectAdmin", incus: "VPS (Incus)", pod: "Pod" }[n.jenis] }}
                    </td>
                    <td class="font-mono text-xs">
                        {{ n.agent_url
                        }}<span
                            v-if="n.da_user"
                            class="block text-muted-foreground"
                            >user {{ n.da_user }}</span
                        >
                    </td>
                    <td class="text-right">
                        {{ n.services_count }} / {{ n.kapasitas_pod }}<span
                            v-if="n.ram_pod_mb"
                            class="block text-xs text-muted-foreground"
                            >RAM maks {{ n.ram_pod_mb }} MB</span
                        >
                    </td>
                    <td>
                        <StatusBadge
                            :status="n.aktif ? 'aktif' : 'nonaktif'"
                            :label="n.aktif ? 'Aktif' : 'Nonaktif'"
                        />
                    </td>
                    <td class="space-x-2 text-right">
                        <Button
                            size="icon-sm"
                            variant="outline"
                            :aria-label="`Uji koneksi ${n.nama}`"
                            title="Uji koneksi"
                            :disabled="menguji === n.id"
                            @click="uji(n)"
                            ><PlugZap class="size-4" aria-hidden="true"
                        /></Button>
                        <Button size="sm" variant="outline" @click="buka(n)"
                            >Ubah</Button
                        >
                    </td>
                </tr>
            </tbody>
        </DataTable>

        <Dialog :open="edit !== null" @update:open="(v) => !v && (edit = null)">
            <DialogContent v-if="edit">
                <DialogHeader>
                    <DialogTitle>{{
                        edit.id ? "Ubah node" : "Tambah node"
                    }}</DialogTitle>
                    <DialogDescription>{{
                        f.jenis === "directadmin"
                            ? "Token = login key DirectAdmin milik user di bawah, batasi perintah akun & IP kantor."
                            : f.jenis === "incus"
                              ? "Tempel sertifikat server yang dicetak pasang-node.sh. Kapasitas = jumlah VPS maksimal di node ini."
                              : "Token harus sama dengan AGENT_TOKEN di server agent."
                    }}</DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="simpan">
                    <div class="grid gap-2">
                        <Label for="nama">Nama</Label>
                        <Input
                            id="nama"
                            v-model="f.nama"
                            required
                            placeholder="node-jkt-1"
                        />
                        <InputError :message="f.errors.nama" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="jenis">Jenis</Label>
                        <select
                            id="jenis"
                            v-model="f.jenis"
                            class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                        >
                            <option
                                v-for="(nama, kunci) in props.jenis"
                                :key="kunci"
                                :value="kunci"
                            >
                                {{ nama }}
                            </option>
                        </select>
                        <InputError :message="f.errors.jenis" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="agent_url">{{
                            f.jenis === "directadmin"
                                ? "URL panel DirectAdmin"
                                : "URL agent"
                        }}</Label>
                        <Input
                            id="agent_url"
                            v-model="f.agent_url"
                            type="url"
                            required
                            :placeholder="contohUrl[f.jenis]"
                        />
                        <InputError :message="f.errors.agent_url" />
                    </div>
                    <div v-if="f.jenis === 'directadmin'" class="grid gap-2">
                        <Label for="da_user">User DirectAdmin</Label>
                        <Input
                            id="da_user"
                            v-model="f.da_user"
                            required
                            placeholder="admin"
                            autocomplete="off"
                        />
                        <InputError :message="f.errors.da_user" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="token">{{
                            (f.jenis === "incus" ? "Sertifikat server" : "Token") +
                            (edit.id ? ", kosongkan jika tidak diganti" : "")
                        }}</Label>
                        <textarea
                            v-if="f.jenis === 'incus'"
                            id="token"
                            v-model="f.token"
                            :required="!edit.id"
                            rows="6"
                            placeholder="-----BEGIN CERTIFICATE-----"
                            class="rounded-md border border-input bg-transparent px-3 py-2 font-mono text-xs"
                        />
                        <Input
                            v-else
                            id="token"
                            v-model="f.token"
                            type="password"
                            :required="!edit.id"
                            minlength="32"
                            autocomplete="off"
                        />
                        <InputError :message="f.errors.token" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="kapasitas_pod">{{
                            f.jenis === "directadmin"
                                ? "Kapasitas akun"
                                : f.jenis === "incus"
                                  ? "Kapasitas VPS"
                                  : "Kapasitas pod"
                        }}</Label>
                        <Input
                            id="kapasitas_pod"
                            v-model.number="f.kapasitas_pod"
                            type="number"
                            min="1"
                            required
                        />
                        <InputError :message="f.errors.kapasitas_pod" />
                    </div>
                    <div v-if="f.jenis === 'pod'" class="grid gap-2">
                        <Label for="ram_pod_mb">RAM untuk pod (MB)</Label>
                        <Input
                            id="ram_pod_mb"
                            :model-value="f.ram_pod_mb ?? undefined"
                            @update:model-value="(v) => (f.ram_pod_mb = v === '' || v == null ? null : Number(v))"
                            type="number"
                            min="256"
                            placeholder="Kosong = hanya batas jumlah pod"
                        />
                        <p class="text-xs text-muted-foreground">
                            Pod baru tidak dibuat di node ini bila total batas RAM pod melebihi angka ini.
                        </p>
                        <InputError :message="f.errors.ram_pod_mb" />
                    </div>
                    <div class="flex items-center gap-2 text-sm">
                        <input id="aktif" v-model="f.aktif" type="checkbox" />
                        <Label for="aktif">Aktif menerima layanan baru</Label>
                    </div>
                    <DialogFooter>
                        <Button type="submit" :disabled="f.processing"
                            >Simpan</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
