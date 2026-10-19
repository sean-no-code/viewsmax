<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /** The API host root sends people to the app. */
    public function test_the_root_redirects_to_the_frontend(): void
    {
        config(['app.frontend_url' => 'https://app.example.com/']);

        $this->get('/')->assertRedirect('https://app.example.com');
    }
}
