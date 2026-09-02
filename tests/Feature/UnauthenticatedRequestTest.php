<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Esta app es solo API: no existe ninguna ruta nombrada `login`. Sin el handler
 * de `bootstrap/app.php`, el comportamiento por defecto de Laravel ante una
 * `AuthenticationException` intenta redirigir a `route('login')`, que no
 * existe, y esa segunda excepción tapa el 401 real con un 500 genérico — se
 * verificó en producción con una petición real sin token.
 */
it('responde 401 en JSON a una ruta protegida sin token, no 500', function () {
    $this->getJson('/api/admin/system-bootstrap')
        ->assertStatus(401)
        ->assertJsonStructure(['message']);
});

it('responde 401 aunque la petición no declare Accept: application/json', function () {
    // El bug real ocurría incluso con la cabecera puesta; se comprueba también
    // sin ella, que es el caso más desfavorable.
    $this->get('/api/admin/system-bootstrap')
        ->assertStatus(401);
});
