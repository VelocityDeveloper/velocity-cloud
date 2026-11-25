export type Paket = {
    id: number;
    slug: string;
    kategori: 'vps' | 'managed' | 'app' | 'ai' | 'hosting' | 'domain';
    nama: string;
    harga: number | null;
    harga_promo: number | null; // token AI: harga beli saat promo berlaku
    promo_selesai: string | null; // "YYYY-MM-DDTHH:mm" WIB, null = tanpa batas
    spek: Record<string, string> | null;
    fitur: string[] | null;
    ringkas: string | null;
    bisa_dipesan: boolean;
    habis: boolean;
    vps_otomatis: boolean;
};

export type Layanan = {
    id: number;
    label: string;
    paket: string;
    kategori: Paket['kategori'];
    kategori_label: string;
    status: string;
    status_label: string;
    harga: number;
    jatuh_tempo: string | null;
    url: string | null;
};

export type LayananLengkap = Layanan & {
    subdomain: string | null;
    custom_domain: string | null;
    custom_domain_aktif: boolean;
    domain_didukung: boolean;
    domain_relay: boolean; // custom domain disambung otomatis lewat relay (app & VPS di belakang relay)
    relay_ip: string | null;
    spek: Record<string, string> | null;
    is_app: boolean;
    is_hosting: boolean;
    is_domain: boolean;
    otomatis: boolean;
    kontrol: boolean;
    pairing: boolean;
    host: string | null;
    host_port: number | null;
    akses: string | null;
    kredensial: Record<string, string> | null;
    pesan_gagal: string | null;
    info: [string, string, boolean][];
};

export type Tiket = {
    id: number;
    judul: string;
    jenis: 'billing' | 'support';
    jenis_label: string;
    status: 'terbuka' | 'dijawab' | 'ditutup';
    status_label: string;
    layanan: string | null;
    pelanggan: string | null;
    email: string | null;
    diperbarui: string;
};

export type Tagihan = {
    id: number;
    nomor: string;
    layanan: string;
    jumlah: number;
    kode_unik: number;
    total: number;
    status: 'belum_dibayar' | 'lunas' | 'dibatalkan';
    status_label: string;
    periode: string;
    jatuh_tempo: string;
    dibayar_pada: string | null;
    metode: string | null;
};
