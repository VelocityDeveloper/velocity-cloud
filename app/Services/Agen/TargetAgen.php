<?php

namespace App\Services\Agen;

use App\Enums\InvoiceStatus;
use App\Models\AgenCatatan;
use App\Models\Invoice;
use App\Models\Pengaturan;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Target kinerja agen (mingguan & bulanan), arahan pemilik, dan perhitungan corong asli.
 * Disimpan di tabel pengaturan (kunci agen.target & agen.arahan) agar dibaca bersama oleh MCP dan /admin/agen.
 */
class TargetAgen
{
    public const ZONA = 'Asia/Jakarta';

    public const KUNCI_TARGET = 'agen.target';

    public const KUNCI_ARAHAN = 'agen.arahan';

    /** Target resmi 24 Nov 2026 (bisa diubah pemilik dari /admin/agen). */
    public const BAWAAN = [
        'mingguan' => [
            'sesi_asli' => 15,
            'klik_pesan' => 6,
            'klik_daftar' => 3,
            'order_lunas' => 2,
        ],
        'bulanan' => [
            'sesi_asli' => 60,
            'klik_pesan' => 25,
            'klik_daftar' => 12,
            'order_lunas' => 8,
            'omzet_lunas' => 2000000,
        ],
    ];

    public static function target(): array
    {
        $tersimpan = Pengaturan::ambil(self::KUNCI_TARGET, []);
        if (! is_array($tersimpan)) {
            $tersimpan = [];
        }

        return [
            'mingguan' => array_merge(self::BAWAAN['mingguan'], is_array($tersimpan['mingguan'] ?? null) ? $tersimpan['mingguan'] : []),
            'bulanan' => array_merge(self::BAWAAN['bulanan'], is_array($tersimpan['bulanan'] ?? null) ? $tersimpan['bulanan'] : []),
        ];
    }

    public static function simpanTarget(array $data): array
    {
        $bersih = [
            'mingguan' => [
                'sesi_asli' => max(1, (int) ($data['mingguan']['sesi_asli'] ?? self::BAWAAN['mingguan']['sesi_asli'])),
                'klik_pesan' => max(1, (int) ($data['mingguan']['klik_pesan'] ?? self::BAWAAN['mingguan']['klik_pesan'])),
                'klik_daftar' => max(1, (int) ($data['mingguan']['klik_daftar'] ?? self::BAWAAN['mingguan']['klik_daftar'])),
                'order_lunas' => max(1, (int) ($data['mingguan']['order_lunas'] ?? self::BAWAAN['mingguan']['order_lunas'])),
            ],
            'bulanan' => [
                'sesi_asli' => max(1, (int) ($data['bulanan']['sesi_asli'] ?? self::BAWAAN['bulanan']['sesi_asli'])),
                'klik_pesan' => max(1, (int) ($data['bulanan']['klik_pesan'] ?? self::BAWAAN['bulanan']['klik_pesan'])),
                'klik_daftar' => max(1, (int) ($data['bulanan']['klik_daftar'] ?? self::BAWAAN['bulanan']['klik_daftar'])),
                'order_lunas' => max(1, (int) ($data['bulanan']['order_lunas'] ?? self::BAWAAN['bulanan']['order_lunas'])),
                'omzet_lunas' => max(0, (int) ($data['bulanan']['omzet_lunas'] ?? self::BAWAAN['bulanan']['omzet_lunas'])),
            ],
        ];
        Pengaturan::simpan(self::KUNCI_TARGET, $bersih);

        return $bersih;
    }

    /** @return array{teks: string, diperbarui_pada: ?string} */
    public static function arahan(): array
    {
        $a = Pengaturan::ambil(self::KUNCI_ARAHAN, null);
        if (! is_array($a)) {
            return ['teks' => '', 'diperbarui_pada' => null];
        }

        return [
            'teks' => trim((string) ($a['teks'] ?? '')),
            'diperbarui_pada' => $a['diperbarui_pada'] ?? null,
        ];
    }

    public static function simpanArahan(string $teks): array
    {
        $data = [
            'teks' => trim($teks),
            'diperbarui_pada' => now(self::ZONA)->toIso8601String(),
        ];
        Pengaturan::simpan(self::KUNCI_ARAHAN, $data);

        return $data;
    }

