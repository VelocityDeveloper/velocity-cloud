<?php

namespace App\Services\Analitik;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Locale;

/**
 * Laporan analitik landing ala Google Analytics dari tabel analitik_hits.
 * Definisi: pengguna = vid unik; sesi = sid unik; sesi engaged = >=10 dtk aktif, >=2 tampilan, atau ada event;
 * bounce rate = 1 - engaged/sesi; sumber sesi = sumber tampilan pertama sesi (awal_sesi).
 */
class Laporan
{
    public const PERIODE = ['hari_ini' => 'Hari ini', 'kemarin' => 'Kemarin', '7h' => '7 hari terakhir', '28h' => '28 hari terakhir', '90h' => '90 hari terakhir', '12b' => '12 bulan terakhir'];

    public function __construct(private bool $tanpaInternal = true) {}

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: bool} [dari, sampai (eksklusif), per jam?] */
    public static function rentang(string $periode, ?CarbonImmutable $kini = null): array
    {
        $hariIni = ($kini ?? CarbonImmutable::now())->startOfDay();

        return match ($periode) {
            'hari_ini' => [$hariIni, $hariIni->addDay(), true],
            'kemarin' => [$hariIni->subDay(), $hariIni, true],
            '28h' => [$hariIni->subDays(27), $hariIni->addDay(), false],
            '90h' => [$hariIni->subDays(89), $hariIni->addDay(), false],
            '12b' => [$hariIni->subYear()->addDay(), $hariIni->addDay(), false],
            default => [$hariIni->subDays(6), $hariIni->addDay(), false],
        };
    }

    /** Hit dalam rentang; $alias diisi bila query di-join agar kolom tidak ambigu. */
    private function q(CarbonImmutable $dari, CarbonImmutable $sampai, ?string $jenis = 'pv', ?string $alias = null): Builder
    {
        $k = $alias ? "$alias." : '';

        return DB::table($alias ? "analitik_hits as $alias" : 'analitik_hits')
            ->where($k.'waktu', '>=', $dari->toDateTimeString())->where($k.'waktu', '<', $sampai->toDateTimeString())
            ->when($jenis, fn ($q) => $q->where($k.'jenis', $jenis))
            ->when($this->tanpaInternal, fn ($q) => $q->where($k.'internal', false));
    }

    public function semua(string $periode): array
    {
        [$dari, $sampai, $perJam] = self::rentang($periode);
        $panjang = $dari->diffInSeconds($sampai);
        [$dariLalu, $sampaiLalu] = [$dari->subSeconds($panjang), $dari];

        return [
            'ringkasan' => $this->ringkasan($dari, $sampai),
            'ringkasan_lalu' => $this->ringkasan($dariLalu, $sampaiLalu),
            'tren' => $this->tren($dari, $sampai, $perJam),
            'tren_lalu' => $this->tren($dariLalu, $sampaiLalu, $perJam),
            'realtime' => $this->realtime(),
            'halaman' => $this->halaman($dari, $sampai),
            'halaman_landing' => $this->halamanLanding($dari, $sampai),
            'halaman_keluar' => $this->halamanKeluar($dari, $sampai),
            'sumber' => $this->sumber($dari, $sampai),
            'kampanye' => $this->dimensiSesi($dari, $sampai, 'kampanye'),
            'referrer' => $this->referrer($dari, $sampai),
            'negara' => $this->negara($dari, $sampai),
            'kota' => $this->dimensi($dari, $sampai, 'kota'),
            'perangkat' => $this->dimensi($dari, $sampai, 'perangkat'),
            'browser' => $this->dimensi($dari, $sampai, 'browser'),
            'os' => $this->dimensi($dari, $sampai, 'os'),
            'layar' => $this->dimensi($dari, $sampai, 'layar'),
            'bahasa' => $this->dimensi($dari, $sampai, 'bahasa'),
            'event' => $this->event($dari, $sampai),
            'jam' => $this->jam($dari, $sampai),
            'dari' => $dari->toDateString(),
            'sampai' => $sampai->subSecond()->toDateString(),
        ];
    }

    /** Statistik per sesi (subquery) dipakai ringkasan & bounce per halaman landing. */
    private function sesi(CarbonImmutable $dari, CarbonImmutable $sampai): Builder
    {
        return $this->q($dari, $sampai, null)->groupBy('sid')->selectRaw(
            "sid, MIN(vid) AS vid, SUM(jenis = 'pv') AS tampilan, COALESCE(SUM(durasi_ms), 0) AS durasi, SUM(jenis = 'ev') AS event,
             (SUM(jenis = 'pv') >= 2 OR COALESCE(SUM(durasi_ms), 0) >= 10000 OR SUM(jenis = 'ev') > 0) AS engaged"
        )->havingRaw("SUM(jenis = 'pv') > 0");
    }

    public function ringkasan(CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        $s = DB::query()->fromSub($this->sesi($dari, $sampai), 's')
            ->selectRaw('COUNT(*) AS sesi, COUNT(DISTINCT vid) AS pengguna, COALESCE(SUM(tampilan), 0) AS tampilan, COALESCE(SUM(engaged), 0) AS engaged, COALESCE(SUM(durasi), 0) AS durasi')
            ->first();
        $baru = $this->q($dari, $sampai)->where('baru', true)->distinct()->count('vid');
        $konversi = $this->q($dari, $sampai, 'ev')->whereIn('event', ['klik_pesan', 'klik_wa', 'klik_daftar'])->count();
        $sesi = (int) $s->sesi;

        return [
            'pengguna' => (int) $s->pengguna,
            'pengguna_baru' => $baru,
            'sesi' => $sesi,
            'tampilan' => (int) $s->tampilan,
            'tampilan_per_sesi' => $sesi ? round($s->tampilan / $sesi, 2) : 0,
            'durasi_per_sesi' => $sesi ? (int) round($s->durasi / $sesi / 1000) : 0, // detik
            'engaged_rate' => $sesi ? round($s->engaged / $sesi, 4) : 0,
            'bounce_rate' => $sesi ? round(1 - $s->engaged / $sesi, 4) : 0,
            'konversi' => $konversi,
        ];
    }

    public function tren(CarbonImmutable $dari, CarbonImmutable $sampai, bool $perJam): array
    {
        $format = $perJam ? '%H' : '%Y-%m-%d';
        $data = $this->q($dari, $sampai)
            ->selectRaw("strftime('$format', waktu) AS k, COUNT(DISTINCT vid) AS pengguna, COUNT(DISTINCT sid) AS sesi, COUNT(*) AS tampilan")
            ->groupBy('k')->get()->keyBy('k');
        $baru = $this->q($dari, $sampai)->where('baru', true)->selectRaw("strftime('$format', waktu) AS k, COUNT(DISTINCT vid) AS n")->groupBy('k')->pluck('n', 'k');
        $konversi = $this->q($dari, $sampai, 'ev')->whereIn('event', ['klik_pesan', 'klik_wa', 'klik_daftar'])
            ->selectRaw("strftime('$format', waktu) AS k, COUNT(*) AS n")->groupBy('k')->pluck('n', 'k');
        $hasil = [];
        for ($t = $dari; $t < $sampai; $t = $perJam ? $t->addHour() : $t->addDay()) {
            $k = $perJam ? $t->format('H') : $t->toDateString();
            $d = $data[$k] ?? null;
            $nanti = $t->isFuture(); // jam/hari yang belum terjadi = null agar garis berhenti di "sekarang"
            $nilai = fn ($v) => $nanti ? null : (int) ($v ?? 0);
            $hasil[] = ['label' => $perJam ? $k.':00' : $k, 'pengguna' => $nilai($d->pengguna ?? null), 'sesi' => $nilai($d->sesi ?? null), 'tampilan' => $nilai($d->tampilan ?? null),
                'baru' => $nilai($baru[$k] ?? null), 'konversi' => $nilai($konversi[$k] ?? null)];
        }

        return $hasil;
    }

    public function realtime(): array
    {
        $kini = CarbonImmutable::now();
        $q = fn ($menit) => $this->q($kini->subMinutes($menit), $kini->addMinute(), null);

        return [
            'aktif_30' => $q(30)->distinct()->count('vid'),
            'aktif_5' => $q(5)->distinct()->count('vid'),
            'per_menit' => $this->perMenit($kini),
            'halaman' => $q(30)->where('jenis', 'pv')->selectRaw('path, COUNT(*) AS tampilan, COUNT(DISTINCT vid) AS pengguna')->groupBy('path')->orderByDesc('tampilan')->limit(10)->get(),
        ];
    }

    private function perMenit(CarbonImmutable $kini): array
    {
        $data = $this->q($kini->subMinutes(30), $kini->addMinute(), null)
            ->selectRaw("strftime('%H:%M', waktu) AS k, COUNT(DISTINCT vid) AS n")->groupBy('k')->pluck('n', 'k');

        return collect(range(29, 0))->map(fn ($m) => ['label' => $kini->subMinutes($m)->format('H:i'), 'n' => (int) ($data[$kini->subMinutes($m)->format('H:i')] ?? 0)])->all();
    }

    public function halaman(CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        $eg = $this->q($dari, $sampai, 'eg')->selectRaw('path, SUM(durasi_ms) AS durasi, AVG(scroll) AS scroll')->groupBy('path');

        return $this->q($dari, $sampai, 'pv', 'h')
            ->leftJoinSub($eg, 'eg', 'eg.path', '=', 'h.path')
            ->selectRaw('h.path, MAX(h.judul) AS judul, COUNT(*) AS tampilan, COUNT(DISTINCT h.vid) AS pengguna, MAX(eg.durasi) AS durasi, MAX(eg.scroll) AS scroll')
            ->groupBy('h.path')->orderByDesc('tampilan')->limit(25)->get()
            ->map(fn ($r) => [
                'path' => $r->path, 'judul' => $r->judul, 'tampilan' => (int) $r->tampilan, 'pengguna' => (int) $r->pengguna,
                'durasi' => $r->tampilan ? (int) round(($r->durasi ?? 0) / $r->tampilan / 1000) : 0, // detik per tampilan
                'scroll' => $r->scroll === null ? null : (int) round($r->scroll),
            ])->all();
    }

    public function halamanLanding(CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        $sesi = $this->sesi($dari, $sampai);

        return $this->q($dari, $sampai, 'pv', 'h')->where('h.awal_sesi', true)
            ->joinSub($sesi, 's', 's.sid', '=', 'h.sid')
            ->selectRaw('h.path, COUNT(*) AS sesi, AVG(s.engaged) AS engaged, AVG(s.durasi) AS durasi')
            ->groupBy('h.path')->orderByDesc('sesi')->limit(25)->get()
            ->map(fn ($r) => ['path' => $r->path, 'sesi' => (int) $r->sesi, 'bounce_rate' => round(1 - $r->engaged, 4), 'durasi' => (int) round($r->durasi / 1000)])->all();
    }

    public function halamanKeluar(CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        $terakhir = $this->q($dari, $sampai)->selectRaw('sid, MAX(waktu) AS waktu')->groupBy('sid');

        return $this->q($dari, $sampai, 'pv', 'h')
            ->joinSub($terakhir, 't', fn ($j) => $j->on('t.sid', '=', 'h.sid')->on('t.waktu', '=', 'h.waktu'))
            ->selectRaw('h.path, COUNT(DISTINCT h.sid) AS keluar')->groupBy('h.path')->orderByDesc('keluar')->limit(25)->get()
            ->map(fn ($r) => ['path' => $r->path, 'keluar' => (int) $r->keluar])->all();
    }

    public function sumber(CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        $sesi = $this->sesi($dari, $sampai);

        return $this->q($dari, $sampai, 'pv', 'h')->where('h.awal_sesi', true)
            ->joinSub($sesi, 's', 's.sid', '=', 'h.sid')
            ->selectRaw("h.sumber || ' / ' || h.medium AS nama, h.medium, COUNT(*) AS sesi, COUNT(DISTINCT h.vid) AS pengguna, AVG(s.engaged) AS engaged, SUM(s.event) AS event")
            ->groupBy('h.sumber', 'h.medium')->orderByDesc('sesi')->limit(25)->get()
            ->map(fn ($r) => ['nama' => $r->nama, 'medium' => $r->medium, 'sesi' => (int) $r->sesi, 'pengguna' => (int) $r->pengguna, 'engaged_rate' => round($r->engaged, 4), 'event' => (int) $r->event])->all();
    }

    private function dimensiSesi(CarbonImmutable $dari, CarbonImmutable $sampai, string $kolom): array
    {
        return $this->q($dari, $sampai)->where('awal_sesi', true)->whereNotNull($kolom)
            ->selectRaw("$kolom AS nama, COUNT(*) AS sesi, COUNT(DISTINCT vid) AS pengguna")->groupBy($kolom)->orderByDesc('sesi')->limit(25)->get()
            ->map(fn ($r) => ['nama' => $r->nama, 'sesi' => (int) $r->sesi, 'pengguna' => (int) $r->pengguna])->all();
    }

    public function referrer(CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        return $this->q($dari, $sampai)->whereNotNull('referrer')
            ->selectRaw('referrer AS nama, COUNT(*) AS tampilan, COUNT(DISTINCT vid) AS pengguna')->groupBy('referrer')->orderByDesc('tampilan')->limit(25)->get()
            ->map(fn ($r) => ['nama' => $r->nama, 'tampilan' => (int) $r->tampilan, 'pengguna' => (int) $r->pengguna])->all();
    }

    /** Pengguna unik per nilai dimensi (perangkat, browser, os, layar, bahasa, kota). */
    public function dimensi(CarbonImmutable $dari, CarbonImmutable $sampai, string $kolom): array
    {
        return $this->q($dari, $sampai)->whereNotNull($kolom)
            ->selectRaw("$kolom AS nama, COUNT(DISTINCT vid) AS pengguna, COUNT(DISTINCT sid) AS sesi, COUNT(*) AS tampilan")
            ->groupBy($kolom)->orderByDesc('pengguna')->limit(25)->get()
            ->map(fn ($r) => ['nama' => $r->nama, 'pengguna' => (int) $r->pengguna, 'sesi' => (int) $r->sesi, 'tampilan' => (int) $r->tampilan])->all();
    }

    public function negara(CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        return array_map(fn ($r) => [...$r, 'kode' => $r['nama'], 'nama' => Locale::getDisplayRegion('-'.$r['nama'], 'id') ?: $r['nama']], $this->dimensi($dari, $sampai, 'negara'));
    }

    public function event(CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        return $this->q($dari, $sampai, 'ev')
            ->selectRaw('event, label, COUNT(*) AS jumlah, COUNT(DISTINCT vid) AS pengguna')->groupBy('event', 'label')->orderByDesc('jumlah')->limit(200)->get() // label terpecah per halaman|lokasi sejak 24 Nov
            ->map(fn ($r) => ['event' => $r->event, 'label' => $r->label, 'jumlah' => (int) $r->jumlah, 'pengguna' => (int) $r->pengguna])->all();
    }

    /** Tampilan per hari-dalam-minggu (0 = Minggu) x jam. */
    public function jam(CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        return $this->q($dari, $sampai)->selectRaw("CAST(strftime('%w', waktu) AS INTEGER) AS hari, CAST(strftime('%H', waktu) AS INTEGER) AS jam, COUNT(*) AS n")
            ->groupBy('hari', 'jam')->get()->map(fn ($r) => [(int) $r->hari, (int) $r->jam, (int) $r->n])->all();
    }
}
