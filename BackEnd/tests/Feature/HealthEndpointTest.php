<?php

namespace Tests\Feature;

use Tests\TestCase;

final class HealthEndpointTest extends TestCase
{
    public function test_api_health_endpoint_is_available(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'service' => 'qt-foods-erp-crm',
                'architecture' => 'modular-monolith',
            ]);
    }

    public function test_protected_endpoints_require_authentication(): void
    {
        $response = $this->getJson('/api/v1/contexts')->assertUnauthorized()
            ->assertJson([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Unauthenticated.',
                ],
            ]);
        self::assertTrue(\Illuminate\Support\Str::isUuid((string) $response->json('error.request_id')));
        $response->assertHeader('X-Request-ID', $response->json('error.request_id'));

        $this->get('/api/v1/contexts')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }
}
