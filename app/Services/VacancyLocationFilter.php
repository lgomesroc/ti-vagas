<?php

namespace App\Services;

use Illuminate\Support\Str;

class VacancyLocationFilter
{
    private const RJ_METRO_CITIES = [
        'rio de janeiro',
        'niteroi',
        'sao goncalo',
        'duque de caxias',
        'nova iguacu',
        'sao joao de meriti',
        'belford roxo',
        'nilopolis',
        'mesquita',
        'itaborai',
    ];

    private const NON_METRO_CITIES = [
        'macae',
        'santos',
        'campos dos goytacazes',
        'cabo frio',
        'angra dos reis',
        'petropolis',
        'volta redonda',
        'resende',
        'itaperuna',
        'teresopolis',
        'nova friburgo',
        'araruama',
        'rio das ostras',
        'buzios',
        'sao paulo',
        'curitiba',
        'belo horizonte',
        'porto alegre',
        'florianopolis',
        'brasilia',
        'salvador',
        'recife',
        'fortaleza',
        'goiania',
        'manaus',
        'belem',
    ];

    private const FOREIGN_COUNTRIES = [
        'india',
        'poland',
        'united kingdom',
        'great britain',
        'england',
        'scotland',
        'wales',
        'united states',
        'usa',
        'estados unidos',
        'canada',
        'germany',
        'alemanha',
        'singapore',
        'singapura',
        'hong kong',
        'australia',
        'ireland',
        'irlanda',
        'france',
        'franca',
        'spain',
        'espanha',
        'portugal',
        'netherlands',
        'pais es',
        'paises baixos',
        'switzerland',
        'suica',
        'mexico',
        'argentina',
        'chile',
        'colombia',
        'peru',
        'japan',
        'japao',
        'china',
        'south africa',
        'africa do sul',
        'czech republic',
        'republica tcheca',
        'romania',
        'romenia',
        'reino unido',
        'gra bretanha',
        'escocia',
        'pais de gales',
    ];

    public function classifyWorkModel(
        ?string $title,
        ?string $location,
        ?string $description
    ): ?string {
        $titleText = $this->normalize($title);
        $locationText = $this->normalize($location);
        $descriptionText = $this->normalize(
            mb_substr($description ?? '', 0, 3000)
        );

        $primaryText = $titleText . ' ' . $locationText;

        if ($this->containsAny($primaryText, [
            'hibrido',
            'hybrid',
            'modelo hibrido',
            'regime hibrido',
        ])) {
            return 'Híbrido';
        }

        if ($this->containsAny($primaryText, [
            'presencial',
            'on-site',
            'onsite',
            'office based',
        ])) {
            return 'Presencial';
        }

        if ($this->containsAny($primaryText, [
            'remoto',
            'remote',
            'home office',
            '100% home office',
            'fully remote',
        ])) {
            return 'Remoto';
        }

        if ($this->containsAny($descriptionText, [
            'trabalho 100% remoto',
            'vaga 100% remota',
            'posicao 100% remota',
            'fully remote',
            '100% remote',
            'remote position',
            'work remotely from brazil',
            'remote in brazil',
            'trabalho remoto no brasil',
            'vaga remota no brasil',
            'posicao remota no brasil',
            'regime de trabalho remoto',
            'contratacao totalmente remota',
            'home office integral',
        ])) {
            return 'Remoto';
        }

        if ($this->containsAny($descriptionText, [
            'modelo de trabalho hibrido',
            'regime de trabalho hibrido',
            'trabalho em modelo hibrido',
            'hybrid work model',
            'hybrid position',
        ])) {
            return 'Híbrido';
        }

        if ($this->containsAny($descriptionText, [
            'trabalho presencial',
            'regime presencial',
            'atuacao presencial',
            'presencialmente no escritorio',
            'on-site position',
            'work on-site',
        ])) {
            return 'Presencial';
        }

        return null;
    }

    public function isEligible(
        ?string $location,
        ?string $title,
        ?string $description,
        ?string $workModel
    ): bool {
        $locationText = $this->normalize($location);
        $titleText = $this->normalize($title);
        $descriptionText = $this->normalize($description);

        if ($this->containsForeignCountry($locationText)) {
            return false;
        }

        $brazilIsExplicit = $this->containsAny(
            $locationText . ' ' . $titleText,
            ['brazil', 'brasil']
        );

        $remoteIsExplicitForBrazil = $this->containsAny(
            $descriptionText,
            [
                'work remotely from brazil',
                'remote in brazil',
                'trabalho remoto no brasil',
                'vaga remota no brasil',
                'posicao remota no brasil',
            ]
        );

        // Vagas remotas podem ser de qualquer estado brasileiro.
        if (
            $workModel === 'Remoto'
            && ($brazilIsExplicit || $remoteIsExplicitForBrazil)
        ) {
            return true;
        }

        // Para vagas híbridas ou presenciais, não aceitamos
        // localidades que não estejam na lista da região metropolitana.
        if (!in_array($workModel, ['Híbrido', 'Presencial', null], true)) {
            return false;
        }

        $titleCities = $this->findCitiesInTitle($titleText);

        // Uma cidade fora da região permitida no título
        // prevalece sobre uma localização genérica da fonte.
        foreach ($titleCities as $city) {
            if (in_array($city, self::NON_METRO_CITIES, true)) {
                return false;
            }
        }

        // Se o título identificou cidades, todas precisam
        // pertencer à lista permitida.
        if ($titleCities !== []) {
            foreach ($titleCities as $city) {
                if (!in_array($city, self::RJ_METRO_CITIES, true)) {
                    return false;
                }
            }

            return true;
        }

        // Sem cidade específica no título, usamos a localização
        // fornecida pela fonte.
        return $this->containsAny(
            $locationText,
            self::RJ_METRO_CITIES
        );
    }

    private function findCitiesInTitle(string $title): array
    {
        $cities = [];

        foreach (array_merge(
            self::RJ_METRO_CITIES,
            self::NON_METRO_CITIES
        ) as $city) {
            if (preg_match(
                '/(^|[^a-z])' . preg_quote($city, '/') . '([^a-z]|$)/',
                $title
            )) {
                $cities[] = $city;
            }
        }

        return array_values(array_unique($cities));
    }

    private function containsForeignCountry(string $location): bool
    {
        foreach (self::FOREIGN_COUNTRIES as $country) {
            if (preg_match(
                '/(^|[^a-z])' . preg_quote($country, '/') . '([^a-z]|$)/',
                $location
            )) {
                return true;
            }
        }

        return false;
    }

    private function containsAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if (str_contains($text, $this->normalize($term))) {
                return true;
            }
        }

        return false;
    }

    private function normalize(?string $value): string
    {
        return Str::ascii(mb_strtolower($value ?? ''));
    }
}
