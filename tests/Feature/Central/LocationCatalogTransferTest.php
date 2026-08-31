<?php

namespace Tests\Feature\Central;

use App\Filament\Resources\LocationSyncs\Pages\ListLocationSyncs;
use App\Models\City;
use App\Models\Neighborhood;
use App\Models\State;
use App\Services\Address\LocationCatalogTransfer;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class LocationCatalogTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsPlatformAdmin();
        Filament::setCurrentPanel(Filament::getPanel('central'));
    }

    private function seedCatalog(): void
    {
        $goias = State::create(['name' => 'Goiás', 'uf' => 'GO', 'ibge_code' => 52]);
        $goiania = City::create(['state_id' => $goias->id, 'name' => 'Goiânia', 'ibge_code' => 5208707]);
        $goiania->neighborhoods()->create(['name' => 'Setor Bueno']);
        $goiania->neighborhoods()->create(['name' => 'Setor Oeste']);
    }

    public function test_export_groups_states_cities_and_neighborhoods_by_natural_key(): void
    {
        $this->seedCatalog();

        $payload = app(LocationCatalogTransfer::class)->export();

        $this->assertSame(1, $payload['version']);
        $this->assertCount(1, $payload['states']);
        $this->assertSame('GO', $payload['states'][0]['uf']);
        $this->assertSame(5208707, $payload['states'][0]['cities'][0]['ibge_code']);
        $this->assertEqualsCanonicalizing(
            ['Setor Bueno', 'Setor Oeste'],
            array_column($payload['states'][0]['cities'][0]['neighborhoods'], 'name'),
        );
    }

    public function test_import_creates_the_catalog_in_a_clean_database(): void
    {
        $this->seedCatalog();
        $payload = app(LocationCatalogTransfer::class)->export();

        Neighborhood::query()->delete();
        City::query()->delete();
        State::query()->delete();

        $counts = app(LocationCatalogTransfer::class)->import($payload);

        $this->assertSame(['states' => 1, 'cities' => 1, 'neighborhoods' => 2], $counts);
        $this->assertDatabaseHas('states', ['uf' => 'GO', 'ibge_code' => 52]);
        $this->assertDatabaseHas('cities', ['normalized_name' => 'goiania', 'ibge_code' => 5208707]);
        $this->assertDatabaseHas('neighborhoods', ['normalized_name' => 'setor bueno', 'name' => 'Setor Bueno']);
    }

    public function test_import_is_idempotent_and_does_not_duplicate_rows(): void
    {
        $this->seedCatalog();
        $payload = app(LocationCatalogTransfer::class)->export();

        app(LocationCatalogTransfer::class)->import($payload);
        app(LocationCatalogTransfer::class)->import($payload);

        $this->assertSame(1, State::query()->count());
        $this->assertSame(1, City::query()->count());
        $this->assertSame(2, Neighborhood::query()->count());
    }

    public function test_import_rejects_a_file_that_is_not_a_locations_export(): void
    {
        $this->expectException(ValidationException::class);

        app(LocationCatalogTransfer::class)->import(['foo' => 'bar']);
    }

    public function test_import_action_processes_an_uploaded_json_file(): void
    {
        $this->seedCatalog();
        $payload = app(LocationCatalogTransfer::class)->export();

        Neighborhood::query()->delete();
        City::query()->delete();
        State::query()->delete();

        $file = UploadedFile::fake()->createWithContent(
            'localidades.json',
            json_encode($payload),
        );

        Livewire::test(ListLocationSyncs::class)
            ->callAction(TestAction::make('importCatalog'), ['file' => $file])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertDatabaseHas('cities', ['normalized_name' => 'goiania']);
        $this->assertSame(2, Neighborhood::query()->count());
    }
}
