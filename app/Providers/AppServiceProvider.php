<?php

namespace App\Providers;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        $this->configureRateLimiting();

        ResetPassword::createUrlUsing(function (User $user, string $token) {
            $base = rtrim(config("app.frontend_url"), "/");

            return $base . "/reset-password?token=" . $token
                . "&email=" . urlencode($user->getEmailForPasswordReset());
        });
    }

    /**
     * Batas laju untuk seluruh API.
     *
     * Laravel 11 ke atas tidak lagi memasang throttle pada grup api secara
     * otomatis — harus dipanggil eksplisit di bootstrap/app.php. Sebelum ini
     * tidak dipanggil sama sekali, jadi setiap endpoint tanpa ->middleware
     * ('throttle:...') sendiri benar-benar tanpa batas: katalog produk yang
     * berisi LIKE '%…%', checkout, pembuatan charge, sampai webhook publik.
     *
     * Dihitung per token untuk request yang login, dan per IP untuk yang
     * tidak. Tanpa pemisahan ini, semua pengguna di belakang satu NAT kantor
     * akan berbagi satu jatah.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by(
                $request->user()?->id ?: $request->ip()
            );
        });
    }
}
