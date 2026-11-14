// Sumber seksi/*.html dan tema/parts/*.html (markup blok divalidasi editor WP). Bangun ulang: node bangun.cjs /tmp/out lalu salin + cek-blok.
// Membangun contoh seksi landing page (markup blok valid via serialize resmi) untuk panduan desain Chat AI.
// Pakai: node bangun.cjs <folder-keluaran>
const path = require('path');
const fs = require('fs');
const akar = '/var/lib/velocity/tools/blockval/node_modules/';
const m = (n) => require(path.join(akar, n));
console.error = console.warn = console.info = () => {};
m('global-jsdom')();
window.matchMedia = window.matchMedia || (() => ({ matches: false, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {} }));
global.requestAnimationFrame = global.requestAnimationFrame || ((cb) => setTimeout(cb, 0));
const { createBlock: b, serialize } = m('@wordpress/blocks');
m('@wordpress/block-library').registerCoreBlocks();

const S = (pt, pb, extra = {}) => ({ spacing: { padding: { top: `var:preset|spacing|${pt}`, bottom: `var:preset|spacing|${pb}`, left: 'var:preset|spacing|50', right: 'var:preset|spacing|50' }, margin: { top: '0', bottom: '0' }, ...extra } });
const seksi = (anchor, bg, isi, pad = ['70', '70'], text) => b('core/group', { tagName: 'section', anchor, align: 'full', backgroundColor: bg, ...(text ? { textColor: text } : {}), style: S(pad[0], pad[1]), layout: { type: 'constrained' } }, isi);
const lebar = (isi, extra = {}) => b('core/group', { align: 'wide', layout: { type: 'default' }, ...extra }, isi);
// Rata teks: block-library 11 menyimpannya di style.typography.textAlign, WP 6.9 di pod masih atribut lama.
// Ditulis ke style dulu, lalu keGayaWp69() memindahkannya ke textAlign (heading) / align (paragraph).
const rata = ({ textAlign, align, style = {}, ...x }) => (textAlign || align ? { ...x, style: { ...style, typography: { ...(style.typography || {}), textAlign: textAlign || align } } } : { ...x, style });
const h = (level, t, extra = {}) => b('core/heading', { level, content: t, ...rata(extra) });
const p = (t, extra = {}) => b('core/paragraph', { content: t, ...rata(extra) });
const kecil = (t, warna = 'accent-1', align) => p(t, { textColor: warna, fontSize: 'small', ...(align ? { align } : {}), style: { typography: { textTransform: 'uppercase', letterSpacing: '0.12em', fontWeight: '700' } } });
const keGayaWp69 = (html) => html.replace(/<!-- wp:(heading|paragraph) (\{.*?\}) -->/g, (asli, nama, json) => {
  const a = JSON.parse(json);
  const t = a.style?.typography?.textAlign;
  if (!t) return asli;
  delete a.style.typography.textAlign;
  if (!Object.keys(a.style.typography).length) delete a.style.typography;
  if (!Object.keys(a.style).length) delete a.style;
  return `<!-- wp:${nama} ${JSON.stringify({ [nama === 'heading' ? 'textAlign' : 'align']: t, ...a })} -->`;
});
const judulSeksi = (eyebrow, judul, intro) => b('core/group', { layout: { type: 'constrained', contentSize: '680px' }, style: { spacing: { margin: { bottom: 'var:preset|spacing|60' }, blockGap: 'var:preset|spacing|30' } } }, [
  kecil(eyebrow, 'accent-1', 'center'), h(2, judul, { textAlign: 'center', fontSize: 'xx-large' }), p(intro, { align: 'center', textColor: 'accent-4' }),
]);
const tombol = (t, url, gaya) => b('core/button', { text: t, url, ...(gaya === 'outline' ? { className: 'is-style-outline' } : {}), ...(gaya === 'terang' ? { backgroundColor: 'base', textColor: 'accent-1' } : {}) });
const kartu = (isi) => b('core/group', { backgroundColor: 'base', style: { border: { radius: '16px', width: '1px', color: 'var:preset|color|accent-6' }, spacing: { padding: { top: '0', bottom: 'var:preset|spacing|50', left: '0', right: '0' }, blockGap: 'var:preset|spacing|30' } }, layout: { type: 'default' }, className: 'kartu' }, isi);
const dalam = (isi) => b('core/group', { style: { spacing: { padding: { left: 'var:preset|spacing|50', right: 'var:preset|spacing|50' }, blockGap: 'var:preset|spacing|20' } }, layout: { type: 'default' } }, isi);
const kolom = (anak, extra = {}) => b('core/columns', { align: 'wide', style: { spacing: { blockGap: { top: 'var:preset|spacing|50', left: 'var:preset|spacing|50' } } }, ...extra }, anak.map((a) => b('core/column', {}, Array.isArray(a) ? a : [a])));