    /**
     * Corong situs → dashboard dalam rentang [dari, sampai) zona aplikasi (WIB).
     * sesi_asli = sesi non-internal yang berasal dari mesin pencari (organic) ATAU negara Indonesia (ID).
     * omzet_lunas = hanya member organik (sumber pertama Langsung atau google / organic); tanpa sumber = akun internal tim.
     */
    public static function corong(CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        $hit = fn () => DB::table('analitik_hits')
            ->where('internal', false)
            ->where('waktu', '>=', $dari->toDateTimeString())
            ->where('waktu', '<', $sampai->toDateTimeString());

        $sesiAsli = $hit()->where('jenis', 'pv')
            ->where(fn ($q) => $q->where('medium', 'organic')->orWhere('negara', 'ID'))
            ->distinct()->count('sid');

        $lunasQuery = Invoice::where('status', InvoiceStatus::Lunas)
            ->where('dibayar_pada', '>=', $dari)
            ->where('dibayar_pada', '<', $sampai)
            ->whereHas('service.user', fn ($q) => $q->where('is_admin', false));

        return [
            'dari' => $dari->toDateString(),
            'sampai' => $sampai->subSecond()->toDateString(),
            'sesi_asli' => $sesiAsli,
            'sesi_total' => $hit()->where('jenis', 'pv')->distinct()->count('sid'),
            'pengunjung' => $hit()->where('jenis', 'pv')->distinct()->count('vid'),
            'tampilan' => $hit()->where('jenis', 'pv')->count(),
            'klik_pesan' => $hit()->where('jenis', 'ev')->where('event', 'klik_pesan')->count(),
            'klik_daftar' => $hit()->where('jenis', 'ev')->where('event', 'klik_daftar')->count(),
            'klik_wa' => $hit()->where('jenis', 'ev')->where('event', 'klik_wa')->count(),
            'daftar' => User::where('is_admin', false)->where('created_at', '>=', $dari)->where('created_at', '<', $sampai)->count(),
            'pesanan' => Service::where('created_at', '>=', $dari)->where('created_at', '<', $sampai)
                ->whereHas('user', fn ($q) => $q->where('is_admin', false))->count(),
            'lunas' => (clone $lunasQuery)->count(),
            'omzet_lunas' => self::omzetOrganik($lunasQuery),
        ];
    }

    /** Jumlah tagihan lunas milik member yang tampilan halaman pertamanya (first touch, sama dengan /admin/pengguna) Langsung atau google / organic. */
    private static function omzetOrganik($lunasQuery): int
    {
        $lunas = (clone $lunasQuery)->with('service.user:id,analitik_vid')->get(['id', 'service_id', 'jumlah']);
        $pertama = DB::table('analitik_hits')->where('jenis', 'pv')
            ->whereIn('vid', $lunas->pluck('service.user.analitik_vid')->filter()->unique()->values())
            ->orderBy('waktu')->get(['vid', 'sumber', 'medium'])
            ->unique('vid')->keyBy('vid');

        return (int) $lunas->filter(function (Invoice $i) use ($pertama) {
            $h = $pertama[$i->service?->user?->analitik_vid] ?? null;

            return $h && ($h->sumber === '(direct)' || ($h->sumber === 'google' && $h->medium === 'organic'));
        })->sum('jumlah');
    }

    /** Corong N hari mundur dari awal hari ini (sama dengan pola MulaiSiklus lama, kini lengkap sesi_asli & klik_daftar). */
    public static function corongHari(int $hari, int $mundur = 0, ?CarbonImmutable $kini = null): array
    {
        $sampai = ($kini ?? CarbonImmutable::now(self::ZONA))->startOfDay()->subDays($mundur);
        $dari = $sampai->subDays($hari);

        return self::corong($dari, $sampai);
    }

