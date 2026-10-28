<?php

namespace NovaPoshta\Filament\Tests;

use Orchestra\Testbench\Attributes\DefineEnvironment;

final class RestConfigurationTest extends TestCase
{
    protected function disableRest($app): void
    {
        $app['config']->set('nova-poshta.rest.enabled', false);
    }

    protected function moveRest($app): void
    {
        $app['config']->set('nova-poshta.rest.prefix', 'delivery/v1');
        $app['config']->set('nova-poshta.rest.middleware', ['api']);
    }

    #[DefineEnvironment('disableRest')]
    public function test_rest_can_be_disabled(): void
    {
        $this->getJson('/api/nova-poshta/v1/areas')->assertNotFound();
    }

    #[DefineEnvironment('moveRest')]
    public function test_rest_prefix_can_be_changed(): void
    {
        $this->getJson('/api/nova-poshta/v1/areas')->assertNotFound();
        $this->getJson('/delivery/v1/areas')->assertStatus(503)->assertJsonPath('error', 'directory_not_ready');
    }
}