const S1 = [
  b('core/cover', { url: '{FOTO_HERO}', alt: '{ALT_HERO}', dimRatio: 60, overlayColor: 'contrast', minHeight: 620, minHeightUnit: 'px', align: 'full', contentPosition: 'center center', style: { spacing: { padding: { top: 'var:preset|spacing|80', bottom: 'var:preset|spacing|80', left: 'var:preset|spacing|50', right: 'var:preset|spacing|50' }, margin: { top: '0', bottom: '0' } } }, layout: { type: 'constrained', contentSize: '760px' } }, [
    kecil('{LABEL KECIL, mis. Langsung dari petani}', 'accent-3', 'center'),
    h(1, '{Judul utama yang menjual, 5–9 kata}', { textAlign: 'center', textColor: 'base', fontSize: 'xx-large', style: { typography: { lineHeight: '1.1' } } }),
    p('{Satu-dua kalimat manfaat utama untuk calon pembeli}', { align: 'center', textColor: 'base', fontSize: 'large' }),
    b('core/buttons', { layout: { type: 'flex', justifyContent: 'center' }, style: { spacing: { margin: { top: 'var:preset|spacing|40' } } } }, [
      tombol('Pesan via WhatsApp', 'https://wa.me/{NOMOR_WA}?text={PESAN_URLENCODE}'),
      b('core/button', { text: 'Lihat Produk', url: '#produk', className: 'is-style-outline', textColor: 'base' }),
    ]),
  ]),
];

const angka = (n, l) => b('core/group', { layout: { type: 'default' }, style: { spacing: { blockGap: '0' } } }, [h(3, n, { textAlign: 'center', textColor: 'accent-1', fontSize: 'xx-large' }), p(l, { align: 'center', textColor: 'accent-4', fontSize: 'small' })]);
const S2 = [seksi('keunggulan-angka', 'accent-5', [kolom([angka('{100%}', '{Bahan alami}'), angka('{500+}', '{Pelanggan}'), angka('{3}', '{Lini produk}'), angka('{24 jam}', '{Respons admin}')], { isStackedOnMobile: false, className: 'angka' })], ['50', '50'])];

const produk = (n) => kartu([
  b('core/image', { url: `{FOTO_PRODUK_${n}}`, alt: `{ALT_PRODUK_${n}}`, aspectRatio: '4/3', scale: 'cover', sizeSlug: 'large', style: { border: { radius: { topLeft: '16px', topRight: '16px', bottomLeft: '0px', bottomRight: '0px' } } } }),
  dalam([
    h(3, `{Nama produk ${n}}`, { fontSize: 'large' }),
    p(`{Deskripsi singkat manfaat dan spesifikasi produk ${n}}`, { textColor: 'accent-4' }),
    p(`<strong>{Rp 25.000 / kg}</strong>`, { textColor: 'accent-1', fontSize: 'large' }),
    b('core/buttons', {}, [tombol('Pesan', 'https://wa.me/{NOMOR_WA}?text={PESAN_PRODUK_URLENCODE}')]),
  ]),
]);
const S3 = [seksi('produk', 'base', [judulSeksi('{Produk kami}', '{Judul seksi produk}', '{Kalimat pengantar produk}'), kolom([produk(1), produk(2), produk(3)])])];

