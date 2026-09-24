<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
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
        // Límite general para toda la API (aplicado vía throttleApi() en
        // bootstrap/app.php). Sin esto, solo login/registro tenían límite y
        // el resto de la API (crear recibos, subir fotos, etc.) no tenía
        // ninguno.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // El resto de la API siempre ha devuelto los modelos "pelados"
        // (sin envoltorio {"data": ...}). Los API Resources de Laravel
        // envuelven así por defecto; esto lo desactiva para que los
        // Resources nuevos (Tanda, TandaMember) respondan igual que el
        // resto de endpoints existentes.
        JsonResource::withoutWrapping();
    }
}
