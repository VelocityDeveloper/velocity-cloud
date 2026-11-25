<?php

namespace App\Services\Chat;

use App\Actions\Order\PesanDomain;
use App\Enums\ServiceStatus;
use App\Http\Presenters\Tampil;
use App\Jobs\PodAction;
use App\Models\Pengaturan;
use App\Models\Service;
use App\Services\Crm\DomainCrm;
use App\Models\User;
use App\Services\Provisioning\Incus;
use App\Services\Provisioning\NodeAgent;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Alat (function calling) Chat AI untuk mengelola layanan milik pelanggan.
 * Model hanya mengusulkan; job JalankanChat menjalankan atas nama pemilik percakapan,
 * jadi kepemilikan & status selalu dicek di sini. Alat di KONFIRMASI baru dijalankan setelah pelanggan klik "Jalankan".
 */
class AlatLayanan
{
    public const KONFIRMASI = ['aksi_layanan', 'wp', 'perintah_vps', 'tulis_berkas', 'pesan_domain', 'ubah_nameserver'];

    /** Seksi contoh landing page (resources/ai/desain-wp/seksi/<n>-<nama>.html, markup blok tervalidasi). */
    public const SEKSI = ['hero', 'angka', 'produk', 'keunggulan', 'cara-pesan', 'testimoni', 'faq', 'kontak'];

    private const BATAS_HASIL = 8000; // karakter hasil yang dikirim balik ke model (hemat token)

