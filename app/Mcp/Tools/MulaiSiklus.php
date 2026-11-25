<?php

namespace App\Mcp\Tools;

use App\Models\AgenCatatan;
use App\Models\AgenPelajaran;
use App\Services\Agen\MetrikHalaman;
use App\Services\Agen\TargetAgen;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('WAJIB dipanggil pertama di setiap siklus. Berisi: arahan pemilik terbaru, progres target mingguan & bulanan, pelajaran aktif (playbook berskor), tindakan yang jatuh tempo dievaluasi lengkap dengan metrik awal vs kini, usulan disetujui, masalah terbuka, dan corong 7 hari (termasuk sesi asli organik/Indonesia dan klik daftar) vs 7 hari sebelumnya.')]
class MulaiSiklus extends Tool
{
    public function handle(Request $request, MetrikHalaman $metrik): Response
    {
        $jatuhTempo = AgenCatatan::where('jenis', 'tindakan')->where('status', 'diterapkan')
            ->whereNotNull('metrik_awal')->whereNull('dievaluasi_pada')
            ->where('created_at', '<=', now()->subDays(AgenCatatan::HARI_EVALUASI))
            ->oldest()->limit(10)->get();

        $arahan = TargetAgen::arahan();

        return Response::json([
            'tanggal' => now(TargetAgen::ZONA)->toDateString(),
            'hari' => now(TargetAgen::ZONA)->locale('id')->dayName,
            'arahan_pemilik' => $arahan['teks'] !== '' ? $arahan : null,
            'target_dan_progres' => TargetAgen::progres(),
            'pelajaran_aktif' => AgenPelajaran::where('status', 'aktif')->orderByDesc('skor')->limit(40)->get(['id', 'judul', 'isi', 'skor', 'bukti', 'updated_at']),
            'tindakan_perlu_evaluasi' => $jatuhTempo->map(fn (AgenCatatan $c) => [
                'id' => $c->id, 'judul' => $c->judul, 'target' => $c->target, 'diterapkan' => $c->created_at->toDateString(),
                'umur_hari' => (int) $c->created_at->diffInDays(now()),
                'isi' => mb_substr($c->isi, 0, 1500),
                'metrik_awal' => $c->metrik_awal,
                'metrik_kini' => $metrik->ambil($c->metrik_awal['path'], self::hari($c->metrik_awal)),
            ]),
            'tindakan_belum_terukur' => AgenCatatan::where('jenis', 'tindakan')->where('status', 'diterapkan')->whereNull('metrik_awal')
                ->where('created_at', '>=', now()->subDays(30))->count(),
            'usulan_disetujui' => AgenCatatan::where('jenis', 'tindakan')->where('status', 'disetujui')->get(['id', 'judul', 'isi', 'target']),
            'masalah_terbuka' => AgenCatatan::where('jenis', 'masalah')->where('status', 'terbuka')->latest('id')->limit(10)->get(['id', 'judul', 'agen', 'diskusi_url', 'created_at']),
            'corong_7_hari' => TargetAgen::corongHari(7, 0),
            'corong_7_hari_sebelumnya' => TargetAgen::corongHari(7, 7),
            'hasil_evaluasi_30_hari' => AgenCatatan::whereNotNull('dievaluasi_pada')->where('dievaluasi_pada', '>=', now()->subDays(30))->get()
                ->countBy(fn ($c) => $c->hasil['putusan'] ?? '-'),
        ]);
    }

    private static function hari(array $awal): int
    {
        return max(1, (int) round((strtotime($awal['sampai']) - strtotime($awal['dari'])) / 86400) + 1);
    }
}
