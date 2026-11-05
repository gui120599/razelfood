<?php

namespace Tests\Feature\Fiscal;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Fiscal\ProductFiscalData;
use App\Support\Fiscal\ResolveProductFiscalData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesFiscalFixtures;
use Tests\Concerns\CreatesSaleFixtures;
use Tests\Concerns\CreatesTenantWithFeatures;
use Tests\TestCase;

/**
 * Dado fiscal efetivo do produto: o que o produto preenche vence; o resto vem
 * da categoria e depois da categoria-pai.
 */
class ProductFiscalDataTest extends TestCase
{
    use CreatesFiscalFixtures;
    use CreatesSaleFixtures;
    use CreatesTenantWithFeatures;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetTenantContext();
        $this->tenant = $this->makeFiscalTenant('lazzopizza');
        $this->actAsTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        $this->forgetTenantContext();

        parent::tearDown();
    }

    private function category(string $name, array $fiscal = [], ?Category $parent = null): Category
    {
        return Category::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'parent_id' => $parent?->id, ...$fiscal]);
    }

    private function resolve(Product $product): ProductFiscalData
    {
        return app(ResolveProductFiscalData::class)($product->fresh());
    }

    public function test_a_product_with_everything_does_not_need_the_category(): void
    {
        $product = $this->makeProduct($this->tenant, 'Calabresa', 40, $this->completeProductFiscalData() + ['cest' => '1234567', 'gtin' => '7891000100103', 'icms_rate' => 18]);

        $data = $this->resolve($product);

        $this->assertTrue($data->isComplete());
        $this->assertSame('19059090', $data->ncm);
        $this->assertSame('1234567', $data->cest);
        $this->assertSame('7891000100103', $data->gtin);
        $this->assertSame('18.00', $data->icmsRate);
    }

    public function test_missing_fields_are_listed_by_label(): void
    {
        $product = $this->makeProduct($this->tenant, 'Calabresa', 40, ['ncm' => '19059090']);

        $this->assertSame(['CFOP', 'CSOSN', 'Origem', 'Unidade'], $this->resolve($product)->missing());
    }

    public function test_the_category_default_fills_what_the_product_left_empty(): void
    {
        $category = $this->category('Pizzas', $this->completeProductFiscalData());
        $product = $this->makeProduct($this->tenant, 'Calabresa', 40, ['category_id' => $category->id]);

        $data = $this->resolve($product);

        $this->assertTrue($data->isComplete());
        $this->assertSame('5102', $data->cfop);
    }

    public function test_the_product_value_beats_the_category_value_field_by_field(): void
    {
        $category = $this->category('Pizzas', $this->completeProductFiscalData());
        $product = $this->makeProduct($this->tenant, 'Refrigerante', 8, ['category_id' => $category->id, 'ncm' => '22021000', 'csosn' => '500']);

        $data = $this->resolve($product);

        $this->assertSame('22021000', $data->ncm);
        $this->assertSame('500', $data->csosn);
        $this->assertSame('5102', $data->cfop, 'O que o produto não preencheu continua vindo da categoria.');
    }

    public function test_a_subcategory_inherits_from_its_parent_and_overrides_per_field(): void
    {
        $parent = $this->category('Pizzas', $this->completeProductFiscalData());
        $child = $this->category('Pizzas doces', ['ncm' => '19059010'], $parent);
        $product = $this->makeProduct($this->tenant, 'Chocolate', 45, ['category_id' => $child->id]);

        $data = $this->resolve($product);

        $this->assertSame('19059010', $data->ncm, 'A subcategoria vence o pai.');
        $this->assertSame('5102', $data->cfop, 'O resto vem do pai.');
        $this->assertTrue($data->isComplete());
    }

    public function test_gtin_is_never_inherited(): void
    {
        $category = $this->category('Pizzas', $this->completeProductFiscalData());
        $product = $this->makeProduct($this->tenant, 'Calabresa', 40, ['category_id' => $category->id]);

        $this->assertNull($this->resolve($product)->gtin);
        $this->assertNotContains('gtin', ProductFiscalData::INHERITABLE_FIELDS);
    }
}
