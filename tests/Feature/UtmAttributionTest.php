<?php

namespace Tests\Feature;

use Tests\TestCase;

class UtmAttributionTest extends TestCase
{
    public function test_campaign_parameters_are_captured_in_the_session(): void
    {
        $this->get(route('welcome', [
            'utm_source' => 'linkedin',
            'utm_medium' => 'social',
            'utm_campaign' => 'lancamento',
            'utm_content' => 'post-principal',
        ]))
            ->assertOk()
            ->assertSessionHas('utm_attribution', [
                'source' => 'linkedin',
                'medium' => 'social',
                'campaign' => 'lancamento',
                'content' => 'post-principal',
            ]);
    }

    public function test_first_campaign_source_is_preserved_during_the_session(): void
    {
        $this->withSession([
            'utm_attribution' => [
                'source' => 'linkedin',
                'medium' => 'social',
                'campaign' => 'lancamento',
            ],
        ])->get(route('welcome', [
            'utm_source' => 'newsletter',
            'utm_medium' => 'email',
        ]))
            ->assertOk()
            ->assertSessionHas('utm_attribution.source', 'linkedin')
            ->assertSessionHas('utm_attribution.medium', 'social')
            ->assertSessionHas('utm_attribution.campaign', 'lancamento');
    }

    public function test_empty_or_non_string_campaign_values_are_ignored(): void
    {
        $this->get(route('welcome', [
            'utm_source' => '',
            'utm_medium' => ['social'],
            'utm_campaign' => '   ',
        ]))
            ->assertOk()
            ->assertSessionMissing('utm_attribution');
    }

    public function test_campaign_values_are_stripped_and_limited_to_one_hundred_characters(): void
    {
        $value = '<b>' . str_repeat('a', 120) . '</b>';

        $this->get(route('welcome', ['utm_source' => $value]))
            ->assertOk()
            ->assertSessionHas('utm_attribution.source', str_repeat('a', 100));
    }

    public function test_visiting_without_campaign_parameters_does_not_create_attribution(): void
    {
        $this->get(route('welcome'))
            ->assertOk()
            ->assertSessionMissing('utm_attribution');
    }
}
