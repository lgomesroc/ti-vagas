<?php

namespace App\Services\Network;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/**
 * Guarda as listas de IPs compiladas.
 *
 * A fonte da verdade é o cache compartilhado (em produção, o banco), porque
 * quem baixa as listas (agendador no GitHub Actions) não é a mesma máquina
 * que atende as requisições. Cada servidor mantém uma cópia local em arquivo
 * PHP, que o OPcache mantém em memória: a leitura por requisição não passa
 * pelo banco. A cópia é renovada a partir do cache compartilhado quando
 * passa de $localTtl segundos.
 *
 * Se não houver lista alguma, devolve null: quem chama deve tratar o IP como
 * não classificado (falha aberta), nunca como suspeito.
 */
class IpListStore
{
    private const CACHE_PREFIX = 'network.list.v1.';

    /** @var array<string, IpRangeSet|null> */
    private array $memo = [];

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly string $directory,
        private readonly int $localTtl = 900,
        private readonly int $missingTtl = 300,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function put(string $name, IpRangeSet $set, array $meta = []): void
    {
        $name = $this->safeName($name);

        $payload = [
            'meta' => $meta + ['updated_at' => now()->toIso8601String(), 'ranges' => $set->count()],
            'set' => $set->toArray(),
        ];

        $this->cache->forever(self::CACHE_PREFIX.$name, $payload);
        $this->writeLocal($name, $payload);

        unset($this->memo[$name]);
    }

    public function get(string $name): ?IpRangeSet
    {
        $name = $this->safeName($name);

        if (array_key_exists($name, $this->memo)) {
            return $this->memo[$name];
        }

        $payload = $this->loadPayload($name);

        return $this->memo[$name] = $payload !== null
            ? IpRangeSet::fromArray($payload['set'])
            : null;
    }

    /**
     * Metadados da última importação (data, URL, número de faixas).
     *
     * @return array<string, mixed>|null
     */
    public function meta(string $name): ?array
    {
        $payload = $this->loadPayload($this->safeName($name));

        return $payload['meta'] ?? null;
    }

    /**
     * @return array{meta: array<string, mixed>, set: array<string, mixed>}|null
     */
    private function loadPayload(string $name): ?array
    {
        $file = $this->path($name);
        $missing = $file.'.missing';

        if ($this->isFresh($file, $this->localTtl)) {
            $payload = $this->readLocal($file);

            if ($payload !== null) {
                return $payload;
            }
        }

        if ($this->isFresh($missing, $this->missingTtl)) {
            return null;
        }

        try {
            $remote = $this->cache->get(self::CACHE_PREFIX.$name);
        } catch (Throwable) {
            $remote = null;
        }

        if ($this->isValid($remote)) {
            $this->writeLocal($name, $remote);

            return $remote;
        }

        // Sem lista no cache compartilhado: usa a cópia local vencida, se
        // houver, e evita consultar o cache a cada requisição.
        $stale = is_file($file) ? $this->readLocal($file) : null;

        if ($stale !== null) {
            @touch($file);

            return $stale;
        }

        $this->ensureDirectory();
        @touch($missing);

        return null;
    }

    /**
     * @return array{meta: array<string, mixed>, set: array<string, mixed>}|null
     */
    private function readLocal(string $file): ?array
    {
        try {
            $payload = (static fn (): mixed => require $file)();
        } catch (Throwable) {
            @unlink($file);

            return null;
        }

        return $this->isValid($payload) ? $payload : null;
    }

    /**
     * @param  array{meta: array<string, mixed>, set: array<string, mixed>}  $payload
     */
    private function writeLocal(string $name, array $payload): void
    {
        if (! $this->ensureDirectory()) {
            return;
        }

        $file = $this->path($name);
        $temporary = @tempnam($this->directory, 'tmp');

        if ($temporary === false) {
            return;
        }

        $code = '<?php return [\'meta\'=>'.var_export($payload['meta'], true)
            .',\'set\'=>'.IpRangeSet::fromArray($payload['set'])->toPhp().'];';

        if (@file_put_contents($temporary, $code) === false) {
            @unlink($temporary);

            return;
        }

        @chmod($temporary, 0644);

        if (! @rename($temporary, $file)) {
            @unlink($temporary);

            return;
        }

        @unlink($file.'.missing');

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    private function isValid(mixed $payload): bool
    {
        return is_array($payload)
            && is_array($payload['set'] ?? null)
            && is_array($payload['meta'] ?? null);
    }

    private function isFresh(string $file, int $ttl): bool
    {
        $modified = is_file($file) ? @filemtime($file) : false;

        return $modified !== false && (time() - $modified) < $ttl;
    }

    private function ensureDirectory(): bool
    {
        return is_dir($this->directory)
            || @mkdir($this->directory, 0775, true)
            || is_dir($this->directory);
    }

    private function path(string $name): string
    {
        return rtrim($this->directory, '/\\').DIRECTORY_SEPARATOR.$name.'.php';
    }

    private function safeName(string $name): string
    {
        if (! preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException("Nome de lista inválido: {$name}");
        }

        return $name;
    }
}
