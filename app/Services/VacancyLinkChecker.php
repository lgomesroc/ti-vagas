<?php

namespace App\Services;

use App\Models\Vacancy;
use App\Models\VacancyAvailabilityCheck;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class VacancyLinkChecker
{
    private const MAX_REDIRECTS = 5;
    private const MAX_BODY_LENGTH = 250000;
    private const NOT_FOUND_FAILURES_TO_EXPIRE = 2;

    public function __construct(
        private readonly VacancyAvailabilityDetector $availabilityDetector
    ) {
    }

    public function check(Vacancy $vacancy): string
    {
        $startedAt = microtime(true);
        $checkedUrl = $vacancy->application_url;
        $finalUrl = $checkedUrl;
        $httpStatus = null;
        $reason = null;
        $responseTime = null;
        $newStatus = 'suspect';
        $consecutiveFailures = (int) $vacancy->availability_consecutive_failures;

        try {
            $response = $this->requestFollowingRedirects($checkedUrl);
            $finalUrl = $response['url'];
            $httpStatus = $response['response']->status();
            $responseTime = (int) round((microtime(true) - $startedAt) * 1000);

            if ($httpStatus === 410) {
                $newStatus = 'expired';
                $reason = 'A página de candidatura respondeu HTTP 410 (Gone).';
                $consecutiveFailures = 0;
            } elseif ($httpStatus === 404) {
                $consecutiveFailures++;
                $newStatus = $consecutiveFailures >= self::NOT_FOUND_FAILURES_TO_EXPIRE
                    ? 'expired'
                    : 'suspect';
                $reason = $newStatus === 'expired'
                    ? 'A página respondeu HTTP 404 em verificações consecutivas.'
                    : 'A página respondeu HTTP 404; aguardando confirmação em nova verificação.';
            } elseif ($httpStatus >= 200 && $httpStatus < 300) {
                $body = mb_substr($response['response']->body(), 0, self::MAX_BODY_LENGTH);

                if ($this->availabilityDetector->isUnavailable(null, $body)) {
                    $newStatus = 'expired';
                    $reason = 'O conteúdo da página informa que a oportunidade ou candidatura foi encerrada.';
                    $consecutiveFailures = 0;
                } else {
                    $newStatus = 'active';
                    $reason = 'Página de candidatura acessível; nenhum indicador de encerramento foi identificado.';
                    $consecutiveFailures = 0;
                }
            } else {
                $consecutiveFailures++;
                $newStatus = 'suspect';
                $reason = "A página respondeu HTTP {$httpStatus}; a indisponibilidade não pode ser confirmada.";
            }
        } catch (ConnectionException $exception) {
            $consecutiveFailures++;
            $reason = 'Falha de conexão ou timeout: ' . $exception->getMessage();
        } catch (Throwable $exception) {
            $consecutiveFailures++;
            $reason = 'Não foi possível validar a URL: ' . $exception->getMessage();
        }

        $checkedAt = now();

        $vacancy->forceFill([
            'availability_status' => $newStatus,
            'availability_checked_at' => $checkedAt,
            'availability_http_status' => $httpStatus,
            'availability_consecutive_failures' => $consecutiveFailures,
            'availability_last_error' => $newStatus === 'active' || $newStatus === 'expired'
                ? null
                : $reason,
        ])->save();

        VacancyAvailabilityCheck::query()->create([
            'vacancy_id' => $vacancy->getKey(),
            'check_type' => 'http',
            'status' => $newStatus,
            'http_status' => $httpStatus,
            'checked_url' => $checkedUrl,
            'final_url' => $finalUrl,
            'reason' => $reason,
            'response_time_ms' => $responseTime,
            'checked_at' => $checkedAt,
        ]);

        return $newStatus;
    }

    /**
     * Segue redirecionamentos manualmente para validar cada destino antes
     * de realizar uma nova requisição.
     *
     * @return array{response: \Illuminate\Http\Client\Response, url: string}
     */
    private function requestFollowingRedirects(string $url): array
    {
        for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++) {
            $this->assertPublicHttpUrl($url);

            $response = Http::accept('text/html,application/xhtml+xml,*/*')
                ->connectTimeout(5)
                ->timeout(15)
                ->withOptions(['allow_redirects' => false])
                ->get($url);

            if (!$response->redirect()) {
                return [
                    'response' => $response,
                    'url' => $url,
                ];
            }

            $location = $response->header('Location');

            if (!is_string($location) || trim($location) === '') {
                return [
                    'response' => $response,
                    'url' => $url,
                ];
            }

            if ($redirect === self::MAX_REDIRECTS) {
                throw new \RuntimeException('Limite de redirecionamentos excedido.');
            }

            $url = (string) UriResolver::resolve(
                new Uri($url),
                new Uri(trim($location))
            );
        }

        throw new \RuntimeException('Não foi possível concluir os redirecionamentos.');
    }

    private function assertPublicHttpUrl(string $url): void
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('URL de candidatura inválida.');
        }

        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (
            !in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || in_array($host, ['localhost', 'localhost.localdomain'], true)
        ) {
            throw new \InvalidArgumentException('Destino HTTP não permitido.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $publicIp = filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );

            if ($publicIp === false) {
                throw new \InvalidArgumentException('Endereço IP privado ou reservado bloqueado.');
            }
        } elseif (!str_contains($host, '.')) {
            throw new \InvalidArgumentException('Nome de host local bloqueado.');
        }
    }
}
