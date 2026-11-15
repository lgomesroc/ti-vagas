<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CaptureUtmParameters
{
    /**
     * @var array<int, string>
     */
    private const PARAMETERS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_id',
        'utm_content',
        'utm_term',
        'utm_source_platform',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && !$request->session()->has('utm_attribution')) {
            $attribution = [];

            foreach (self::PARAMETERS as $parameter) {
                $value = $request->query($parameter);

                if (!is_string($value)) {
                    continue;
                }

                $value = trim(strip_tags($value));

                if ($value !== '') {
                    $attribution[substr($parameter, 4)] = substr($value, 0, 100);
                }
            }

            if ($attribution !== []) {
                $request->session()->put('utm_attribution', $attribution);
            }
        }

        return $next($request);
    }
}
