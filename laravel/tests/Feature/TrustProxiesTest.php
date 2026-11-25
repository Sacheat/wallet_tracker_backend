<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Only the local reverse proxy (loopback) is trusted: its X-Forwarded-For
 * gives the real client, anyone else's is ignored. Addresses come from the
 * documentation ranges (RFC 5737).
 */
class TrustProxiesTest extends TestCase
{
    private const CLIENT = '203.0.113.10';

    private const OUTSIDER = '198.51.100.20';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/ip', fn (Request $request) => $request->ip());
        Route::get('/_test/host', fn (Request $request) => $request->getHost());
    }

    public function test_the_local_reverse_proxy_forwards_the_real_client_ip(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('/_test/ip', ['X-Forwarded-For' => self::CLIENT])
            ->assertOk()->assertSeeText(self::CLIENT);
    }

    public function test_forwarded_for_from_anyone_else_is_ignored(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::OUTSIDER])
            ->get('/_test/ip', ['X-Forwarded-For' => self::CLIENT])
            ->assertOk()->assertSeeText(self::OUTSIDER);
    }

    public function test_forwarded_host_is_not_trusted_even_from_the_proxy(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('/_test/host', ['X-Forwarded-Host' => 'evil.example'])
            ->assertOk()->assertDontSeeText('evil.example');
    }
}
