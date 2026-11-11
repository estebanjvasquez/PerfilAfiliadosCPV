<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * TASK-0018: TrustHosts y TrustProxies guardan su lista en propiedades estáticas de Symfony Request, que sobreviven
     * de una prueba a la siguiente en el mismo proceso. En producción cada petición empieza de cero; aquí se limpian.
     */
    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        parent::tearDown();
    }
}
