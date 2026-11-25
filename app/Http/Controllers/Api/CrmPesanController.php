<?php

namespace App\Http\Controllers\Api;

use App\Actions\Billing\TandaiLunas;
use App\Actions\Order\BuatPesanan;
use App\Enums\PlanKategori;
use App\Http\Controllers\Controller;
use App\Http\Controllers\KatalogController;
use App\Models\Invoice;
use App\Models\Pengaturan;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Tab "Cloud" customer CRM: katalog paket + buat layanan yang SUDAH DIBAYAR ke Velocity (cara bayar "tandai lunas" dari CRM).
 * Akun dicari lewat email; belum ada = dibuat (pelanggan nanti login Google dengan email itu). Tagihan dicatat lunas metode "crm",
 * lalu provisioning jalan seperti pembayaran biasa. Perpanjangan tetap ditagih velocity-cloud.
 */
class CrmPesanController extends Controller
{
    public function katalog(): JsonResponse
    {
        return response()->json([
            'paket' => Plan::aktif()->orderBy('kategori')->orderBy('urutan')->get()
                ->reject(fn (Plan $p) => $p->kategori === PlanKategori::Domain) // domain dipesan di CRM sendiri
                ->map(fn (Plan $p) => [
                    'id' => $p->id,
                    'kategori' => $p->kategori->value,
                    'kategori_label' => $p->kategori->label(),
                    'nama' => $p->nama,
                    'harga' => $p->hargaBeli(), // token AI: per juta token (promo bila berlaku)
                    'bisa_dipesan' => $p->bisaDipesan(),
                    'habis' => $p->sisaStok() === 0,
                    'butuh_os' => $p->vpsOtomatis(),
                    'butuh_domain' => $p->kategori === PlanKategori::Hosting,
                    'butuh_token' => $p->kategori === PlanKategori::Ai,
                ])->values(),
            'pilihan_os' => collect(Pengaturan::osVps())->map(fn ($o) => $o['nama']),
            'token_maks_juta' => (int) config('cloud.token_ai.beli_maks_juta'),
        ]);
    }

    public function store(Request $request, BuatPesanan $buat, TandaiLunas $tandai): JsonResponse
    {
        $v = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'nama' => ['required', 'string', 'max:255'],
            'plan_id' => ['required', 'integer'],
            'ref' => ['required', 'alpha_num', 'size:12'], // dibuat form CRM sekali per isian: klik dua kali = satu pesanan
            'petugas' => ['required', 'string', 'max:60'],
            'catatan_bayar' => ['required', 'string', 'max:60'],
        ]);
        $plan = Plan::aktif()->whereKey($v['plan_id'])->where('kategori', '!=', PlanKategori::Domain)->first();
        if (! $plan || ! $plan->bisaDipesan()) {
            return response()->json(['message' => $plan?->sisaStok() === 0 ? 'Stok paket ini habis.' : 'Paket ini belum bisa dipesan.'], 422);
        }
        $awalan = "CRM {$v['ref']} ";

        return Cache::lock('crm-pesan:'.$v['ref'], 30)->block(10, function () use ($request, $v, $plan, $buat, $tandai, $awalan) {
            if ($lama = Invoice::where('referensi', 'like', $awalan.'%')->first()) {
                return response()->json($this->hasil($lama, false));
            }
            $email = Str::lower($v['email']);
            $user = User::where('email', $email)->first();
            $baru = ! $user;

            try {
                // akun baru ikut batal bila isian paket tidak valid; tandai lunas di luar transaksi (provisioning di-dispatch sesudah commit)
                $invoice = DB::transaction(fn () => KatalogController::pesan($request, $plan, $buat,
                    $user ?? User::create(['name' => $v['nama'], 'email' => $email, 'password' => Str::password(32)])));
            } catch (InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            $user = $invoice->user;
            $tandai->handle($invoice, 'crm', mb_substr($awalan."{$v['petugas']}: {$v['catatan_bayar']}", 0, 100));
            Log::info('Layanan dibuat dari CRM', ['invoice' => $invoice->nomor, 'user' => $user->id, 'akun_baru' => $baru, 'petugas' => $v['petugas']]);

            return response()->json($this->hasil($invoice->fresh(), $baru), 201);
        });
    }

    private function hasil(Invoice $invoice, bool $akunBaru): array
    {
        $s = $invoice->service;

        return [
            'tagihan' => $invoice->nomor,
            'jumlah' => (int) $invoice->jumlah,
            'layanan' => $s->plan->nama,
            'status' => $s->status->label(),
            'akun_baru' => $akunBaru,
            'url_admin' => route('admin.pengguna.show', $invoice->user_id),
        ];
    }
}
