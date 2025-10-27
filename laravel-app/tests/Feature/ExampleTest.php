<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDeviceCookie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        self::markTestSkipped('Placeholder example test.');
    }
}
