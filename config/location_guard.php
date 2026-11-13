<?php

/*
|--------------------------------------------------------------------------
| Proteção contra falsificação de localização
|--------------------------------------------------------------------------
|
| Nenhuma verificação feita no servidor consegue provar que o GPS é real:
| navegadores e dispositivos permitem simular a posição. Estas regras
| tornam a falsificação mais trabalhosa e detectável, mas não a impedem.
|
| Cada sinal suspeito tem um peso. A atualização de região é recusada
| quando a soma dos pesos de uma única tentativa atinge `reject_score`.
| Os pesos de tentativas seguidas se acumulam na sessão (com decaimento);
| ao atingir `lock_score`, a região atual fica congelada por `lock_minutes`.
|
*/

return [

    'enabled' => env('LOCATION_GUARD_ENABLED', true),

    // Canal de log dos eventos suspeitos (null = canal padrão).
    'log_channel' => env('LOCATION_GUARD_LOG_CHANNEL'),

    'reject_score' => 4,

    'lock_score' => 8,

    'lock_minutes' => 30,

    // Meia-vida do acúmulo de suspeitas na sessão.
    'score_half_life_minutes' => 10,

    'weights' => [
        // Corpo da requisição fora do formato que o navegador legítimo envia.
        'malformed_payload' => 2,

        // Parâmetro, cookie ou campo tentando impor cidade, UF ou coordenadas.
        'parameter_tampering' => 1,

        // Coordenadas que não caem em nenhum município plausível.
        'unresolvable_coordinates' => 1,

        // Velocidade impossível em relação à última posição aceita.
        'impossible_travel' => 5,

        // Velocidade incomum (acima de trem-bala), possível só em avião.
        'fast_travel' => 2,

        // Coordenada igual ao centroide de um município (dado público no
        // repositório). GPS real nunca coincide com esse ponto.
        'centroid_exact' => 4,

        // Hora do GPS muito distante da hora do servidor (replay ou relógio).
        'stale_or_skewed_timestamp' => 2,

        // Precisão declarada igual ou menor que zero.
        'accuracy_zero' => 2,

        // Precisão declarada menor que `min_plausible_accuracy_meters`.
        'accuracy_too_perfect' => 1,

        // Latitude e longitude com no máximo 2 casas decimais.
        'round_coordinates' => 2,

        // UF estimada pelo IP diferente da UF do GPS. Sinal fraco: VPN e
        // operadoras móveis causam isso com frequência.
        'ip_state_mismatch' => 1,

        // Trocas de região demais em pouco tempo.
        'region_hopping' => 3,
    ],

    'min_plausible_accuracy_meters' => 1.0,

    'max_clock_skew_seconds' => 300,

    'impossible_speed_kmh' => 1000.0,

    'suspicious_speed_kmh' => 300.0,

    // Deslocamentos menores que isso não entram no cálculo de velocidade.
    'min_travel_km' => 5.0,

    'centroid_exact_meters' => 15.0,

    // Limite de trocas de região por sessão e por IP, em uma hora.
    'max_region_changes_per_session_hour' => 3,

    'max_region_changes_per_ip_hour' => 20,

    // Compara a UF do GPS com a estimada pelo IP (consulta já em cache).
    'ip_crosscheck' => env('LOCATION_GUARD_IP_CROSSCHECK', true),

    /*
    |--------------------------------------------------------------------------
    | Parâmetros que o visitante NUNCA pode usar para escolher a região
    |--------------------------------------------------------------------------
    |
    | Removidos da query string, do corpo e dos cookies antes de chegarem aos
    | controllers. A região vem somente da sessão, validada no servidor.
    |
    */

    'override_params' => [
        'uf', 'estado', 'state', 'state_code', 'statecode',
        'cidade', 'city', 'city_name', 'cityname',
        'municipio', 'municipality', 'municipality_id',
        'ibge', 'ibge_code', 'visitor_municipality_ibge',
        'regiao', 'region', 'region_code', 'scope', 'escopo',
        'location', 'localizacao', 'location_mode',
        'visitor_state_code', 'visitor_location_label',
        'location_guard', 'location_proof',
    ],

    // Removidos nas rotas em que coordenadas não são entrada legítima.
    'coordinate_params' => [
        'lat', 'lng', 'lon', 'long', 'latitude', 'longitude',
        'coords', 'coordinates', 'geo', 'accuracy',
        'location_latitude', 'location_longitude',
    ],

];
