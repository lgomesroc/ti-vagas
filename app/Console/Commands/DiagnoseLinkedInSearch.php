<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class DiagnoseLinkedInSearch extends Command
{
    protected $signature = 'linkedin:diagnose-search';

    protected $description = 'Diagnostica a busca pública do LinkedIn sem cadastrar ou alterar vagas';

    public function handle(): int
    {
        $this->info('Diagnóstico de busca do LinkedIn (não grava candidatos nem publica vagas).');
        $this->line('Coleta habilitada: ' . (config('linkedin.enabled') ? 'sim' : 'não'));
        $this->line('Extensão PHP DOM: ' . (class_exists(\DOMDocument::class) ? 'disponível' : 'ausente'));
        $this->line('Janela de busca: últimas ' . (int) config('linkedin.lookback_hours', 72) . ' horas');
        $bundle = trim((string) config('linkedin.ca_bundle', ''));
        $this->line('CA bundle configurado: ' . ($bundle !== '' && is_file($bundle) && is_readable($bundle) ? 'sim' : 'não (validação padrão)'));
        $this->newLine();

        $keyword = collect(config('linkedin.keywords', []))
            ->first(fn ($value) => is_string($value) && trim($value) !== '');

        if (!$keyword) {
            $this->error('Nenhuma palavra-chave está configurada.');

            return self::FAILURE;
        }

        $query = '(site:linkedin.com/posts OR site:linkedin.com/feed/update) "' . $keyword . '"';

        try {
            $bundle = trim((string) config('linkedin.ca_bundle', ''));
            $verify = $bundle !== '' && is_file($bundle) && is_readable($bundle) ? $bundle : true;

            $response = Http::withOptions(['verify' => $verify])->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; TIVagas/1.0)',
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'pt-BR,pt;q=0.9,en;q=0.8',
            ])->timeout(15)->get('https://html.duckduckgo.com/html/', [
                'q' => $query,
                'kl' => 'br-pt',
            ]);

            $html = $response->body();
            $domAvailable = class_exists(\DOMDocument::class);
            $anchors = 0;
            $linkedinUrls = 0;
            $relativeDates = 0;
            $absoluteDates = 0;

            if ($domAvailable && $html !== '') {
                $previous = libxml_use_internal_errors(true);

                try {
                    $document = new \DOMDocument();
                    if ($document->loadHTML($html)) {
                        $xpath = new \DOMXPath($document);
                        $nodes = $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " result__a ")]');
                        $anchors = $nodes ? $nodes->length : 0;

                        foreach ($nodes ?: [] as $node) {
                            $url = html_entity_decode(trim($node->getAttribute('href')));
                            if (str_starts_with($url, '//')) {
                                $url = 'https:' . $url;
                            }
                            if (str_contains(strtolower($url), 'linkedin.com/posts/')
                                || str_contains(strtolower($url), 'linkedin.com/feed/update/')) {
                                $linkedinUrls++;
                            }

                            $parent = $node->parentNode?->parentNode;
                            $snippetNode = $parent instanceof \DOMNode
                                ? $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " result__snippet ")]', $parent)?->item(0)
                                : null;
                            $snippet = mb_strtolower(trim($node->textContent . ' ' . ($snippetNode?->textContent ?? '')));

                            if (preg_match('/\b(ha\s+)?\d{1,2}\s*(min|minuto|minutos|h|hr|hrs|hora|horas|d|day|days|dia|dias|sem|semana|semanas)\b|\b(today|hoje|yesterday|ontem)\b/', $snippet)) {
                                $relativeDates++;
                            }
                            if (preg_match('/\b(20\d{2}|jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec|jan|fev|mar|abr|mai|jun|jul|ago|set|out|nov|dez)\b/i', $snippet)) {
                                $absoluteDates++;
                            }
                        }
                    }
                } finally {
                    libxml_clear_errors();
                    libxml_use_internal_errors($previous);
                }
            }

            $this->table(
                ['Verificação', 'Resultado'],
                [
                    ['HTTP', $response->status() . ($response->successful() ? ' (sucesso)' : ' (falha)')],
                    ['Tamanho da resposta', number_format(strlen($html), 0, ',', '.') . ' bytes'],
                    ['Marcadores de resultado DDG', $anchors],
                    ['URLs LinkedIn reconhecidas', $linkedinUrls],
                    ['Resultados com data relativa', $relativeDates],
                    ['Resultados com indicação de data absoluta/ano', $absoluteDates],
                    ['Página parece bloqueio/captcha', preg_match('/captcha|unusual traffic|verify you are human|anomaly\.js/i', $html) ? 'sim' : 'não'],
                ]
            );

            if (!$response->successful()) {
                $this->warn('O DuckDuckGo respondeu com HTTP não exitoso. Verifique conectividade ou restrições da rede.');
                return self::FAILURE;
            }

            if ($html === '') {
                $this->warn('O buscador retornou uma resposta vazia.');
                return self::FAILURE;
            }

            if (!$domAvailable) {
                $this->warn('A extensão DOM está ausente; o coletor não consegue interpretar os resultados HTML.');
                return self::FAILURE;
            }

            if ($anchors === 0) {
                $this->warn('Nenhum resultado no formato esperado foi encontrado. Pode ser bloqueio, mudança no HTML ou página de desafio.');
                return self::FAILURE;
            }

            if ($linkedinUrls > 0 && $relativeDates === 0) {
                $this->warn('Há links do LinkedIn, mas nenhum snippet contém data relativa reconhecida. O filtro de data pode estar descartando tudo.');
            }

            $this->info('Diagnóstico concluído. Nenhuma vaga foi cadastrada ou alterada.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Falha ao consultar DuckDuckGo: ' . $exception->getMessage());
            $this->line('Nenhum dado foi gravado.');

            return self::FAILURE;
        }
    }
}
