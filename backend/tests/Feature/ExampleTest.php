<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_stockroom_pages_serve_the_react_shell(): void
    {
        $this->withoutVite();

        foreach (['/', '/workspace'] as $path) {
            $this->get($path)->assertOk()->assertSee('id="stockroom"', false);
        }
    }
}
