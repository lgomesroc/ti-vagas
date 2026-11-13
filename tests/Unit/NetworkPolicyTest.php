<?php

namespace Tests\Unit;

use App\Services\Network\NetworkAction;
use App\Services\Network\NetworkCategory;
use App\Services\Network\NetworkPolicy;
use App\Services\Network\NetworkSignal;
use App\Services\Network\SignalConfidence;
use PHPUnit\Framework\TestCase;

class NetworkPolicyTest extends TestCase
{
    private function signal(NetworkCategory $category, SignalConfidence $confidence, string $source = 'lista'): NetworkSignal
    {
        return new NetworkSignal($category, $source, $confidence);
    }

    public function test_default_actions_follow_the_access_policy(): void
    {
        $policy = new NetworkPolicy;

        $this->assertSame(NetworkAction::Allow, $policy->actionFor(NetworkCategory::Ordinary));
        $this->assertSame(NetworkAction::Evaluate, $policy->actionFor(NetworkCategory::Vpn));
        $this->assertSame(NetworkAction::Restrict, $policy->actionFor(NetworkCategory::AnonymousProxy));
        $this->assertSame(NetworkAction::Restrict, $policy->actionFor(NetworkCategory::Tor));
        $this->assertSame(NetworkAction::Evaluate, $policy->actionFor(NetworkCategory::Datacenter));
        $this->assertSame(NetworkAction::Block, $policy->actionFor(NetworkCategory::Abusive));
    }

    public function test_ordinary_networks_are_always_allowed_whatever_the_configuration_says(): void
    {
        $policy = new NetworkPolicy(['ordinary' => 'block']);

        $this->assertSame(NetworkAction::Allow, $policy->actionFor(NetworkCategory::Ordinary));
    }

    public function test_configuration_overrides_defaults_and_invalid_values_fall_back(): void
    {
        $policy = new NetworkPolicy(['tor' => 'BLOCK', 'vpn' => 'restrict', 'proxy' => 'banana', 'datacenter' => 42]);

        $this->assertSame(NetworkAction::Block, $policy->actionFor(NetworkCategory::Tor));
        $this->assertSame(NetworkAction::Restrict, $policy->actionFor(NetworkCategory::Vpn));
        $this->assertSame(NetworkAction::Restrict, $policy->actionFor(NetworkCategory::AnonymousProxy));
        $this->assertSame(NetworkAction::Evaluate, $policy->actionFor(NetworkCategory::Datacenter));
    }

    public function test_no_signals_means_an_ordinary_network(): void
    {
        $assessment = (new NetworkPolicy)->assess([]);

        $this->assertTrue($assessment->isOrdinary());
        $this->assertSame(NetworkAction::Allow, $assessment->action);
    }

    public function test_confidence_caps_what_a_single_signal_can_do(): void
    {
        $policy = new NetworkPolicy(['abusive' => 'block', 'tor' => 'block']);

        $high = $policy->assess([$this->signal(NetworkCategory::Abusive, SignalConfidence::High)]);
        $medium = $policy->assess([$this->signal(NetworkCategory::Abusive, SignalConfidence::Medium)]);
        $low = $policy->assess([$this->signal(NetworkCategory::Tor, SignalConfidence::Low)]);

        $this->assertSame(NetworkAction::Block, $high->action);
        $this->assertSame(NetworkAction::Restrict, $medium->action);
        $this->assertSame(NetworkAction::Evaluate, $low->action);
    }

    public function test_the_strictest_signal_wins(): void
    {
        $assessment = (new NetworkPolicy)->assess([
            $this->signal(NetworkCategory::Datacenter, SignalConfidence::High, 'aws'),
            $this->signal(NetworkCategory::Vpn, SignalConfidence::Medium, 'vpn'),
            $this->signal(NetworkCategory::AnonymousProxy, SignalConfidence::Medium, 'proxy'),
        ]);

        $this->assertSame(NetworkCategory::AnonymousProxy, $assessment->category);
        $this->assertSame(NetworkAction::Restrict, $assessment->action);
        $this->assertSame('proxy', $assessment->primary?->source);
        $this->assertSame(['aws', 'vpn', 'proxy'], $assessment->sources());
    }

    public function test_ties_go_to_higher_confidence_then_to_the_more_specific_category(): void
    {
        $policy = new NetworkPolicy;

        $byConfidence = $policy->assess([
            $this->signal(NetworkCategory::Datacenter, SignalConfidence::Medium, 'comunitaria'),
            $this->signal(NetworkCategory::Datacenter, SignalConfidence::High, 'aws'),
        ]);

        $byCategory = $policy->assess([
            $this->signal(NetworkCategory::Datacenter, SignalConfidence::Medium, 'dc'),
            $this->signal(NetworkCategory::Vpn, SignalConfidence::Medium, 'vpn'),
        ]);

        $this->assertSame('aws', $byConfidence->primary?->source);
        $this->assertSame(NetworkCategory::Vpn, $byCategory->category);
    }

    public function test_a_category_set_to_allow_is_still_labeled_but_not_acted_on(): void
    {
        $assessment = (new NetworkPolicy(['vpn' => 'allow']))
            ->assess([$this->signal(NetworkCategory::Vpn, SignalConfidence::Medium)]);

        $this->assertSame(NetworkCategory::Vpn, $assessment->category);
        $this->assertSame(NetworkAction::Allow, $assessment->action);
    }
}
