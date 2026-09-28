<?php

namespace Tests\Feature;

use App\Http\Middleware\TrustVercelProxy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Header values mirror what the Vercel rewrite was observed to send on
 * 2026-09-28: REMOTE_ADDR an AWS address, X-Forwarded-For the real client
 * (Vercel overwrites any client-supplied value).
 */
class TrustVercelProxyTest extends TestCase
{
    private const VERCEL_EDGE = '13.36.169.27';

    private const CLIENT = '178.51.206.94';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/ip', fn (Request $request) => $request->ip());
    }

    private function hit(array $headers = [])
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::VERCEL_EDGE])
            ->get('/_test/ip', ['X-Forwarded-For' => self::CLIENT] + $headers);
    }

    public function test_without_a_configured_secret_forwarded_for_is_ignored(): void
    {
        config(['app.proxy_secret' => null]);

        $this->hit()->assertOk()->assertSeeText(self::VERCEL_EDGE);
    }

    public function test_request_carrying_the_secret_gets_the_real_client_ip(): void
    {
        config(['app.proxy_secret' => 's3cret']);

        $this->hit([TrustVercelProxy::HEADER => 's3cret'])->assertOk()->assertSeeText(self::CLIENT);
    }

    public function test_request_without_the_secret_is_rejected(): void
    {
        config(['app.proxy_secret' => 's3cret']);

        $this->hit()->assertForbidden();
        $this->hit([TrustVercelProxy::HEADER => 'wrong'])->assertForbidden();
    }

    public function test_forwarded_host_is_not_trusted(): void
    {
        config(['app.proxy_secret' => 's3cret']);

        Route::get('/_test/host', fn (Request $request) => $request->getHost());

        $this->withServerVariables(['REMOTE_ADDR' => self::VERCEL_EDGE])
            ->get('/_test/host', [TrustVercelProxy::HEADER => 's3cret', 'X-Forwarded-Host' => 'evil.example'])
            ->assertOk()
            ->assertDontSeeText('evil.example');
    }
}
