<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/** Masuk/daftar dengan Google. Akun lama ditautkan lewat email hanya jika Google menyatakan email terverifikasi. */
class GoogleController extends Controller
{
    public static function aktif(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    public function redirect(): SymfonyRedirect
    {
        abort_unless(self::aktif(), 404);

        return Socialite::driver('google')->redirect();
    }

    public function callback(): RedirectResponse
    {
        abort_unless(self::aktif(), 404);

        try {
            $g = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            report($e);

            return $this->gagal('Login Google dibatalkan atau kedaluwarsa. Silakan coba lagi.');
        }

        $email = Str::lower((string) $g->getEmail());
        if ($email === '' || ! ($g->user['email_verified'] ?? false)) {
            return $this->gagal('Email akun Google Anda belum terverifikasi.');
        }

        $user = User::where('google_id', $g->getId())->first() ?? User::where('email', $email)->first();
        if ($user && $user->google_id && $user->google_id !== $g->getId()) {
            return $this->gagal('Email ini sudah tertaut ke akun Google lain.');
        }

        $user ??= User::create(['name' => $g->getName() ?: Str::before($email, '@'), 'email' => $email, 'password' => Str::password(32)]);
        $user->forceFill([
            'google_id' => $g->getId(),
            'email_verified_at' => $user->email_verified_at ?? now(), // Google sudah memverifikasi email
        ])->save();

        // 2FA aktif → lewati Google tidak boleh melewati tantangan kode (alur sesi Fortify).
        if ($user->two_factor_confirmed_at) {
            session()->put(['login.id' => $user->getKey(), 'login.remember' => true]);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, remember: true);
        request()->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    private function gagal(string $pesan): RedirectResponse
    {
        return redirect()->route('login')->with('galat_google', $pesan);
    }
}