const fitur = (n) => b('core/group', { layout: { type: 'default' }, style: { spacing: { blockGap: 'var:preset|spacing|20' } } }, [h(3, `{Keunggulan ${n}}`, { fontSize: 'medium' }), p(`{Penjelasan keunggulan ${n}, 1–2 kalimat}`, { textColor: 'accent-4' })]);
const S4 = [seksi('keunggulan', 'accent-2', [kolom([
  b('core/image', { url: '{FOTO_KEUNGGULAN}', alt: '{ALT_KEUNGGULAN}', aspectRatio: '4/5', scale: 'cover', sizeSlug: 'large', style: { border: { radius: '16px' } } }),
  [kecil('{Kenapa memilih kami}'), h(2, '{Judul alasan memilih}', { fontSize: 'xx-large' }), p('{Kalimat pengantar}', { textColor: 'accent-4' }),
    kolom([fitur(1), fitur(2)], { style: { spacing: { blockGap: { top: 'var:preset|spacing|40', left: 'var:preset|spacing|40' }, margin: { top: 'var:preset|spacing|50' } } } }),
    kolom([fitur(3), fitur(4)], { style: { spacing: { blockGap: { top: 'var:preset|spacing|40', left: 'var:preset|spacing|40' } } } })],
], { verticalAlignment: 'center' })])];

const langkah = (n) => b('core/group', { layout: { type: 'default' }, style: { spacing: { blockGap: 'var:preset|spacing|20' } } }, [p(`<strong>0${n}</strong>`, { textColor: 'accent-3', fontSize: 'x-large' }), h(3, `{Langkah ${n}}`, { fontSize: 'medium' }), p(`{Penjelasan langkah ${n}}`, { textColor: 'accent-4' })]);
const S5 = [seksi('cara-pesan', 'base', [judulSeksi('{Cara pesan}', '{Judul alur pemesanan}', '{Pengantar singkat}'), kolom([langkah(1), langkah(2), langkah(3)])])];

const testi = (n) => kartu([dalam([
  p(`“{Kutipan testimoni ${n} — tulis jelas sebagai contoh bila belum ada data asli}”`, { style: { spacing: { padding: { top: 'var:preset|spacing|50' } } } }),
  p(`<strong>{Nama ${n}}</strong><br>{Kota / jenis usaha}`, { fontSize: 'small', textColor: 'accent-4' }),
])]);
const S6 = [seksi('testimoni', 'accent-5', [judulSeksi('{Testimoni}', '{Kata pelanggan}', '{Pengantar singkat}'), kolom([testi(1), testi(2), testi(3)])])];

const tanya = (n) => b('core/details', { summary: `{Pertanyaan ${n}?}`, style: { border: { bottom: { color: 'var:preset|color|accent-6', width: '1px' } }, spacing: { padding: { top: 'var:preset|spacing|30', bottom: 'var:preset|spacing|30' } } } }, [p(`{Jawaban ${n}}`, { textColor: 'accent-4' })]);
const S7 = [seksi('faq', 'base', [judulSeksi('{FAQ}', '{Pertanyaan yang sering diajukan}', '{Pengantar singkat}'), b('core/group', { layout: { type: 'constrained', contentSize: '760px' } }, [tanya(1), tanya(2), tanya(3), tanya(4)])])];

const S8 = [seksi('kontak', 'accent-1', [
  h(2, '{Ajakan bertindak, mis. Siap pesan hari ini?}', { textAlign: 'center', textColor: 'base', fontSize: 'xx-large' }),
  p('{Kalimat pendek + jam layanan}', { align: 'center', textColor: 'base' }),
  b('core/buttons', { layout: { type: 'flex', justifyContent: 'center' } }, [tombol('Chat WhatsApp', 'https://wa.me/{NOMOR_WA}?text={PESAN_URLENCODE}', 'terang'), b('core/button', { text: '{Email}', url: 'mailto:{EMAIL}', className: 'is-style-outline', textColor: 'base' })]),
], ['70', '70'], 'base')];

