<?php

namespace Tests\Feature;

use App\Jobs\ImportNeighborhoodsFromRuaCepJob;
use App\Models\City;
use App\Models\Neighborhood;
use App\Models\State;
use App\Models\User;
use App\Notifications\RuaCepImportCompleted;
use App\Notifications\RuaCepImportFailed;
use App\Services\Address\RuaCepBairroScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

class ImportNeighborhoodsFromRuaCepJobTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $state = State::create(['name' => 'Goiás', 'uf' => 'GO', 'ibge_code' => 52]);
        $this->city = City::create(['state_id' => $state->id, 'name' => 'Goiânia', 'ibge_code' => 5208707]);
        $this->superAdmin = User::factory()->create(['tenant_id' => null]);
    }

    private function fakeScraper(array $names): void
    {
        $this->instance(RuaCepBairroScraper::class, Mockery::mock(RuaCepBairroScraper::class, function ($mock) use ($names) {
            $mock->shouldReceive('bairrosOf')->once()->with('GO', 'Goiânia')->andReturn($names);
        }));
    }

    public function test_creates_neighborhoods_from_the_scraped_names_and_notifies_completion(): void
    {
        Notification::fake();
        $this->fakeScraper(['Setor Bueno', 'Setor Oeste']);

        (new ImportNeighborhoodsFromRuaCepJob($this->city->id))->handle(app(RuaCepBairroScraper::class));

        $this->assertDatabaseHas('neighborhoods', ['city_id' => $this->city->id, 'normalized_name' => 'setor bueno']);
        $this->assertDatabaseHas('neighborhoods', ['city_id' => $this->city->id, 'normalized_name' => 'setor oeste']);
        Notification::assertSentTo($this->superAdmin, RuaCepImportCompleted::class);
    }

    public function test_is_idempotent_when_run_twice(): void
    {
        Notification::fake();
        $this->fakeScraper(['Setor Bueno']);
        (new ImportNeighborhoodsFromRuaCepJob($this->city->id))->handle(app(RuaCepBairroScraper::class));

        $this->fakeScraper(['Setor Bueno']);
        (new ImportNeighborhoodsFromRuaCepJob($this->city->id))->handle(app(RuaCepBairroScraper::class));

        $this->assertSame(1, Neighborhood::where('city_id', $this->city->id)->count());
    }

    public function test_deduplicates_repeated_names_from_the_scraper_within_the_same_run(): void
    {
        Notification::fake();
        $this->fakeScraper(['Setor Bueno', 'Setor Bueno', 'setor bueno']);

        (new ImportNeighborhoodsFromRuaCepJob($this->city->id))->handle(app(RuaCepBairroScraper::class));

        $this->assertSame(1, Neighborhood::where('city_id', $this->city->id)->count());
    }

    public function test_notifies_failure_when_scraper_finds_nothing(): void
    {
        Notification::fake();
        $this->fakeScraper([]);

        (new ImportNeighborhoodsFromRuaCepJob($this->city->id))->handle(app(RuaCepBairroScraper::class));

        $this->assertSame(0, Neighborhood::where('city_id', $this->city->id)->count());
        Notification::assertSentTo($this->superAdmin, RuaCepImportFailed::class);
        Notification::assertNotSentTo($this->superAdmin, RuaCepImportCompleted::class);
    }
}
