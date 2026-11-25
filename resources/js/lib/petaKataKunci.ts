// Peta kata kunci (Admin › SEO › Keyword Mapping): tipe data + warna status (palet landing: volt/brand).
export type Simpul = {
    id: string;
    jenis: 'akar' | 'kata' | 'halaman';
    kata: string;
    sumber?: 'riset' | 'gsc' | 'keduanya';
    status?: 'target' | 'turunan' | 'cadangan' | 'gsc';
    url?: string | null;
    volume?: number | null;
    kd?: number | null;
    niat?: string | null;
    catatan?: string | null;
    klik?: number;
    tayangan?: number;
    posisi?: number | null;
    induk?: string[];
};
export type Tautan = {
    dari: string;
    ke: string;
    jenis: 'cabang' | 'halaman' | 'akar';
};

// warna titik status (legenda & tabel), selaras palet landing: volt = target, navy = turunan
export const WARNA: Record<string, string> = {
    akar: '#62b324',
    target: '#62b324',
    turunan: '#165a82',
    cadangan: '#94a3b8',
    gsc: '#d4922a',
    halaman: '#0b2a3f',
};
export const NAMA_STATUS: Record<string, string> = {
    target: 'Target',
    turunan: 'Turunan',
    cadangan: 'Cadangan',
    gsc: 'Dari Google (GSC)',
    halaman: 'Halaman',
};
