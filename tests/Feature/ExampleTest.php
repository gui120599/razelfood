<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A landing page do domínio central (`razelfood.com.br`) ainda não foi
     * implementada — `LandingController` responde 501 de propósito. Quando
     * a página existir, este teste deve passar a esperar 200.
     */
    public function test_the_central_landing_page_is_not_implemented_yet(): void
    {
        $baseDomain = config('tenancy.base_domain');

        $this->get("http://{$baseDomain}/")->assertStatus(501);
    }
}
