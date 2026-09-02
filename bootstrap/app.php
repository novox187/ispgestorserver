<?php

use App\Http\Middleware\AuthenticateProvisioningAgent;
use App\Http\Middleware\EnsureEmployeeSuperAdmin;
use App\Http\Middleware\EnsurePrimaryRouter;
use App\Http\Middleware\CheckPermission;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        /*
         * La aplicación solo es alcanzable a través del proxy de Coolify, que a
         * su vez está detrás de Cloudflare. Sin declararlo, Laravel ignora las
         * cabeceras `X-Forwarded-*` y eso rompía dos cosas de forma silenciosa:
         *
         *  - Veía cada petición como `http`, así que `hasValidSignature()`
         *    reconstruía una URL distinta de la firmada (que sale de APP_URL,
         *    en `https`) y toda URL firmada se rechazaba con un 403.
         *  - Tomaba como IP de origen la del proxy, de modo que los límites de
         *    `throttle` —pensados por cliente— los compartía todo el mundo en un
         *    único cubo.
         *
         * Se confía en cualquier proxy porque el contenedor no está expuesto
         * directamente: solo recibe tráfico que ya ha pasado por Traefik. No se
         * incluye `X-Forwarded-Host` a propósito: el host real llega igualmente
         * en la cabecera `Host` y así no hay forma de inyectar otro.
         */
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        /*
         * Por defecto Laravel intenta redirigir a `route('login')` cuando un
         * invitado toca una ruta protegida. Esta app es solo API —no existe tal
         * ruta— así que ese intento de construir la URL lanzaba su propia
         * `RouteNotFoundException` ANTES de que se llegara a construir la
         * `AuthenticationException`, tapando el 401 real con un 500 genérico.
         * `redirectGuestsTo(null)` desactiva el intento de redirección: el
         * middleware `Authenticate` pasa a lanzar la excepción sin URL de
         * destino, que el handler de abajo convierte en un 401 limpio.
         */
        $middleware->redirectGuestsTo(fn () => null);

        // CORS debe correr primero para responder los preflight OPTIONS
        // antes de que cualquier otro middleware (auth, throttle) los rechace
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);

        $middleware->alias([
            'super_admin'      => EnsureEmployeeSuperAdmin::class,
            'permission'       => CheckPermission::class,
            'primary_router'   => EnsurePrimaryRouter::class,
            // Canal máquina-a-máquina de los agentes de aprovisionamiento.
            // No usa Sanctum: sus tokens están atados a un usuario y no caducan.
            'agent.hmac'       => AuthenticateProvisioningAgent::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        /*
         * Esta aplicación es solo API: no existe ninguna ruta nombrada `login`
         * (routes/web.php solo sirve la vista de bienvenida por defecto de
         * Laravel). Sin este handler, cualquier petición sin token válido a una
         * ruta protegida hace que el comportamiento por defecto de Laravel
         * intente redirigir a `route('login')`, que no existe, y esa segunda
         * excepción (`RouteNotFoundException`) tapa por completo el 401 real
         * que debía responderse — el cliente recibe un 500 genérico en vez de
         * un 401 con el que pueda decidir mostrar el login.
         */
        $exceptions->render(function (
            \Illuminate\Auth\AuthenticationException $e,
            \Illuminate\Http\Request $request
        ) {
            return response()->json(['message' => 'No autenticado.'], 401);
        });

        // Garantiza que TODAS las respuestas de error incluyan cabeceras CORS,
        // incluso cuando una excepción escapa al middleware HandleCors (errores fatales,
        // timeouts de PHP, fallos en el handler de excepciones, etc.).
        $exceptions->respond(function (
            \Symfony\Component\HttpFoundation\Response $response,
            \Throwable $e,
            \Illuminate\Http\Request $request
        ) {
            $origin = $request->header('Origin');
            if (!$origin) {
                return $response;
            }
            $allowed = config('cors.allowed_origins', ['*']);
            if (in_array('*', $allowed) || in_array($origin, $allowed)) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                if (config('cors.supports_credentials', false)) {
                    $response->headers->set('Access-Control-Allow-Credentials', 'true');
                }
            }
            return $response;
        });
    })
    ->withSchedule(function (Schedule $schedule) {
        $tz    = config('billing.timezone');
        $sched = config('billing.schedule');

        // Las automatizaciones con AutomationSetting (suspensión, facturación
        // mensual, cobros automáticos, sync MikroTik) NO se registran aquí: las
        // gestiona el scheduler dinámico de routes/console.php respetando el
        // flag `enabled`. Registrarlas también aquí duplicaba la ejecución y
        // hacía que la automatización siguiera corriendo aunque estuviese
        // desactivada desde la UI.

        // Reactivación automática de clientes suspendidos con saldo suficiente
        // (sin contraparte en automation_settings).
        $schedule->command('billing:reactivate')
                 ->dailyAt($sched['auto_reactivate_time'])
                 ->timezone($tz)
                 ->withoutOverlapping();
    })
    ->create();
