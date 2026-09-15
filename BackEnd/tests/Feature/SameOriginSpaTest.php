<?php

namespace Tests\Feature;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

final class SameOriginSpaTest extends TestCase
{
    private string $spaPath;
    private ?string $originalSpa = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spaPath = public_path('index.html');
        if (is_file($this->spaPath)) {
            $contents = file_get_contents($this->spaPath);
            $this->originalSpa = is_string($contents) ? $contents : '';
        }
        file_put_contents($this->spaPath, '<!doctype html><title>Q &amp; T Foods</title><div id="root"></div>');
    }

    protected function tearDown(): void
    {
        if ($this->originalSpa === null) {
            @unlink($this->spaPath);
        } else {
            file_put_contents($this->spaPath, $this->originalSpa);
        }

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

    public function test_spa_fallback_does_not_capture_api_routes(): void
    {
        $this->getJson('/api/health')->assertOk()->assertJsonPath('status', 'ok');
        $this->getJson('/api/route-that-does-not-exist')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }
}
