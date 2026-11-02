<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Inventario\Domain\Repositories\KitInventarioRepositoryInterface;
use App\Inventario\Domain\Repositories\SuministroQuimicoRepositoryInterface;
use App\Inventario\Infrastructure\Persistence\EloquentKitInventarioRepository;
use App\Inventario\Infrastructure\Persistence\EloquentSuministroQuimicoRepository;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            KitInventarioRepositoryInterface::class,
            EloquentKitInventarioRepository::class
        );

        $this->app->bind(
            SuministroQuimicoRepositoryInterface::class,
            EloquentSuministroQuimicoRepository::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