const out = process.argv[2];
fs.mkdirSync(out, { recursive: true });
const tulis = (n, bl) => fs.writeFileSync(path.join(out, n), keGayaWp69(serialize(bl)) + '\n');
tulis('1-hero.html', S1); tulis('2-angka.html', S2); tulis('3-produk.html', S3); tulis('4-keunggulan.html', S4);
tulis('5-cara-pesan.html', S5); tulis('6-testimoni.html', S6); tulis('7-faq.html', S7); tulis('8-kontak.html', S8);

// header & footer (template part)
const header = [b('core/group', { align: 'full', backgroundColor: 'base', className: 'kepala', style: { spacing: { padding: { top: 'var:preset|spacing|30', bottom: 'var:preset|spacing|30', left: 'var:preset|spacing|50', right: 'var:preset|spacing|50' } }, border: { bottom: { color: 'var:preset|color|accent-6', width: '1px' } } }, layout: { type: 'constrained' } }, [
  b('core/group', { align: 'wide', layout: { type: 'flex', justifyContent: 'space-between', flexWrap: 'nowrap' } }, [
    b('core/site-title', { level: 0, style: { typography: { fontWeight: '800', fontSize: '1.4rem' }, elements: { link: { color: { text: 'var:preset|color|accent-1' } } } } }),
    b('core/group', { layout: { type: 'flex', flexWrap: 'nowrap' }, style: { spacing: { blockGap: 'var:preset|spacing|40' } } }, [
      b('core/navigation', { overlayMenu: 'mobile', layout: { type: 'flex', justifyContent: 'right' }, style: { typography: { fontWeight: '600' } } }, [
        b('core/navigation-link', { label: 'Produk', url: '#produk', kind: 'custom' }),
        b('core/navigation-link', { label: 'Keunggulan', url: '#keunggulan', kind: 'custom' }),
        b('core/navigation-link', { label: 'Cara Pesan', url: '#cara-pesan', kind: 'custom' }),
        b('core/navigation-link', { label: 'FAQ', url: '#faq', kind: 'custom' }),
      ]),
      b('core/buttons', { className: 'tombol-kepala' }, [b('core/button', { text: 'Pesan', url: 'https://wa.me/{NOMOR_WA}', fontSize: 'small' })]),
    ]),
  ]),
])];
const kolomFooter = (judul, isi) => [h(3, judul, { fontSize: 'medium', textColor: 'base' }), ...isi];
const footer = [b('core/group', { align: 'full', backgroundColor: 'contrast', textColor: 'accent-6', style: { spacing: { padding: { top: 'var:preset|spacing|70', bottom: 'var:preset|spacing|50', left: 'var:preset|spacing|50', right: 'var:preset|spacing|50' }, margin: { top: '0' } }, elements: { link: { color: { text: 'var:preset|color|base' } } } }, layout: { type: 'constrained' } }, [
  kolom([
    kolomFooter('{Nama usaha}', [p('{Deskripsi singkat usaha, 1–2 kalimat}', { fontSize: 'small' })]),
    kolomFooter('Kontak', [p('WhatsApp: <a href="https://wa.me/{NOMOR_WA}">{08xx}</a><br>Email: <a href="mailto:{EMAIL}">{EMAIL}</a><br>{Alamat / kota}', { fontSize: 'small' })]),
    kolomFooter('Ikuti kami', [b('core/social-links', { iconColor: 'base', iconColorValue: '#ffffff', className: 'is-style-logos-only' }, [
      b('core/social-link', { service: 'instagram', url: 'https://www.instagram.com/' }), b('core/social-link', { service: 'facebook', url: 'https://www.facebook.com/' }), b('core/social-link', { service: 'tiktok', url: 'https://www.tiktok.com/' })])]),
  ]),
  b('core/separator', { align: 'wide', backgroundColor: 'accent-4', className: 'is-style-wide', style: { spacing: { margin: { top: 'var:preset|spacing|60', bottom: 'var:preset|spacing|40' } } } }),
  p('© {TAHUN} {Nama usaha}. Semua hak dilindungi.', { align: 'center', fontSize: 'small' }),
])];
tulis('header.html', header);
tulis('footer.html', footer);
