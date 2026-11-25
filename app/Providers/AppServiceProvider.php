<?php

namespace App\Providers;

use App\Listeners\CatatEmail;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Di belakang Cloudflare Tunnel origin selalu http; semua URL buatan Laravel wajib https.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        Gate::define('admin', fn (User $user) => $user->is_admin);

        Event::listen(MessageSending::class, [CatatEmail::class, 'sending']);
        Event::listen(MessageSent::class, [CatatEmail::class, 'sent']);

        // API VDC Pay: semua permintaan datang dari 127.0.0.1 (cloudflared), jadi dibatasi per klien.
        RateLimiter::for('checkout', fn (Request $r) => Limit::perMinute(120)->by('checkout:'.($r->attributes->get('klien')?->id ?? $r->ip())));
        RateLimiter::for('checkout-halaman', fn (Request $r) => Limit::perMinute(30)
            ->by('bayar:'.$r->route()?->originalParameter('sesi').'|'.($r->header('CF-Connecting-IP') ?? $r->ip())));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
