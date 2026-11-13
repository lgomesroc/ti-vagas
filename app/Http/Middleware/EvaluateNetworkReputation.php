<?php

namespace App\Http\Middleware;

use App\Services\Network\AbuseTracker;
use App\Services\Network\Detectors\AbuseIpDbDetector;
use App\Services\Network\IpFingerprint;
use App\Services\Network\NetworkAction;
use App\Services\Network\NetworkAssessment;
use App\Services\Network\NetworkClassifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Aplica a política de acesso por tipo de rede (VPN, proxy, Tor, datacenter
 * e endereços abusivos).
 *
 * Roda antes da sessão, para que acessos bloqueados não criem sessões.
 *
 * O IP é usado só para avaliar risco de acesso. O middleware não lê nem
 * grava localização: a localização do visitante continua vindo
 * exclusivamente do navegador, e o IP segue como aviso apenas informativo.
 * Qualquer falha na classificação libera o acesso.
 */
class EvaluateNetworkReputation
{
    /**
     * Atributo da requisição com a NetworkAssessment calculada.
     */
    public const ATTRIBUTE = 'network.assessment';

    public function __construct(
        private readonly NetworkClassifier $classifier,
        private readonly AbuseTracker $tracker,
        private readonly AbuseIpDbDetector $abuseIpDb,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $mode = $this->mode();

        // /up é a verificação de saúde da hospedagem, que vem de datacenter.
        if ($mode === 'off' || $request->is('up')) {
            return $next($request);
        }

        $ip = $request->ip();

        try {
            $assessment = $this->classifier->assess($ip);
        } catch (Throwable $exception) {
            report($exception);

            return $next($request);
        }

        $request->attributes->set(self::ATTRIBUTE, $assessment);

        // Inclui toda rede comum: nada é consultado, contado ou registrado.
        if ($ip === null || $assessment->action === NetworkAction::Allow) {
            return $next($request);
        }

        try {
            $action = $this->resolveAction($request, $ip, $assessment);
            $this->log($ip, $assessment, $action, $mode);

            if ($mode !== 'enforce') {
                return $next($request);
            }

            return match ($action) {
                NetworkAction::Block => $this->deny($request, 403),
                NetworkAction::Restrict => $this->applyRestriction($request, $next, $ip),
                default => $next($request),
            };
        } catch (Throwable $exception) {
            // Cache indisponível, por exemplo: o acesso não pode cair por isso.
            report($exception);

            return $next($request);
        }
    }

    /**
     * Depois do envio da resposta, consulta o AbuseIPDB (se configurado) para
     * IPs ainda sem sinal. O resultado só vale a partir da próxima requisição.
     */
    public function terminate(Request $request, Response $response): void
    {
        $assessment = $request->attributes->get(self::ATTRIBUTE);
        $ip = $request->ip();

        if (
            $ip === null
            || ! $assessment instanceof NetworkAssessment
            || ! $assessment->isOrdinary()
            || ! $this->abuseIpDb->enabled()
        ) {
            return;
        }

        try {
            $this->abuseIpDb->warm($ip);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Ação a aplicar nesta requisição.
     *
     * "evaluate" observa o comportamento do IP: volume de requisições e
     * respostas 429. Se ele se comportar como robô, passa a "restrict" por
     * um tempo.
     */
    private function resolveAction(Request $request, string $ip, NetworkAssessment $assessment): NetworkAction
    {
        if ($assessment->action !== NetworkAction::Evaluate) {
            return $assessment->action;
        }

        $requests = $this->tracker->recordRequest($ip);

        if ($this->isKnownCrawler($request)) {
            return NetworkAction::Evaluate;
        }

        if ($this->tracker->exceedsEvaluateLimit($requests)) {
            $this->tracker->escalate($ip);
        }

        return $this->tracker->isEscalated($ip)
            ? NetworkAction::Restrict
            : NetworkAction::Evaluate;
    }

    private function applyRestriction(Request $request, Closure $next, string $ip): Response
    {
        $retryAfter = $this->tracker->restrictedRetryAfter($ip);

        return $retryAfter === null
            ? $next($request)
            : $this->deny($request, 429, $retryAfter);
    }

    private function deny(Request $request, int $status, ?int $retryAfter = null): Response
    {
        $blocked = $status === 403;

        $heading = $blocked
            ? 'Acesso indisponível a partir desta rede'
            : 'Muitas requisições a partir desta rede';

        $message = $blocked
            ? 'Não foi possível liberar o acesso por esta conexão. Se você usa VPN, proxy ou outra rede de anonimização, desative-a e recarregue a página.'
            : 'Esta conexão atingiu o limite de requisições. Aguarde um pouco e tente novamente.';

        $headers = ['Cache-Control' => 'no-store, private'];

        if ($retryAfter !== null) {
            $headers['Retry-After'] = (string) $retryAfter;
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'code' => $blocked ? 'network_blocked' : 'network_restricted',
            ], $status, $headers);
        }

        return response()->view('network.denied', [
            'heading' => $heading,
            'message' => $message,
            'retryAfter' => $retryAfter,
        ], $status, $headers);
    }

    private function isKnownCrawler(Request $request): bool
    {
        $agent = strtolower((string) $request->userAgent());

        if ($agent === '') {
            return false;
        }

        foreach ((array) config('network_security.crawler_user_agents', []) as $crawler) {
            if ($crawler !== '' && str_contains($agent, strtolower((string) $crawler))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Um registro por IP e ação a cada dez minutos, sem o IP em texto claro.
     */
    private function log(string $ip, NetworkAssessment $assessment, NetworkAction $action, string $mode): void
    {
        if (! $this->tracker->firstSighting($ip, $action->value)) {
            return;
        }

        Log::info('network.access', [
            'ip_hash' => IpFingerprint::short($ip),
            'category' => $assessment->category->value,
            'assessed_action' => $assessment->action->value,
            'action' => $action->value,
            'enforced' => $mode === 'enforce',
            'sources' => $assessment->sources(),
        ]);
    }

    private function mode(): string
    {
        $mode = strtolower(trim((string) config('network_security.mode', 'enforce')));

        return in_array($mode, ['off', 'monitor', 'enforce'], true) ? $mode : 'enforce';
    }
}
