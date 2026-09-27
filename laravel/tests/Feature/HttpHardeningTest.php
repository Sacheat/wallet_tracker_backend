<?php

namespace Tests\Feature;

use Tests\TestCase;

class HttpHardeningTest extends TestCase
{
    public function test_unauthenticated_web_route_returns_json_401_without_accept_header(): void
    {
        $response = $this->get('/user');

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_responses_forbid_browser_storage(): void
    {
        $response = $this->get('/user');

        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
    }
}
