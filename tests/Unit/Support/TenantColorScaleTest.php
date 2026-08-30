<?php

namespace Tests\Unit\Support;

use App\Support\TenantColorScale;
use PHPUnit\Framework\TestCase;

class TenantColorScaleTest extends TestCase
{
    public function test_generates_expected_hsl_scale_for_known_hex(): void
    {
        $this->assertSame(
            ':root{--tenant-primary:#FA6400;'
            .'--tenant-50:hsl(24 100% 94%);'
            .'--tenant-100:hsl(24 100% 84%);'
            .'--tenant-200:hsl(24 100% 74%);'
            .'--tenant-300:hsl(24 100% 64%);'
            .'--tenant-400:hsl(24 100% 56%);'
            .'--tenant-500:hsl(24 100% 49%);'
            .'--tenant-600:hsl(24 100% 41%);'
            .'--tenant-700:hsl(24 100% 33%);'
            .'--tenant-800:hsl(24 100% 25%);'
            .'--tenant-900:hsl(24 100% 17%);}',
            TenantColorScale::cssVariables('#FA6400'),
        );
    }

    public function test_preserves_tenant_primary_exactly_as_input_hex(): void
    {
        $css = TenantColorScale::cssVariables('#22c55e');

        $this->assertStringStartsWith(':root{--tenant-primary:#22c55e;', $css);
    }

    public function test_falls_back_to_default_orange_when_hex_is_null(): void
    {
        $css = TenantColorScale::cssVariables(null);

        $this->assertStringStartsWith(':root{--tenant-primary:#FA6400;', $css);
    }

    public function test_falls_back_to_default_when_hex_is_malformed(): void
    {
        $css = TenantColorScale::cssVariables('#fff;} body{display:none');

        $this->assertStringStartsWith(':root{--tenant-primary:#FA6400;', $css);
        $this->assertStringNotContainsString('display:none', $css);
    }
}
