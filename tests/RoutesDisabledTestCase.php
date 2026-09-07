<?php

declare(strict_types=1);

namespace Tests;

class RoutesDisabledTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('eix-pricing.routes.enabled', false);
    }
}
