<?php

namespace App\Services\Analitik;

/** Mengubah satu baris log beacon nginx menjadi baris analitik_hits (atau null bila tidak valid / bot). */
class Pengurai
{
    private const MESIN_CARI = ['google' => 'google', 'bing' => 'bing', 'yahoo' => 'yahoo', 'duckduckgo' => 'duckduckgo', 'yandex' => 'yandex', 'ecosia' => 'ecosia', 'baidu' => 'baidu'];

    private const SOSIAL = ['facebook' => 'facebook', 'fb.' => 'facebook', 'instagram' => 'instagram', 't.co' => 'x', 'twitter' => 'x', 'x.com' => 'x', 'linkedin' => 'linkedin', 'lnkd.in' => 'linkedin', 'youtube' => 'youtube', 'tiktok' => 'tiktok', 'whatsapp' => 'whatsapp', 'wa.me' => 'whatsapp', 't.me' => 'telegram', 'telegram' => 'telegram', 'threads' => 'threads', 'pinterest' => 'pinterest'];

    /** @param  list<string>  $ipInternal */
    public function __construct(private array $ipInternal = []) {}

    public function urai(string $baris): ?array
    {
        $log = json_decode($baris, true);
        if (! is_array($log) || empty($log['q']) || empty($log['t'])) {
            return null;
        }
        $ua = (string) ($log['ua'] ?? '');
        if ($ua === '' || preg_match('/bot|crawl|spider|slurp|headless|lighthouse|preview|curl|wget|python|scrapy/i', $ua)) {
            return null;
        }
        parse_str($log['q'], $q);
        $jenis = $q['e'] ?? '';
        $id = fn ($k) => is_string($q[$k] ?? null) && preg_match('/^[a-z0-9]{8,24}$/', $q[$k]) ? $q[$k] : null;
        if (! in_array($jenis, ['pv', 'eg', 'ev'], true) || ! $id('vid') || ! $id('sid') || ! $id('pid')) {
            return null;
        }
        $teks = fn ($k, $max) => is_string($q[$k] ?? null) && $q[$k] !== '' ? mb_substr(strip_tags($q[$k]), 0, $max) : null;
        $path = $teks('p', 200);
        if (! $path || $path[0] !== '/') {
            return null;
        }

        $baris = [
            'waktu' => date('Y-m-d H:i:s', strtotime($log['t'])),
            'jenis' => $jenis,
            'vid' => $id('vid'), 'sid' => $id('sid'), 'pid' => $id('pid'),
            'path' => $path,
            'judul' => null, 'referrer' => null, 'sumber' => null, 'medium' => null, 'kampanye' => null,
            'negara' => preg_match('/^[A-Z]{2}$/', $log['negara'] ?? '') && $log['negara'] !== 'XX' ? $log['negara'] : null,
            'kota' => ($log['kota'] ?? '') !== '' ? mb_substr($log['kota'], 0, 60) : null,
            ...$this->perangkat($ua),
            'layar' => null, 'bahasa' => null, 'event' => null, 'label' => null, 'durasi_ms' => null, 'scroll' => null,
            'baru' => ($q['n'] ?? '') === '1',
            'awal_sesi' => ($q['s'] ?? '') === '1',
            'masuk' => ($q['m'] ?? '') === '1',
            'internal' => in_array($log['ip'] ?? '', $this->ipInternal, true),
        ];

        if ($jenis === 'pv') {
            $baris['judul'] = $teks('t', 150);
            $baris['referrer'] = $teks('r', 300);
            $w = (int) ($q['w'] ?? 0);
            $h = (int) ($q['h'] ?? 0);
            $baris['layar'] = $w > 0 && $h > 0 && $w < 10000 && $h < 10000 ? "{$w}x{$h}" : null;
            $baris['bahasa'] = preg_match('/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,4})?$/', $q['l'] ?? '') ? $q['l'] : null;
            [$baris['sumber'], $baris['medium']] = $this->sumber($baris['referrer'], $teks('utm_source', 100), $teks('utm_medium', 50));
            $baris['kampanye'] = $teks('utm_campaign', 100);
        } elseif ($jenis === 'eg') {
            $baris['durasi_ms'] = min(max((int) ($q['d'] ?? 0), 0), 3_600_000); // maks 1 jam per laporan
            $baris['scroll'] = min(max((int) ($q['sc'] ?? 0), 0), 100);
        } else {
            $baris['event'] = preg_match('/^[a-z_]{2,30}$/', $q['en'] ?? '') ? $q['en'] : null;
            $baris['label'] = $teks('el', 150);
            if (! $baris['event']) {
                return null;
            }
        }

        return $baris;
    }

    /** @return array{0: string, 1: string} [sumber, medium] ala GA */
    public function sumber(?string $referrer, ?string $utmSumber = null, ?string $utmMedium = null): array
    {
        if ($utmSumber) {
            return [mb_strtolower($utmSumber), mb_strtolower($utmMedium ?? '(not set)')];
        }
        $host = $referrer ? mb_strtolower((string) parse_url($referrer, PHP_URL_HOST)) : '';
        if ($host === '') {
            return ['(direct)', '(none)'];
        }
        $host = preg_replace('/^(www|m|l|lm)\./', '', $host);
        foreach (self::MESIN_CARI as $kunci => $nama) {
            if (preg_match('/(^|\.)'.preg_quote($kunci).'\./', $host)) {
                return [$nama, 'organic'];
            }
        }
        foreach (self::SOSIAL as $kunci => $nama) {
            if (str_contains($host, $kunci)) {
                return [$nama, 'social'];
            }
        }

        return [$host, 'referral'];
    }

    /** @return array{perangkat: string, browser: string, os: string} */
    public function perangkat(string $ua): array
    {
        $perangkat = match (true) {
            (bool) preg_match('/iPad|Tablet|Android(?!.*Mobile)/i', $ua) => 'tablet',
            (bool) preg_match('/Mobi|iPhone|Android/i', $ua) => 'mobile',
            default => 'desktop',
        };
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Lainnya',
        };
        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Android') => 'Android',
            (bool) preg_match('/iPhone|iPad|iPod/', $ua) => 'iOS',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Lainnya',
        };

        return compact('perangkat', 'browser', 'os');
    }
}
