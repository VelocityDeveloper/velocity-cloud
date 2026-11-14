export const rupiah = (n: number | null): string =>
    n === null ? 'Hubungi kami' : 'Rp' + n.toLocaleString('id-ID');

export const tanggal = (iso: string | null): string =>
    iso
        ? new Date(
              iso + (iso.length === 10 ? 'T00:00:00' : ''),
          ).toLocaleDateString('id-ID', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : '-';