    public static function definisi(): array
    {
        $id = ['type' => 'integer', 'description' => 'ID layanan dari daftar_layanan'];
        $f = fn (string $nama, string $desk, array $prop = [], array $wajib = []) => ['type' => 'function', 'function' => [
            'name' => $nama, 'description' => $desk,
            'parameters' => ['type' => 'object', 'properties' => (object) $prop, 'required' => $wajib],
        ]];

        return [
            $f('daftar_layanan', 'Daftar semua layanan milik pelanggan (Cloud Server/VPS, Managed Server, App 1-Klik seperti WordPress/n8n, Token AI) beserta id, status, URL, dan alat yang bisa dipakai.'),
            $f('status_layanan', 'Status menyala/mati dan pemakaian CPU/RAM layanan App atau VPS.', ['id' => $id], ['id']),
            $f('log_layanan', 'Log terakhir container App 1-Klik (untuk mencari penyebab error).', ['id' => $id, 'baris' => ['type' => 'integer', 'description' => 'Jumlah baris, 10-500, bawaan 100']], ['id']),
            $f('aksi_layanan', 'Nyalakan, matikan, atau mulai ulang App/VPS. Pelanggan harus menyetujui dulu.', ['id' => $id, 'aksi' => ['type' => 'string', 'enum' => NodeAgent::AKSI]], ['id', 'aksi']),
            $f('wp', 'Jalankan WP-CLI di App WordPress pelanggan, mis. ["plugin","list"], ["plugin","install","contact-form-7","--activate"], ["user","update","1","--user_pass=..."], ["core","update"]. Tanpa kata "wp" di depan. Pelanggan harus menyetujui dulu.',
                ['id' => $id, 'args' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Argumen WP-CLI']], ['id', 'args']),
            $f('panduan_desain_wp', 'WAJIB dipanggil sebelum membuat/mendesain ulang halaman atau tampilan WordPress: alur child theme FSE, aturan markup blok, palet, dan berkas tema contoh.'),
            $f('contoh_seksi_wp', 'Markup blok contoh satu seksi landing page (placeholder {…} diganti isi pelanggan).', ['seksi' => ['type' => 'string', 'enum' => self::SEKSI]], ['seksi']),
            $f('cari_foto', 'Cari foto bebas pakai (Pexels) untuk halaman WordPress; hasil: url_besar, url_sedang, alt, fotografer.',
                ['kata' => ['type' => 'string', 'description' => 'Kata kunci bahasa Inggris, spesifik'], 'jumlah' => ['type' => 'integer', 'description' => '1-8, bawaan 4']], ['kata']),
            $f('tulis_berkas', 'Tulis berkas di wp-content App WordPress: tema (themes/<slug>/...), isi halaman (uploads/velocity-ai/<nama>.html). Berkas .html dicek validitas bloknya. Pelanggan harus menyetujui dulu.',
                ['id' => $id, 'path' => ['type' => 'string', 'description' => 'Relatif ke wp-content, mis. themes/velocitycoco/theme.json'], 'isi' => ['type' => 'string'], 'tambah' => ['type' => 'boolean', 'description' => 'true = sambung ke akhir berkas']], ['id', 'path', 'isi']),
            $f('cek_domain', 'Cek ketersediaan dan harga domain (daftar baru & transfer, total per periode tahun) sebelum dipesan.',
                ['domain' => ['type' => 'string', 'description' => 'Nama domain lengkap, mis. tokoanda.com']], ['domain']),
            $f('pesan_domain', 'Buat pesanan domain + tagihan (pelanggan membayar di halaman tagihan; setelah lunas domain didaftarkan otomatis). Kontak pemilik domain memakai data pesanan domain sebelumnya. Pelanggan harus menyetujui dulu.',
                ['domain' => ['type' => 'string'], 'jenis' => ['type' => 'string', 'enum' => ['Register', 'Transfer']], 'periode' => ['type' => 'integer', 'description' => 'Tahun, 1-10, bawaan 1'],
                    'epp' => ['type' => 'string', 'description' => 'Kode EPP, wajib untuk Transfer']], ['domain', 'jenis']),
            $f('info_domain', 'Status, masa aktif, jatuh tempo perpanjangan, dan nameserver domain milik pelanggan.', ['id' => $id], ['id']),
            $f('ubah_nameserver', 'Ganti nameserver domain milik pelanggan (2-4 nameserver). Pelanggan harus menyetujui dulu.',
                ['id' => $id, 'nameserver' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'mis. ["ns1.contoh.com","ns2.contoh.com"]']], ['id', 'nameserver']),
            $f('perintah_vps', 'Jalankan perintah shell sebagai root di Cloud Server (VPS) pelanggan, batas 60 detik, tanpa input interaktif (pakai -y). Pelanggan harus menyetujui dulu.',
                ['id' => $id, 'perintah' => ['type' => 'string', 'description' => 'Perintah sh, mis. "df -h && free -m"']], ['id', 'perintah']),
        ];
    }

    /** Teks satu baris untuk daftar alat di chat & kartu persetujuan. */
    public static function ringkas(string $nama, array $a): string
    {
        $id = isset($a['id']) && is_scalar($a['id']) ? ' #'.$a['id'] : '';
        $teks = fn ($k) => is_scalar($a[$k] ?? null) ? (string) $a[$k] : '';

        return mb_substr(match ($nama) {
            'daftar_layanan' => 'Lihat daftar layanan',
            'status_layanan' => "Cek status layanan$id",
            'log_layanan' => "Baca log layanan$id",
            'aksi_layanan' => $teks('aksi')." layanan$id",
            'wp' => 'wp '.implode(' ', array_filter((array) ($a['args'] ?? []), 'is_scalar'))."  (WordPress$id)",
            'perintah_vps' => $teks('perintah')."  (VPS$id)",
            'tulis_berkas' => (empty($a['tambah']) ? 'Tulis' : 'Tambah ke').' wp-content/'.$teks('path').' ('.(int) ceil(strlen($teks('isi')) / 1024)." KB, WordPress$id)",
            'panduan_desain_wp' => 'Baca panduan desain WordPress',
            'contoh_seksi_wp' => 'Ambil contoh seksi '.$teks('seksi'),
            'cari_foto' => 'Cari foto "'.$teks('kata').'"',
            'cek_domain' => 'Cek domain '.$teks('domain'),
            'pesan_domain' => ($teks('jenis') === 'Transfer' ? 'Pesan transfer domain ' : 'Pesan domain ').$teks('domain').' '.max(1, (int) ($a['periode'] ?? 1)).' tahun',
            'info_domain' => "Lihat info domain$id",
            'ubah_nameserver' => 'Ganti nameserver ke '.implode(', ', array_filter((array) ($a['nameserver'] ?? []), 'is_scalar'))." (domain$id)",
            default => $nama,
        }, 0, 300);
    }

    /** Dipanggil job chat (dan POST chat/alat); galat apa pun dikembalikan sebagai teks agar model bisa menjelaskannya ke pelanggan. */
    public static function jalankan(User $user, string $nama, array $arg): string
    {
        try {
            $hasil = match ($nama) {
                'daftar_layanan' => self::daftar($user),
                'status_layanan' => self::status(self::layanan($user, $arg)),
                'log_layanan' => self::log(self::layanan($user, $arg), $arg),
                'aksi_layanan' => self::aksi(self::layanan($user, $arg), $arg),
                'wp' => self::wp(self::layanan($user, $arg), $arg),
                'perintah_vps' => self::vps(self::layanan($user, $arg), $arg),
                'tulis_berkas' => self::tulisBerkas(self::layanan($user, $arg), $arg),
                'panduan_desain_wp' => self::panduan(),
                'contoh_seksi_wp' => self::seksi($arg),
                'cari_foto' => self::cariFoto($arg),
                'cek_domain' => self::cekDomain($arg),
                'pesan_domain' => self::pesanDomain($user, $arg),
                'info_domain' => self::infoDomain(self::domainMilik($user, $arg)),
                'ubah_nameserver' => self::ubahNameserver(self::domainMilik($user, $arg), $arg),
                default => throw new \InvalidArgumentException("Alat $nama tidak dikenal."),
            };
            if (in_array($nama, self::KONFIRMASI, true)) {
                Log::info('chat-alat', ['user' => $user->id, 'alat' => $nama, 'arg' => $arg]); // jejak audit aksi yang mengubah
            }
        } catch (ValidationException $e) {
            $hasil = ['galat' => $e->validator->errors()->first()];
        } catch (\InvalidArgumentException|\DomainException $e) {
            $hasil = ['galat' => $e->getMessage()];
        } catch (Throwable $e) {
            report($e);
            $hasil = ['galat' => 'Server layanan tidak merespons. Coba lagi sebentar.'];
        }
        $teks = is_string($hasil) ? $hasil : json_encode($hasil, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        // panduan & contoh seksi harus utuh (markup terpotong = blok rusak)
        return mb_strlen($teks) > self::BATAS_HASIL && ! in_array($nama, ['panduan_desain_wp', 'contoh_seksi_wp'], true)
            ? '…'.mb_substr($teks, -self::BATAS_HASIL) : $teks;
    }

    private static function daftar(User $user): array
    {
        return $user->services()->with('plan', 'node')->latest()->get()->map(fn (Service $s) => [
            ...collect(Tampil::layanan($s))->except('harga')->all(),
            'alat' => array_keys(array_filter([
                'status_layanan' => self::kontrol($s),
                'log_layanan' => self::kontrol($s) && $s->isApp(),
                'aksi_layanan' => self::kontrol($s),
                'wp' => self::kontrol($s) && $s->plan->appSlug() === 'wordpress',
                'tulis_berkas' => self::kontrol($s) && $s->plan->appSlug() === 'wordpress',
                'perintah_vps' => self::kontrol($s) && $s->node->jenis === 'incus',
                'info_domain' => self::domainBisaDikelola($s),
                'ubah_nameserver' => self::domainBisaDikelola($s),
            ])),
        ])->all();
    }

    private static function domainBisaDikelola(Service $s): bool
    {
        return $s->isDomain() && $s->status === ServiceStatus::Aktif && ! empty($s->detail['crm']['customer_id']);
    }

    private static function domainMilik(User $user, array $arg): Service
    {
        $s = $user->services()->with('plan')->find($arg['id'] ?? 0);
        if (! $s || ! $s->isDomain()) {
            throw new \DomainException('Domain tidak ditemukan di akun ini. Panggil daftar_layanan untuk melihat ID domain.');
        }
        if (! self::domainBisaDikelola($s)) {
            throw new \DomainException("Domain \"{$s->label}\" belum bisa dikelola dari chat (status: {$s->status->label()}). Arahkan ke menu Tiket.");
        }

        return $s;
    }

    /** [customer_id CRM, id domain CRM] */
    private static function domainCrm(Service $s): array
    {
        $cid = (int) $s->detail['crm']['customer_id'];
        $d = app(DomainCrm::class)->domainCustomer($cid, $s->detail['domain']);
        if (! $d) {
            throw new \DomainException("Domain {$s->detail['domain']} tidak ditemukan di sistem domain kami. Arahkan ke menu Tiket.");
        }

        return [$cid, (int) $d['id'], $d];
    }

    private static function cekDomain(array $arg): array
    {
        $domain = PesanDomain::normalisasi((string) ($arg['domain'] ?? ''));
        if (! preg_match(PesanDomain::POLA, $domain)) {
            throw new \InvalidArgumentException('Format domain tidak valid, contoh: tokoanda.com.');
        }
        $info = app(PesanDomain::class)->info($domain);
        if (! $info) {
            return ['domain' => $domain, 'dijual' => false, 'pesan' => 'Ekstensi ini belum kami jual. Yang dijual: '.implode(', ', Pengaturan::tldDomain())];
        }

        return [
            'domain' => $domain,
            'status' => $info['tercatat'] ? 'sudah tercatat sebagai layanan Velocity' : match ($info['tersedia']) {
                true => 'tersedia, bisa didaftarkan (Register)',
                false => 'sudah terdaftar, hanya bisa Transfer bila milik pelanggan',
                default => 'ketersediaan tidak bisa dicek otomatis',
            },
            'harga_rupiah' => $info['harga'],
        ];
    }

    private static function pesanDomain(User $user, array $arg): array
    {
        $kontak = PesanDomain::kontakTerakhir($user);
        $kurang = array_filter(PesanDomain::KONTAK_WAJIB, fn ($k) => blank($kontak[$k] ?? null));
        if ($kurang) {
            throw new \DomainException('Data pemilik domain (alamat lengkap untuk WHOIS) belum ada. Minta pelanggan memesan lewat '.route('domain.index').' sekali; pesanan berikutnya bisa lewat chat.');
        }
        $domain = PesanDomain::normalisasi((string) ($arg['domain'] ?? ''));
        if (! preg_match(PesanDomain::POLA, $domain)) {
            throw new \InvalidArgumentException('Format domain tidak valid, contoh: tokoanda.com.');
        }
        $jenis = ($arg['jenis'] ?? '') === 'Transfer' ? 'Transfer' : 'Register';
        $tagihan = app(PesanDomain::class)->handle($user, $domain, $jenis, min(max((int) ($arg['periode'] ?? 1), 1), 10), $arg['epp'] ?? null, $kontak);

        return [
            'tagihan' => $tagihan->nomor,
            'jumlah_rupiah' => $tagihan->total(), // termasuk kode unik
            'bayar_di' => route('tagihan.show', $tagihan),
            'pemilik_domain' => $kontak['nama'].' <'.$kontak['email'].'>',
            'catatan' => 'Domain didaftarkan otomatis setelah tagihan lunas.',
        ];
    }

    private static function infoDomain(Service $s): array
    {
        [$cid, $id, $d] = self::domainCrm($s);

        return [
            'domain' => $s->detail['domain'],
            'status' => $d['status'],
            'aktif_sampai' => $d['expiry_date'] ?? null,
            'jatuh_tempo_perpanjangan' => $d['next_due_date'] ?? null,
            'nameserver' => app(DomainCrm::class)->nameserver($cid, $id),
        ];
    }

    private static function ubahNameserver(Service $s, array $arg): string
    {
        $ns = array_values(array_unique(array_map(fn ($n) => strtolower(rtrim(trim((string) $n), '.')), array_filter((array) ($arg['nameserver'] ?? []), 'is_scalar'))));
        if (count($ns) < 2 || count($ns) > 4) {
            throw new \InvalidArgumentException('Isi 2 sampai 4 nameserver.');
        }
        [$cid, $id] = self::domainCrm($s);
        $hasil = app(DomainCrm::class)->ubahNameserver($cid, $id, $ns);

        return ($hasil['message'] ?? 'Nameserver diubah').'. Perubahan menyebar 1-24 jam.';
    }

    private static function kontrol(Service $s): bool
    {
        return $s->status === ServiceStatus::Aktif && $s->bisaKontrol();
    }

    private static function layanan(User $user, array $arg): Service
    {
        $s = $user->services()->with('plan', 'node')->find($arg['id'] ?? 0);
        if (! $s) {
            throw new \DomainException('Layanan tidak ditemukan di akun ini. Panggil daftar_layanan untuk melihat ID yang benar.');
        }
        if (! self::kontrol($s)) {
            throw new \DomainException("Layanan \"$s->label\" tidak bisa dikelola dari chat (status: {$s->status->label()}). Hanya App 1-Klik dan Cloud Server otomatis yang aktif.");
        }

        return $s;
    }

    private static function status(Service $s): array
    {
        return $s->node->jenis === 'incus' ? (new Incus($s->node))->ringkas($s->podName()) : (new NodeAgent($s->node))->status($s->podName());
    }

    private static function log(Service $s, array $arg): array
    {
        if (! $s->isApp()) {
            throw new \DomainException('Log hanya tersedia untuk App 1-Klik. Untuk VPS pakai perintah_vps, mis. "journalctl -n 100".');
        }
        $baris = min(max((int) ($arg['baris'] ?? 100), 10), 500);

        return (new NodeAgent($s->node))->log($s->podName(), $baris)['log'] ?? [];
    }

    private static function aksi(Service $s, array $arg): string
    {
        $aksi = Validator::validate($arg, ['aksi' => ['required', 'in:'.implode(',', NodeAgent::AKSI)]])['aksi'];
        PodAction::dispatch($s, $aksi);

        return "Perintah $aksi untuk \"$s->label\" dikirim. Status berubah dalam beberapa detik; cek dengan status_layanan.";
    }

    private static function wp(Service $s, array $arg): array
    {
        if ($s->plan->appSlug() !== 'wordpress') {
            throw new \DomainException('WP-CLI hanya untuk App WordPress.');
        }
        $args = Validator::validate($arg, ['args' => ['required', 'array', 'min:1', 'max:30'], 'args.*' => ['string', 'max:2000']])['args'];
        if (strtolower($args[0]) === 'wp') {
            array_shift($args); // model kadang tetap menulis "wp" di depan
        }

        return (new NodeAgent($s->node))->wp($s->podName(), $args);
    }

    private const DIR_DESAIN = 'ai/desain-wp';

    private static function panduan(): string
    {
        $d = resource_path(self::DIR_DESAIN.'/tema');
        $teks = File::get(resource_path(self::DIR_DESAIN.'/panduan.md'));
        foreach (['style.css', 'theme.json', 'templates/front-page.html', 'parts/header.html', 'parts/footer.html'] as $f) {
            $teks .= "\n### themes/<slug>/$f\n```\n".rtrim(File::get("$d/$f"))."\n```\n";
        }

        return $teks;
    }

    private static function seksi(array $arg): string
    {
        $seksi = Validator::validate($arg, ['seksi' => ['required', 'in:'.implode(',', self::SEKSI)]])['seksi'];

        return File::get(File::glob(resource_path(self::DIR_DESAIN."/seksi/*-$seksi.html"))[0]);
    }

    private static function cariFoto(array $arg): array
    {
        $v = Validator::validate($arg, ['kata' => ['required', 'string', 'max:100'], 'jumlah' => ['nullable', 'integer']]);
        $kunci = trim((string) @file_get_contents(config('cloud.chat_ai.pexels_kunci')));
        if ($kunci === '') {
            throw new \DomainException('Pencarian foto belum tersedia. Pakai foto dari Media Library pelanggan.');
        }
        $foto = Http::withHeaders(['Authorization' => $kunci])->timeout(20)->throw()
            ->get('https://api.pexels.com/v1/search', ['query' => $v['kata'], 'per_page' => min(max((int) ($v['jumlah'] ?? 4), 1), 8)])
            ->json('photos') ?? [];
        // shortcut: lebar dibatasi lewat parameter CDN Pexels (hero 1600, lainnya 900) agar media klien tetap ringan
        $ukuran = fn (array $f, int $w) => strtok($f['src']['original'], '?')."?auto=compress&cs=tinysrgb&w=$w";

        return array_map(fn ($f) => [
            'url_besar' => $ukuran($f, 1600), 'url_sedang' => $ukuran($f, 900),
            'alt' => $f['alt'] ?? '', 'fotografer' => $f['photographer'] ?? '', 'orientasi' => ($f['width'] ?? 0) >= ($f['height'] ?? 0) ? 'lanskap' : 'potret',
        ], $foto) ?: ['galat' => 'Foto tidak ditemukan. Coba kata kunci bahasa Inggris lain.'];
    }

    private static function tulisBerkas(Service $s, array $arg): array
    {
        if ($s->plan->appSlug() !== 'wordpress') {
            throw new \DomainException('tulis_berkas hanya untuk App WordPress.');
        }
        $v = Validator::validate($arg, [
            'path' => ['required', 'string', 'max:200', 'regex:#^(themes|plugins|mu-plugins|uploads)/[A-Za-z0-9._/-]+$#', 'not_regex:#(^|/)\.{1,2}(/|$)|//#'],
            'isi' => ['present', 'string', 'max:500000'],
            'tambah' => ['nullable', 'boolean'],
        ]);
        $hasil = (new NodeAgent($s->node))->berkas($s->podName(), $v['path'], $v['isi'], (bool) ($v['tambah'] ?? false));
        if (str_ends_with($v['path'], '.html') && trim($v['isi']) !== '') {
            $hasil['cek_blok'] = self::cekBlok($v['isi']);
        }

        return $hasil;
    }

    /** Validasi markup blok dengan parser editor WordPress (scripts/cek-blok installer); null = alat tidak tersedia. */
    private static function cekBlok(string $isi): ?string
    {
        $alat = config('cloud.chat_ai.cek_blok');
        if (! is_executable($alat)) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'blok').'.html';
        file_put_contents($tmp, $isi);
        try {
            $r = Process::timeout(60)->run([$alat, $tmp]);
        } finally {
            @unlink($tmp);
        }

        return trim(str_replace($tmp, 'berkas', $r->output())) ?: 'cek blok gagal dijalankan';
    }

    private static function vps(Service $s, array $arg): array
    {
        if ($s->node->jenis !== 'incus') {
            throw new \DomainException('Perintah shell hanya untuk Cloud Server (VPS).');
        }
        $perintah = Validator::validate($arg, ['perintah' => ['required', 'string', 'max:4000']])['perintah'];

        return (new Incus($s->node))->exec($s->podName(), $perintah);
    }
}
