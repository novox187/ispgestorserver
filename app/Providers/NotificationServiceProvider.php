<?php

namespace App\Providers;

use App\Notifications\Core\ChannelRegistry;
use App\Notifications\Core\Deduplicator;
use App\Notifications\Core\NotificationConfigRepository;
use App\Notifications\Core\NotificationDispatcher;
use App\Notifications\Core\NotificationRouter;
use Illuminate\Support\ServiceProvider;

/**
 * Registra los componentes del módulo de notificaciones como singletons.
 *
 * Se carga desde bootstrap/providers.php. El módulo no expone rutas ni vistas;
 * solo bindings de contenedor y la fachada Notify (resuelta vía facade aliasing).
 */
class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChannelRegistry::class, function ($app) {
            return new ChannelRegistry($app);
        });

        $this->app->singleton(Deduplicator::class, function () {
            return new Deduplicator();
        });

        $this->app->singleton(NotificationConfigRepository::class, function () {
            return new NotificationConfigRepository();
        });

        $this->app->singleton(NotificationRouter::class, function ($app) {
            return new NotificationRouter(
                $app->make(NotificationConfigRepository::class),
            );
        });

        $this->app->singleton(NotificationDispatcher::class, function ($app) {
            return new NotificationDispatcher(
                $app->make(NotificationRouter::class),
                $app->make(Deduplicator::class),
            );
        });
    }

    public function boot(): void
    {
        // El config es publicado en config/notifications.php y se versiona en git;
        // no es necesario publishConfig aquí.
    }
}
