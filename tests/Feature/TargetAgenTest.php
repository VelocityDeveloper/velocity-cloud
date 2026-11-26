<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PlanKategori;
use App\Enums\ServiceStatus;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Service;
use App\Models\User;
use App\Services\Agen\TargetAgen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TargetAgenTest extends TestCase
{
    use RefreshDatabase;

    public function test_omzet_target_hanya_member_langsung_dan_google_organic(): void
    {
        $plan = Plan::create(['kategori' => PlanKategori::Vps, 'slug' => 'vps-uji', 'nama' => 'Paket', 'harga' => 1000]);
        $bayar = function (?string $vid, ?string $sumber, ?string $medium, int $jumlah) use ($plan): void {
            $u = User::factory()->create(['is_admin' => false, 'analitik_vid' => $vid]);
            if ($vid && $sumber) {
                // kunjungan pertama menentukan sumber; kunjungan google sesudahnya tidak mengubah
                foreach ([[$sumber, $medium, 2], ['google', 'organic', 1]] as [$s, $m, $jam]) {
                    DB::table('analitik_hits')->insert(['waktu' => now()->subHours($jam)->toDateTimeString(), 'jenis' => 'pv', 'vid' => $vid,
                        'sid' => 's'.$vid.$jam, 'pid' => 'p'.$vid.$jam, 'path' => '/', 'sumber' => $s, 'medium' => $m, 'internal' => false]);
                }
            }
            $s = Service::create(['user_id' => $u->id, 'plan_id' => $plan->id, 'label' => 'x', 'status' => ServiceStatus::Aktif, 'harga' => $jumlah]);
            Invoice::create(['user_id' => $u->id, 'service_id' => $s->id, 'nomor' => Invoice::nomorBaru(), 'jumlah' => $jumlah, 'status' => InvoiceStatus::Lunas,
                'dibayar_pada' => now()->subMinutes(30), 'periode_mulai' => today(), 'periode_selesai' => today()->addMonth(), 'jatuh_tempo' => today()]);
        };

        $bayar('vidlangsung1', '(direct)', '(none)', 100);
        $bayar('vidgoogle001', 'google', 'organic', 20);
        $bayar('vidbing00001', 'bing', 'organic', 3000);   // bukan google
        $bayar('vidcpc000001', 'google', 'cpc', 4000);     // bukan organic
        $bayar(null, null, null, 50000);                    // sumber "-" = akun internal tim

        $c = TargetAgen::corong(now()->subDay()->toImmutable(), now()->addMinute()->toImmutable());
        $this->assertSame([120, 5], [$c['omzet_lunas'], $c['lunas']]);
    }
}
