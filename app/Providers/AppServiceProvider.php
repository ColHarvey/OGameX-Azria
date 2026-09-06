<?php

namespace OGame\Providers;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use OGame\Exceptions\Handler;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\BattleEngine\Draws\BattleDraws;
use OGame\GameMissions\BattleEngine\Draws\SystemDraws;
use OGame\Models\Message;
use OGame\Models\User;
use OGame\Observers\MessageObserver;
use OGame\Observers\UserObserver;
use OGame\Services\SettingsService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    final public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Legacy JS bundles are plain concatenated scripts that rely on jQuery globals
        // and must not be treated as ES modules.
        Vite::useScriptTagAttributes(['type' => false]);

        // Register composer file for the main ingame layout.
        view()->composer('ingame.layouts.main', 'OGame\Http\ViewComposers\IngameMainComposer');

        // Register model observers
        User::observe(UserObserver::class);
        Message::observe(MessageObserver::class);
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    final public function register(): void
    {
        $this->app->singleton(function ($app): SettingsService {
            return new SettingsService();
        });

        $this->app->singleton(function ($app): PlayerServiceFactory {
            return new PlayerServiceFactory();
        });

        $this->app->singleton(function ($app): PlanetServiceFactory {
            return new PlanetServiceFactory(
                $app->make(SettingsService::class),
                $app->make(PlayerServiceFactory::class)
            );
        });

        // **La source des tirages d une bataille se resout, elle ne se construit plus sur place.**
        // En jeu, c est le hasard du systeme, une instance neuve par bataille — le comportement
        // n a pas change. Ce qui change, c est qu un banc peut desormais lui substituer une graine
        // sans atteindre le moteur, qui nait au fond du chemin de fermeture et hors de sa portee.
        $this->app->bind(BattleDraws::class, SystemDraws::class);

        $this->app->singleton(ExceptionHandler::class, Handler::class);
    }
}
