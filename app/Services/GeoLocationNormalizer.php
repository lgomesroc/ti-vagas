<?php

namespace App\Services;

use Illuminate\Support\Str;

class GeoLocationNormalizer
{
    private const STATES = [
        'ac' => 'AC',
        'acre' => 'AC',
        'al' => 'AL',
        'alagoas' => 'AL',
        'ap' => 'AP',
        'amapa' => 'AP',
        'am' => 'AM',
        'amazonas' => 'AM',
        'ba' => 'BA',
        'bahia' => 'BA',
        'ce' => 'CE',
        'ceara' => 'CE',
        'df' => 'DF',
        'distrito federal' => 'DF',
        'es' => 'ES',
        'espirito santo' => 'ES',
        'go' => 'GO',
        'goias' => 'GO',
        'ma' => 'MA',
        'maranhao' => 'MA',
        'mt' => 'MT',
        'mato grosso' => 'MT',
        'ms' => 'MS',
        'mato grosso do sul' => 'MS',
        'mg' => 'MG',
        'minas gerais' => 'MG',
        'pa' => 'PA',
        'para' => 'PA',
        'pb' => 'PB',
        'paraiba' => 'PB',
        'pr' => 'PR',
        'parana' => 'PR',
        'pe' => 'PE',
        'pernambuco' => 'PE',
        'pi' => 'PI',
        'piaui' => 'PI',
        'rj' => 'RJ',
        'rio de janeiro' => 'RJ',
        'rn' => 'RN',
        'rio grande do norte' => 'RN',
        'rs' => 'RS',
        'rio grande do sul' => 'RS',
        'ro' => 'RO',
        'rondonia' => 'RO',
        'rr' => 'RR',
        'roraima' => 'RR',
        'sc' => 'SC',
        'santa catarina' => 'SC',
        'sp' => 'SP',
        'sao paulo' => 'SP',
        'se' => 'SE',
        'sergipe' => 'SE',
        'to' => 'TO',
        'tocantins' => 'TO',
    ];

    /**
     * Padroniza os dados geográficos fornecidos pela fonte.
     *
     * Não tenta descobrir município ou coordenadas
     * a partir de texto livre.
     */
    public function normalize(array $data): array
    {
        $city = $this->firstString($data, [
            'city',
            'addressLocality',
            'address_locality',
            'locality',
        ]);

        $state = $this->firstString($data, [
            'state',
            'addressRegion',
            'address_region',
            'region',
        ]);

        $stateCode = $this->normalizeStateCode(
            $data['state_code'] ?? null
        );

        if ($stateCode === null) {
            $stateCode = $this->normalizeStateCode($state);
        }

        $country = $this->firstString($data, [
            'country_code',
            'addressCountry',
            'address_country',
            'country',
        ]);

        $countryCode = $this->normalizeCountryCode($country);

        $ibgeCode = $this->normalizeIbgeCode(
            $data['ibge_municipality_code'] ?? null
        );

        return [
            'city' => $city,
            'state' => $stateCode !== null
                ? $this->stateName($stateCode)
                : $this->cleanString($state),
            'state_code' => $stateCode,
            'country_code' => $countryCode,
            'ibge_municipality_code' => $ibgeCode,
            'latitude' => $this->normalizeCoordinate(
                $data['latitude'] ?? null,
                -90,
                90
            ),
            'longitude' => $this->normalizeCoordinate(
                $data['longitude'] ?? null,
                -180,
                180
            ),
        ];
    }

    private function firstString(
        array $data,
        array $keys
    ): ?string {
        foreach ($keys as $key) {
            $value = $this->cleanString($data[$key] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function cleanString(mixed $value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function normalizeStateCode(mixed $state): ?string
    {
        if (!is_string($state)) {
            return null;
        }

        $normalized = Str::ascii(
            mb_strtolower(trim($state))
        );

        return self::STATES[$normalized] ?? null;
    }

    private function stateName(string $code): string
    {
        $names = [
            'AC' => 'Acre',
            'AL' => 'Alagoas',
            'AP' => 'Amapá',
            'AM' => 'Amazonas',
            'BA' => 'Bahia',
            'CE' => 'Ceará',
            'DF' => 'Distrito Federal',
            'ES' => 'Espírito Santo',
            'GO' => 'Goiás',
            'MA' => 'Maranhão',
            'MT' => 'Mato Grosso',
            'MS' => 'Mato Grosso do Sul',
            'MG' => 'Minas Gerais',
            'PA' => 'Pará',
            'PB' => 'Paraíba',
            'PR' => 'Paraná',
            'PE' => 'Pernambuco',
            'PI' => 'Piauí',
            'RJ' => 'Rio de Janeiro',
            'RN' => 'Rio Grande do Norte',
            'RS' => 'Rio Grande do Sul',
            'RO' => 'Rondônia',
            'RR' => 'Roraima',
            'SC' => 'Santa Catarina',
            'SP' => 'São Paulo',
            'SE' => 'Sergipe',
            'TO' => 'Tocantins',
        ];

        return $names[$code];
    }

    private function normalizeCountryCode(mixed $country): ?string
    {
        if (!is_string($country)) {
            return null;
        }

        $normalized = Str::ascii(
            mb_strtolower(trim($country))
        );

        if (in_array($normalized, [
            'br',
            'brasil',
            'brazil',
        ], true)) {
            return 'BR';
        }

        $country = strtoupper(trim($country));

        if (preg_match('/^[A-Z]{2}$/', $country)) {
            return $country;
        }

        return null;
    }

    private function normalizeIbgeCode(mixed $code): ?string
    {
        if (!is_string($code) && !is_int($code)) {
            return null;
        }

        $code = trim((string) $code);

        return preg_match('/^\d{7}$/', $code)
            ? $code
            : null;
    }

    private function normalizeCoordinate(
        mixed $value,
        float $minimum,
        float $maximum
    ): ?float {
        if (!is_numeric($value)) {
            return null;
        }

        $coordinate = (float) $value;

        if (
            !is_finite($coordinate)
            || $coordinate < $minimum
            || $coordinate > $maximum
        ) {
            return null;
        }

        return $coordinate;
    }
}
