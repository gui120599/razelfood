<?php

namespace App\Services\Address;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Lista os bairros de uma cidade via ruacep.com.br (terceiro não-oficial,
 * sem API/JSON — HTML paginado). Complementa o ViaCEP: diferente dele, o
 * RuaCEP tem uma página de listagem por cidade, então não precisamos varrer
 * faixa de CEP pra descobrir bairros aqui — só paginar essa listagem.
 *
 * Parsing via DOMDocument/DOMXPath (nativos do PHP, ext-dom) — o projeto não
 * tem symfony/dom-crawler instalado e adicionar dependência exige aprovação,
 * então evitamos.
 */
class RuaCepBairroScraper
{
    private const BASE_URL = 'https://www.ruacep.com.br';

    // Guarda de segurança contra paginação quebrada/loop infinito — nenhuma
    // cidade real tem milhares de páginas de bairro (~20 itens/página).
    private const MAX_PAGES = 100;

    /**
     * @return array<int, string> nomes de bairro crus, na ordem encontrada
     *                            (sem dedupe — quem chama decide)
     */
    public function bairrosOf(string $uf, string $cityName): array
    {
        $uf = strtolower($uf);
        $slug = Str::slug($cityName);

        $names = [];
        $page = 1;

        do {
            $url = $page === 1
                ? self::BASE_URL."/{$uf}/{$slug}/bairros/"
                : self::BASE_URL."/{$uf}/{$slug}/bairros/{$page}/";

            try {
                $response = Http::timeout(10)->get($url);
            } catch (ConnectionException) {
                break;
            }

            if (! $response->ok()) {
                break;
            }

            $pageNames = $this->extractNames($response->body());
            $names = [...$names, ...$pageNames];
            $page++;

            if ($pageNames !== [] && $page <= self::MAX_PAGES) {
                // Respeitoso com um site pequeno de terceiro — não é o mesmo
                // volume/urgência do sweep de CEP (aqui são só dezenas de
                // páginas por cidade).
                usleep(300_000);
            }
        } while ($pageNames !== [] && $page <= self::MAX_PAGES);

        return $names;
    }

    /**
     * @return array<int, string>
     */
    private function extractNames(string $html): array
    {
        $dom = new DOMDocument;

        libxml_use_internal_errors(true);
        // DOMDocument::loadHTML() assume ISO-8859-1 quando o HTML não
        // declara charset explicitamente, corrompendo acento ("Sé" vira
        // "SÃ©") — forçar UTF-8 aqui evita depender do site sempre declarar
        // <meta charset> corretamente.
        $dom->loadHTML('<?xml encoding="utf-8">'.$html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query(
            "//a[contains(concat(' ', normalize-space(@class), ' '), ' text-decoration-none ')]/strong",
        );

        $names = [];

        foreach ($nodes as $node) {
            $name = trim($node->textContent);

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }
}
