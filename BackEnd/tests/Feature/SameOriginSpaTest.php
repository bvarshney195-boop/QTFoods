<?php

namespace Tests\Feature;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

final class SameOriginSpaTest extends TestCase
{
    private string $spaPath;
    private string $originalPublicPath;
    private string $testPublicPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalPublicPath = public_path();
        $this->testPublicPath = storage_path('framework/testing/spa-public-'.bin2hex(random_bytes(8)));
        if (! mkdir($this->testPublicPath, 0700, true) && ! is_dir($this->testPublicPath)) {
            self::fail("Unable to create the isolated SPA fixture directory {$this->testPublicPath}.");
        }

        $this->app->usePublicPath($this->testPublicPath);
        $this->spaPath = public_path('index.html');
        file_put_contents($this->spaPath, '<!doctype html><title>Q &amp; T Foods</title><div id="root"></div>');
    }

    protected function tearDown(): void
    {
        @unlink($this->spaPath);
        $this->app->usePublicPath($this->originalPublicPath);
        @rmdir($this->testPublicPath);

        parent::tearDown();
    }

    public function test_backend_information_remains_available_as_json(): void
    {
        $this->getJson('/backend-info')
            ->assertOk()
            ->assertExactJson(['service' => 'Q & T FOODS ERP + CRM Backend']);
    }

    public function test_frontend_routes_return_the_spa_entrypoint(): void
    {
        foreach (['/', '/login', '/dashboard'] as $path) {
            $response = $this->get($path)
                ->assertOk()
                ->assertHeader('Content-Type', 'text/html; charset=UTF-8');

            self::assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
            self::assertSame($this->spaPath, $response->baseResponse->getFile()->getPathname());
        }
    }

    public function test_frontend_route_returns_a_controlled_not_found_response_when_the_spa_is_not_packaged(): void
    {
        unlink($this->spaPath);

        $this->getJson('/')
            ->assertNotFound()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_spa_fallback_does_not_capture_api_routes(): void
    {
        $this->getJson('/api/health')->assertOk()->assertJsonPath('status', 'ok');
        $this->getJson('/api/route-that-does-not-exist')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }
}
