<?php

namespace App\Mcp\Tools;

use App\Enums\InvoiceStatus;
use App\Models\AgenCatatan;
use App\Models\AgenPelajaran;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\Agen\MetrikHalaman;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('WAJIB dipanggil pertama di setiap siklus. Berisi: pelajaran aktif (playbook berskor), tindakan yang jatuh tempo dievaluasi lengkap dengan metrik awal vs metrik kini, usulan yang sudah disetujui admin, dan corong 7 hari vs 7 hari sebelumnya (kunjungan → klik pesan → daftar → pesanan → lunas).')]
class MulaiSiklus extends Tool
{
    public function handle(Request $request, MetrikHalaman $metrik): Response
    {
        $jatuhTempo = AgenCatatan::where('jenis', 'tindakan')->where('status', 'diterapkan')
            ->whereNotNull('metrik_awal')->whereNull('dievaluasi_pada')
            ->where('created_at', '<=', now()->subDays(AgenCatatan::HARI_EVALUASI))
            ->oldest()->limit(10)->get();

        return Response::json([
            'tanggal' => now('Asia/Jakarta')->toDateString(),
            'hari' => now('Asia/Jakarta')->locale('id')->dayName,
            'pelajaran_aktif' => AgenPelajaran::where('status', 'aktif')->orderByDesc('skor')->limit(40)->get(['id', 'judul', 'isi', 'skor', 'bukti', 'updated_at']),
            'tindakan_perlu_evaluasi' => $jatuhTempo->map(fn (AgenCatatan $c) => [
                'id' => $c->id, 'judul' => $c->judul, 'target' => $c->target, 'diterapkan' => $c->created_at->toDateString(),
                'isi' => mb_substr($c->isi, 0, 1500),
                'metrik_awal' => $c->metrik_awal,
                'metrik_kini' => $metrik->ambil($c->metrik_awal['path'], self::hari($c->metrik_awal)),
            ]),
            'tindakan_belum_terukur' => AgenCatatan::where('jenis', 'tindakan')->where('status', 'diterapkan')->whereNull('metrik_awal')
                ->where('created_at', '>=', now()->subDays(30))->count(),
            'usulan_disetujui' => AgenCatatan::where('jenis', 'tindakan')->where('status', 'disetujui')->get(['id', 'judul', 'isi', 'target']),
            'corong_7_hari' => self::corong(7, 0),
            'corong_7_hari_sebelumnya' => self::corong(7, 7),
            'hasil_evaluasi_30_hari' => AgenCatatan::whereNotNull('dievaluasi_pada')->where('dievaluasi_pada', '>=', now()->subDays(30))->get()
                ->countBy(fn ($c) => $c->hasil['putusan'] ?? '-'),
        ]);
    }

    private static function hari(array $awal): int
    {
        return max(1, (int) round((strtotime($awal['sampai']) - strtotime($awal['dari'])) / 86400) + 1);
    }

    /** Corong situs → dashboard; trafik kantor tidak dihitung. */
    private static function corong(int $hari, int $mundur): array
    {
        $sampai = now()->startOfDay()->subDays($mundur);
        $dari = $sampai->copy()->subDays($hari);
        $hit = fn () => DB::table('analitik_hits')->where('internal', false)
            ->where('waktu', '>=', $dari->toDateTimeString())->where('waktu', '<', $sampai->toDateTimeString());

        return [
            'dari' => $dari->toDateString(), 'sampai' => $sampai->copy()->subDay()->toDateString(),
            'pengunjung' => $hit()->where('jenis', 'pv')->distinct()->count('vid'),
            'tampilan' => $hit()->where('jenis', 'pv')->count(),
            'klik_pesan' => $hit()->where('jenis', 'ev')->where('event', 'klik_pesan')->count(),
            'klik_wa' => $hit()->where('jenis', 'ev')->where('event', 'klik_wa')->count(),
            'daftar' => User::where('is_admin', false)->whereBetween('created_at', [$dari, $sampai])->count(),
            'pesanan' => Service::whereBetween('created_at', [$dari, $sampai])->whereHas('user', fn ($q) => $q->where('is_admin', false))->count(),
            'lunas' => Invoice::where('status', InvoiceStatus::Lunas)->whereBetween('dibayar_pada', [$dari, $sampai])
                ->whereHas('service.user', fn ($q) => $q->where('is_admin', false))->count(),
        ];
    }
}
