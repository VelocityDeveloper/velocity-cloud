<?php

namespace App\Services\TokenAi;

use Carbon\CarbonInterface as Carbon;
use Illuminate\Support\Facades\DB;

/** Analitik pemakaian token AI untuk admin (/admin/paket/ai). Sumber: token_ai_pemakaian yang ditulis velocity-ai-proxy. */
class LaporanToken
{
    public const PERIODE = ['7h' => '7 hari', '30h' => '30 hari', '90h' => '90 hari'];

    /** Nilai rupiah = saldo terpotong × harga output paket layanan saat ini (rumus sama dengan riwayat pemakaian pelanggan). */
    private const NILAI = 'coalesce(sum(coalesce(token_ai_pemakaian.potong, token_ai_pemakaian.total) * (select p.harga from token_ai t
        join services s on s.id = t.service_id join plans p on p.id = s.plan_id where t.id = token_ai_pemakaian.token_ai_id)), 0) / 1000000.0';

    public function semua(string $periode): array
    {
        $hari = (int) $periode;
        $sampai = now()->endOfDay();
        $dari = now()->subDays($hari - 1)->startOfDay();
        $dariLalu = $dari->copy()->subDays($hari);

        return [
            'dari' => $dari->toDateString(),
            'sampai' => $sampai->toDateString(),
            'ringkasan' => $this->ringkasan($dari, $sampai),
            'ringkasan_lalu' => $this->ringkasan($dariLalu, $dari->copy()->subSecond()),
            'tren' => $this->tren($dari, $hari),
            'tren_lalu' => $this->tren($dariLalu, $hari),
            'pelanggan' => $this->pelanggan($dari, $sampai),
            'model' => $this->model($dari, $sampai),
            'gagal' => $this->gagal($dari, $sampai),
            'jam' => $this->q($dari, $sampai)->selectRaw("cast(strftime('%w', created_at) as integer) h, cast(strftime('%H', created_at) as integer) j, count(*) n")
                ->groupBy('h', 'j')->get()->map(fn ($r) => [$r->h, $r->j, $r->n]),
        ];
    }

    /** $tokenAi diisi = tampilan pelanggan: hanya miliknya dan hanya request sukses. */
    private function q(Carbon $dari, Carbon $sampai, ?int $tokenAi = null)
    {
        return DB::table('token_ai_pemakaian')->whereBetween('token_ai_pemakaian.created_at', [$dari->toDateTimeString(), $sampai->toDateTimeString()])
            ->when($tokenAi, fn ($q) => $q->where('token_ai_id', $tokenAi)->where('status', 200));
    }

    private function ringkasan(Carbon $dari, Carbon $sampai): array
    {
        $r = $this->q($dari, $sampai)->selectRaw('count(*) request,
            sum(status = 200) sukses, sum(status != 200) gagal, sum(taksiran) taksiran,
            coalesce(sum(total), 0) token, coalesce(sum(prompt), 0) prompt, coalesce(sum(completion), 0) completion, coalesce(sum(cache_hit), 0) cache_hit,
            avg(case when status = 200 then durasi_ms end) durasi, count(distinct case when status = 200 then token_ai_id end) pelanggan, '.self::NILAI.' nilai')->first();

        return [
            'request' => (int) $r->request,
            'token' => (int) $r->token,
            'prompt' => (int) $r->prompt,
            'completion' => (int) $r->completion,
            'cache_hit' => (int) $r->cache_hit,
            'cache_rate' => $r->prompt ? $r->cache_hit / $r->prompt : 0,
            'durasi' => (int) round($r->durasi ?? 0),
            'gagal' => (int) $r->gagal,
            'gagal_rate' => $r->request ? $r->gagal / $r->request : 0,
            'taksiran' => (int) $r->taksiran,
            'pelanggan' => (int) $r->pelanggan,
            'nilai' => (int) round($r->nilai),
        ];
    }

    /** Satu baris per hari (termasuk hari tanpa pemakaian) agar dua periode bisa disejajarkan. */
    public function tren(Carbon $dari, int $hari, ?int $tokenAi = null): array
    {
        $data = $this->q($dari, $dari->copy()->addDays($hari)->subSecond(), $tokenAi)
            ->selectRaw('date(created_at) d, count(*) request, coalesce(sum(total), 0) token, coalesce(sum(cache_hit), 0) cache_hit, '.self::NILAI.' nilai')
            ->groupBy('d')->get()->keyBy('d');

        return collect(range(0, $hari - 1))->map(function ($i) use ($dari, $data) {
            $d = $dari->copy()->addDays($i)->toDateString();

            return ['label' => $dari->copy()->addDays($i)->format('d/m'), 'request' => (int) ($data[$d]->request ?? 0),
                'token' => (int) ($data[$d]->token ?? 0), 'cache_hit' => (int) ($data[$d]->cache_hit ?? 0), 'nilai' => (int) round($data[$d]->nilai ?? 0)];
        })->all();
    }

    private function pelanggan(Carbon $dari, Carbon $sampai): array
    {
        return $this->q($dari, $sampai)
            ->join('token_ai', 'token_ai.id', '=', 'token_ai_pemakaian.token_ai_id')
            ->join('services', 'services.id', '=', 'token_ai.service_id')
            ->join('users', 'users.id', '=', 'services.user_id')
            ->groupBy('token_ai.id', 'users.id', 'users.name', 'services.label', 'services.id', 'token_ai.saldo', 'token_ai.terpakai')
            ->selectRaw('services.id layanan_id, users.id user_id, users.name pengguna, services.label layanan, users.name || \' · \' || services.label nama, count(*) request, '.self::NILAI.' nilai,
                coalesce(sum(token_ai_pemakaian.total), 0) token, coalesce(sum(token_ai_pemakaian.prompt), 0) prompt, coalesce(sum(token_ai_pemakaian.cache_hit), 0) cache_hit, sum(token_ai_pemakaian.status != 200) gagal,
                token_ai.saldo sisa, token_ai.terpakai terpakai, max(token_ai_pemakaian.created_at) terakhir')
            ->orderByDesc('token')->get()
            ->map(fn ($r) => [
                'layanan_id' => $r->layanan_id,
                'nama' => $r->nama,
                'user_id' => $r->user_id,
                'pengguna' => $r->pengguna,
                'layanan' => $r->layanan,
                'nilai' => (int) round($r->nilai),
                'token' => (int) $r->token,
                'request' => (int) $r->request,
                'cache_rate' => $r->prompt ? $r->cache_hit / $r->prompt : 0,
                'gagal' => (int) $r->gagal,
                'sisa' => max(0, (int) $r->sisa),
                'kuota_terpakai' => ($kuota = max(0, $r->sisa) + $r->terpakai) ? $r->terpakai / $kuota : 0,
                'terakhir' => substr($r->terakhir, 0, 16),
            ])->all();
    }

    private function model(Carbon $dari, Carbon $sampai): array
    {
        return $this->q($dari, $sampai)->groupBy('model')
            ->selectRaw('model nama, coalesce(sum(total), 0) token, count(*) request, coalesce(sum(prompt), 0) prompt, coalesce(sum(cache_hit), 0) cache_hit, '.self::NILAI.' nilai')
            ->orderByDesc('token')->get()
            ->map(fn ($r) => ['nama' => $r->nama, 'token' => (int) $r->token, 'nilai' => (int) round($r->nilai), 'request' => (int) $r->request, 'cache_rate' => $r->prompt ? $r->cache_hit / $r->prompt : 0])->all();
    }

    private const STATUS = [402 => 'Saldo habis', 429 => 'Terlalu banyak / server ramai', 502 => 'Upstream gangguan'];

    private function gagal(Carbon $dari, Carbon $sampai): array
    {
        return $this->q($dari, $sampai)->where('status', '!=', 200)->groupBy('status')->selectRaw('status, count(*) n')->orderByDesc('n')->get()
            ->map(fn ($r) => ['nama' => $r->status.' · '.(self::STATUS[$r->status] ?? 'Ditolak model'), 'request' => (int) $r->n])->all();
    }
}
