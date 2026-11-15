<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    public function test_only_configured_proxy_can_establish_https_origin(): void
    {
        config(['trustedproxy.proxies' => ['172.30.0.1']]);
        Route::get('/proxy-test', fn () => [
            'secure' => request()->secure(),
            'url' => url('/login'),
        ]);
        $headers = [
            'X-Forwarded-Host' => 'mapr.example.test',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Port' => '443',
            'X-Forwarded-For' => '203.0.113.5',
        ];
        $this->withServerVariables(['REMOTE_ADDR' => '172.30.0.1'])
            ->getJson('http://mapr.internal/proxy-test', $headers)
            ->assertJson(['secure' => true, 'url' => 'https://mapr.example.test/login']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->getJson('http://mapr.internal/proxy-test', $headers)
            ->assertJson(['secure' => false, 'url' => 'http://mapr.internal/login']);
    }
}
