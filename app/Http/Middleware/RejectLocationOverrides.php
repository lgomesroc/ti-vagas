<?php

namespace App\Http\Middleware;

use App\Services\Geo\Security\LocationGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Impede que parâmetros, campos ou cookies escolham a região do visitante.
 *
 * A região usada para filtrar vagas vem exclusivamente da sessão, gravada
 * pelo servidor depois de validar a posição. Qualquer tentativa de impor
 * cidade, UF, região ou coordenadas por outro caminho é removida da
 * requisição (para não vazar a nenhum código posterior, inclusive os links
 * de paginação) e registrada como sinal suspeito.
 *
 * Uso: `location.overrides` (remove coordenadas também) ou
 * `location.overrides:coordinates` nas rotas em que latitude/longitude são a
 * entrada legítima.
 */
class RejectLocationOverrides
{
    public function __construct(
        private readonly LocationGuard $guard
    ) {
    }

    public function handle(Request $request, Closure $next, string ...$options): Response
    {
        $names = array_map('strtolower', (array) config('location_guard.override_params', []));

        if (!in_array('coordinates', $options, true)) {
            $names = array_merge(
                $names,
                array_map('strtolower', (array) config('location_guard.coordinate_params', []))
            );
        }

        $names = array_flip($names);

        $removed = $this->strip($request, $names);

        if ($removed > 0) {
            $this->guard->registerSuspicion($request, 'parameter_tampering');
        }

        return $next($request);
    }

    /**
     * @param array<string, int> $names nomes proibidos (minúsculos) como chaves
     */
    private function strip(Request $request, array $names): int
    {
        $bags = [$request->query, $request->request, $request->cookies];

        if ($request->isJson()) {
            $bags[] = $request->json();
        }

        $removed = 0;

        foreach ($bags as $bag) {
            foreach (array_keys($bag->all()) as $key) {
                if (isset($names[strtolower((string) $key)])) {
                    $bag->remove($key);
                    $removed++;
                }
            }
        }

        return $removed;
    }
}
