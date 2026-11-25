<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\PlanKategori;
use App\Enums\ServiceStatus;
use App\Jobs\KirimDomainCrm;
use App\Jobs\PodAction;
use App\Jobs\ProvisionHosting;
use App\Jobs\ProvisionPod;
use App\Jobs\ProvisionVps;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Promo;
use App\Models\PromoKlaim;
use App\Models\SaldoMutasi;
use App\Models\User;
use App\Models\Service;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class TandaiLunas
{
    /**
     * Lunasi tagihan lalu perbarui layanan:
     * - tagihan pertama: layanan disiapkan (app → pod, hosting → akun DirectAdmin, VPS otomatis → VM; managed → disiapkan admin manual)
     * - perpanjangan: jatuh tempo maju, layanan yang ditangguhkan diaktifkan lagi.
     */
    public function handle(Invoice $invoice, string $metode, ?string $referensi = null): void
    {
        if ($invoice->status !== InvoiceStatus::BelumDibayar) {
            return;
        }

        $provision = DB::transaction(function () use ($invoice, $metode, $referensi) {
            $invoice->update([
                'status' => InvoiceStatus::Lunas,
                'dibayar_pada' => now(),
                'metode' => $metode,
                'referensi' => $referensi,
            ]);

            $this->kodeUnikKeSaldo($invoice);

            $service = $invoice->service()->lockForUpdate()->first();

            if ($invoice->token_juta) {
                $this->tambahToken($service, $invoice->token_juta);
                $this->bonusPromo($invoice, $service);

                return null;
            }

            // domain: sekali bayar, dikirim ke CRM (order + registrar); perpanjangan ditagih CRM, tanpa jatuh tempo & promo di sini
            if ($service->isDomain()) {
                if ($service->status === ServiceStatus::MenungguPembayaran) {
                    $service->update(['status' => ServiceStatus::Disiapkan]);

                    return KirimDomainCrm::class;
                }

                return null;
            }

            if ($service->status === ServiceStatus::MenungguPembayaran) { // promo hanya pembelian baru, bukan perpanjangan
                $this->bonusPromo($invoice, $service);
            }

            $service->jatuh_tempo = $invoice->periode_selesai;

            if ($service->status === ServiceStatus::MenungguPembayaran) {
                $service->status = ServiceStatus::Disiapkan;
                $service->save();

                return match (true) {
                    $service->isApp() => ProvisionPod::class,
                    $service->plan->kategori === PlanKategori::Hosting => ProvisionHosting::class,
                    $service->plan->vpsOtomatis() => ProvisionVps::class,
                    default => null,
                };
            }

            if ($service->status === ServiceStatus::Ditangguhkan) {
                $service->status = ServiceStatus::Aktif;
                if ($service->ikutDitangguhkan()) {
                    DB::afterCommit(fn () => PodAction::dispatch($service, 'start')); // pod/VM/akun DA dihentikan saat ditangguhkan
                }
            }
            $service->save();

            return null;
        });

        if ($provision) {
            $provision::dispatch($invoice->service);
        }
    }

    /** Kode unik yang ikut dibayar menjadi saldo pelanggan (tidak bisa ditarik). */
    private function kodeUnikKeSaldo(Invoice $invoice): void
    {
        if ($invoice->kode_unik < 1) {
            return;
        }
        $user = User::whereKey($invoice->user_id)->lockForUpdate()->first();
        $user->increment('saldo', $invoice->kode_unik);
        SaldoMutasi::create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'jumlah' => $invoice->kode_unik,
            'saldo_akhir' => $user->saldo,
            'keterangan' => 'Kode unik '.$invoice->nomor,
        ]);
    }

    /**
     * Promo berlaku yang cocok (kategori paket + minimal tagihan tanpa kode unik) → bonus saldo token AI.
     * Hanya pembelian baru: tagihan pertama layanan atau beli/top-up token; perpanjangan tidak dapat bonus.
     * Tujuan: paket promo (plan_id) bila diisi; selain itu layanan token tagihan ini, atau token AI milik pelanggan; belum punya → dibuatkan otomatis.
     */
    private function bonusPromo(Invoice $invoice, Service $service): void
    {
        $promo = Promo::berlaku()->get()->filter(fn (Promo $p) => $p->cocok($service->plan->kategori, $invoice->jumlah));
        if ($promo->isEmpty()) {
            return;
        }

        foreach ($promo as $p) {
            // promo ber-plan_id selalu masuk ke paket itu (mis. DeepSeek V4.1 Flash), apa pun yang dibeli
            $tujuan = match (true) {
                $p->plan_id !== null => $service->plan_id === $p->plan_id ? $service : $this->layananToken($invoice->user_id, $p->plan_id),
                $service->plan->kategori === PlanKategori::Ai => $service,
                default => $this->layananToken($invoice->user_id),
            };
            if (! $tujuan) {
                continue; // paket token AI belum ada di katalog
            }
            $this->tambahToken($tujuan, $p->bonus_token_juta);
            PromoKlaim::create([
                'promo_id' => $p->id,
                'user_id' => $invoice->user_id,
                'invoice_id' => $invoice->id,
                'token_service_id' => $tujuan->id,
                'token_juta' => $p->bonus_token_juta,
                'nominal' => $invoice->jumlah,
            ]);
        }
    }

    private function layananToken(int $userId, ?int $planId = null): ?Service
    {
        $ada = Service::where('user_id', $userId)
            ->when($planId, fn ($q) => $q->where('plan_id', $planId))
            ->whereHas('plan', fn ($q) => $q->where('kategori', PlanKategori::Ai))
            ->whereIn('status', [ServiceStatus::Aktif, ServiceStatus::Ditangguhkan])
            ->orderByRaw('status = ?', [ServiceStatus::Ditangguhkan->value])->oldest('id')->lockForUpdate()->first();
        if ($ada) {
            return $ada;
        }
        $plan = Plan::where('kategori', PlanKategori::Ai)->when($planId, fn ($q) => $q->whereKey($planId))->orderBy('urutan')->first();

        return $plan ? Service::create([
            'user_id' => $userId,
            'plan_id' => $plan->id,
            'label' => 'Token AI (bonus promo)',
            'status' => ServiceStatus::MenungguPembayaran, // tambahToken mengaktifkan + membuat API key
            'harga' => $plan->harga,
        ]) : null;
    }

    /** Beli pertama: buat API key + aktifkan. Top-up: saldo bertambah (layanan ditangguhkan admin tidak diaktifkan otomatis). */
    private function tambahToken(Service $service, int $juta): void
    {
        $token = $service->tokenAi;
        if (! $token) {
            $kunci = 'vc-'.Str::random(48);
            $token = $service->tokenAi()->create(['kunci_hash' => hash('sha256', $kunci), 'saldo' => 0]);
            $service->setRelation('tokenAi', $token); // bonus promo di transaksi yang sama memakai token ini, bukan membuat kunci kedua
            $service->detail = [...($service->detail ?? []), 'kredensial' => Crypt::encryptString(json_encode(['api_key' => $kunci]))];
        }
        $token->increment('saldo', $juta * 1_000_000);

        if ($service->status === ServiceStatus::MenungguPembayaran) {
            $service->status = ServiceStatus::Aktif;
        }
        $service->save();
    }
}
