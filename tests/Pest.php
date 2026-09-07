<?php

declare(strict_types=1);

use Tests\RoutesDisabledTestCase;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');
pest()->extend(RoutesDisabledTestCase::class)->in('DisabledRoutes');
