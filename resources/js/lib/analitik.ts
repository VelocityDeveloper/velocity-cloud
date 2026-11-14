export const angka = (n: number): string => n.toLocaleString('id-ID');

export const persen = (n: number): string =>
    (n * 100).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + '%';

/** Detik → "1m 05d" / "42d". */
export const durasi = (detik: number): string => {
    const m = Math.floor(detik / 60);
    const d = detik % 60;

    return m ? `${m}m ${String(d).padStart(2, '0')}d` : `${d}d`;
};

/** Perubahan relatif terhadap periode lalu; null jika periode lalu nol. */
export const selisih = (kini: number, lalu: number): number | null =>
    lalu ? (kini - lalu) / lalu : null;
