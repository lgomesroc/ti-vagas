<?php

namespace Tests\Feature;

use App\Sources\AblerSource;
use ReflectionMethod;
use Tests\TestCase;

class AblerSourceTest extends TestCase
{
    public function test_it_keeps_the_abler_source_identifier(): void
    {
        $this->assertSame('abler-global', (new AblerSource())->name());
    }

    public function test_it_accepts_junior_technology_roles_and_rejects_seniority_above_junior(): void
    {
        $source = new AblerSource();
        $method = new ReflectionMethod($source, 'isTechnologyVacancy');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($source, 'Desenvolvedor PHP Júnior'));
        $this->assertTrue($method->invoke($source, 'Analista de Suporte Jr'));
        $this->assertFalse($method->invoke($source, 'Desenvolvedor PHP Pleno'));
        $this->assertFalse($method->invoke($source, 'Desenvolvedor Sênior'));
        $this->assertFalse($method->invoke($source, 'Estágio em Desenvolvimento'));
    }
}
