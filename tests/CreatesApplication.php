<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        // Forzamos la desactivación de MikroTik para que los tests jamás
        // intenten conectarse al router real (lo cual implica timeouts
        // de varios segundos por petición y depende de infraestructura).
        // Cualquier servicio que necesite el cliente RouterOS recibirá `null`
        // y los flujos críticos deben mockear el comportamiento por su cuenta.
        $app['config']->set('mikrotik.enabled', false);

        return $app;
    }
}