    /** Progres target mingguan (7 hari sampai sekarang) & bulanan (awal bulan WIB sampai sekarang) untuk dashboard dan MCP. */
    public static function progres(?CarbonImmutable $kini = null): array
    {
        $sekarang = $kini ?? CarbonImmutable::now(self::ZONA);
        $target = self::target();

        $aktualMingguan = self::corong($sekarang->subDays(6)->startOfDay(), $sekarang->addMinute());
        $aktualBulanan = self::corong($sekarang->startOfMonth(), $sekarang->addMinute());

        $skor = static function (int $aktual, int $nilaiTarget): array {
            $persen = $nilaiTarget > 0 ? min(999, (int) round(($aktual / $nilaiTarget) * 100)) : 100;
            $status = match (true) {
                $aktual >= $nilaiTarget => 'tercapai',
                $persen >= 60 => 'mendekati',
                default => 'merah',
            };

            return ['aktual' => $aktual, 'target' => $nilaiTarget, 'persen' => $persen, 'status' => $status];
        };

        return [
            'mingguan' => [
                'periode' => $aktualMingguan['dari'].' s.d. '.$sekarang->toDateString(),
                'item' => [
                    'sesi_asli' => $skor($aktualMingguan['sesi_asli'], $target['mingguan']['sesi_asli']),
                    'klik_pesan' => $skor($aktualMingguan['klik_pesan'], $target['mingguan']['klik_pesan']),
                    'klik_daftar' => $skor($aktualMingguan['klik_daftar'], $target['mingguan']['klik_daftar']),
                    'order_lunas' => $skor($aktualMingguan['lunas'], $target['mingguan']['order_lunas']),
                ],
            ],
            'bulanan' => [
                'periode' => $sekarang->locale('id')->translatedFormat('F Y'),
                'item' => [
                    'sesi_asli' => $skor($aktualBulanan['sesi_asli'], $target['bulanan']['sesi_asli']),
                    'klik_pesan' => $skor($aktualBulanan['klik_pesan'], $target['bulanan']['klik_pesan']),
                    'klik_daftar' => $skor($aktualBulanan['klik_daftar'], $target['bulanan']['klik_daftar']),
                    'order_lunas' => $skor($aktualBulanan['lunas'], $target['bulanan']['order_lunas']),
                    'omzet_lunas' => $skor($aktualBulanan['omzet_lunas'], $target['bulanan']['omzet_lunas']),
                ],
            ],
        ];
    }

    /** Ringkasan kesehatan siklus agen untuk header /admin/agen. */
    public static function ringkasanSiklus(): array
    {
        $dievaluasi = AgenCatatan::where('jenis', 'tindakan')->whereNotNull('dievaluasi_pada')->get();
        $putusan = $dievaluasi->countBy(fn (AgenCatatan $c) => $c->hasil['putusan'] ?? 'lainnya');

        $menungguEval = AgenCatatan::where('jenis', 'tindakan')
            ->where('status', 'diterapkan')
            ->whereNotNull('metrik_awal')
            ->whereNull('dievaluasi_pada')
            ->where('created_at', '<=', now()->subDays(AgenCatatan::HARI_EVALUASI))
            ->count();

        $laporanTerakhir = AgenCatatan::where('jenis', 'laporan')->latest('created_at')->first(['id', 'agen', 'judul', 'created_at']);
        $artikelTerakhir = AgenCatatan::where('jenis', 'tindakan')->where('agen', 'Kang SEO')->latest('created_at')->first(['id', 'judul', 'target', 'created_at']);

        return [
            'tindakan_diterapkan' => AgenCatatan::where('jenis', 'tindakan')->where('status', 'diterapkan')->count(),
            'siap_dievaluasi' => $menungguEval,
            'evaluasi' => [
                'berhasil' => (int) ($putusan['berhasil'] ?? 0),
                'netral' => (int) ($putusan['netral'] ?? 0),
                'gagal' => (int) ($putusan['gagal'] ?? 0),
            ],
            'laporan_terakhir' => $laporanTerakhir ? [
                'agen' => $laporanTerakhir->agen,
                'judul' => $laporanTerakhir->judul,
                'waktu' => $laporanTerakhir->created_at?->toIso8601String(),
            ] : null,
            'artikel_terakhir' => $artikelTerakhir ? [
                'judul' => $artikelTerakhir->judul,
                'target' => $artikelTerakhir->target,
                'waktu' => $artikelTerakhir->created_at?->toIso8601String(),
            ] : null,
        ];
    }
}
