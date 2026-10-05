<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The API host has no home page: a browser at / is sent to signup
     * (see routes/web.php and WebRegisterTest for the signed-in case).
     */
    public function test_the_root_sends_browsers_to_signup(): void
    {
        $this->get('/')->assertRedirect(route('register'));
    }
}
