<?php

declare(strict_types=1);

it('does not register quote routes when disabled', function (): void {
    $this->getJson('/api/quotes/IE000EOFR2K5')->assertNotFound();
});
