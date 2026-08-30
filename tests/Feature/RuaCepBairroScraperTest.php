<?php

namespace Tests\Feature;

use App\Services\Address\RuaCepBairroScraper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class RuaCepBairroScraperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Trava de segurança: qualquer requisição não coberta por um fake
        // explícito estoura StrayRequestException em vez de vazar pra
        // internet de verdade — foi exatamente isso que aconteceu antes
        // desta correção (um teste com URL de fake sem o "www." deixou
        // passar uma consulta real ao ruacep.com.br).
        Http::preventStrayRequests();
    }

    private function cardHtml(string $name): string
    {
        $slug = Str::slug($name);

        return <<<HTML
            <div class="col-sm-6 mb-4">
              <div class="card shadow-sm">
                <div class="card-header">
                  <a href="https://www.ruacep.com.br/go/goiania/{$slug}/logradouros/" class="text-decoration-none"><strong>{$name}</strong></a>
                </div>
                <div class="card-body">
                  <p class="card-text">CEP: 74000-000 à 74000-999</p>
                </div>
              </div>
            </div>
            HTML;
    }

    /**
     * O scraper NÃO lê link de "próxima página" do HTML — ele só incrementa
     * /bairros/{n}/ sequencialmente até uma página vir vazia. Por isso todo
     * teste com resultado precisa fakear também a página seguinte vazia,
     * pra dar um ponto de parada real ao loop.
     */
    private function pageHtml(array $names): string
    {
        return '<html><body>'.collect($names)->map(fn ($n) => $this->cardHtml($n))->implode("\n").'</body></html>';
    }

    private function emptyPageHtml(): string
    {
        return '<html><body>Nenhum bairro encontrado.</body></html>';
    }

    public function test_extracts_names_from_the_first_page_and_stops_when_the_next_page_is_empty(): void
    {
        Http::fake([
            'https://www.ruacep.com.br/go/goiania/bairros/' => Http::response($this->pageHtml(['Setor Bueno', 'Setor Marista'])),
            'https://www.ruacep.com.br/go/goiania/bairros/2/' => Http::response($this->emptyPageHtml()),
        ]);

        $names = app(RuaCepBairroScraper::class)->bairrosOf('GO', 'Goiânia');

        $this->assertSame(['Setor Bueno', 'Setor Marista'], $names);
        Http::assertSentCount(2);
    }

    public function test_follows_pagination_across_multiple_pages(): void
    {
        Http::fake([
            'https://www.ruacep.com.br/go/goiania/bairros/' => Http::response($this->pageHtml(['Setor Bueno'])),
            'https://www.ruacep.com.br/go/goiania/bairros/2/' => Http::response($this->pageHtml(['Setor Oeste'])),
            'https://www.ruacep.com.br/go/goiania/bairros/3/' => Http::response($this->emptyPageHtml()),
        ]);

        $names = app(RuaCepBairroScraper::class)->bairrosOf('GO', 'Goiânia');

        $this->assertSame(['Setor Bueno', 'Setor Oeste'], $names);
        Http::assertSentCount(3);
    }

    public function test_slugifies_uf_and_city_name_for_the_url(): void
    {
        Http::fake([
            'https://www.ruacep.com.br/sp/sao-paulo/bairros/' => Http::response($this->pageHtml(['Sé'])),
            'https://www.ruacep.com.br/sp/sao-paulo/bairros/2/' => Http::response($this->emptyPageHtml()),
        ]);

        $names = app(RuaCepBairroScraper::class)->bairrosOf('SP', 'São Paulo');

        $this->assertSame(['Sé'], $names);
    }

    public function test_returns_empty_array_without_throwing_when_the_city_page_does_not_exist(): void
    {
        Http::fake([
            'https://www.ruacep.com.br/*' => Http::response('not found', 404),
        ]);

        $names = app(RuaCepBairroScraper::class)->bairrosOf('GO', 'Cidade Inexistente');

        $this->assertSame([], $names);
    }

    public function test_returns_empty_array_without_throwing_on_connection_failure(): void
    {
        Http::fake([
            'https://www.ruacep.com.br/*' => fn () => throw new ConnectionException('timeout'),
        ]);

        $names = app(RuaCepBairroScraper::class)->bairrosOf('GO', 'Goiânia');

        $this->assertSame([], $names);
    }
}
