<?php

namespace Tests\Unit;

use App\Services\Network\ListParser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ListParserTest extends TestCase
{
    public function test_lines_format_ignores_comments_blank_lines_and_trailing_notes(): void
    {
        $body = <<<'TXT'
        # firehol_level1
        ; Spamhaus DROP

        1.10.16.0/20 ; SBL256894
        2.57.122.0/24   # nota
        198.51.100.7
        2001:db8::/32
        TXT;

        $this->assertSame(
            ['1.10.16.0/20', '2.57.122.0/24', '198.51.100.7', '2001:db8::/32'],
            ListParser::parse('lines', $body)
        );
    }

    public function test_lines_format_accepts_windows_line_endings(): void
    {
        $this->assertSame(
            ['198.51.100.1', '198.51.100.2'],
            ListParser::parse('lines', "198.51.100.1\r\n198.51.100.2\r\n")
        );
    }

    public function test_aws_format_keeps_only_the_requested_services(): void
    {
        $body = json_encode([
            'syncToken' => '1',
            'prefixes' => [
                ['ip_prefix' => '3.5.140.0/22', 'region' => 'ap-northeast-2', 'service' => 'AMAZON'],
                ['ip_prefix' => '13.34.0.0/16', 'region' => 'us-east-1', 'service' => 'EC2'],
                ['ip_prefix' => '52.94.0.0/22', 'region' => 'us-east-1', 'service' => 'CLOUDFRONT'],
            ],
            'ipv6_prefixes' => [
                ['ipv6_prefix' => '2600:1f00::/24', 'region' => 'us-east-1', 'service' => 'EC2'],
                ['ipv6_prefix' => '2600:9000::/28', 'region' => 'GLOBAL', 'service' => 'CLOUDFRONT'],
            ],
        ]);

        $this->assertSame(
            ['13.34.0.0/16', '2600:1f00::/24'],
            ListParser::parse('aws', $body, ['services' => ['EC2']])
        );
    }

    public function test_google_format_reads_both_families(): void
    {
        $body = json_encode([
            'syncToken' => '1',
            'prefixes' => [
                ['ipv4Prefix' => '34.80.0.0/15', 'service' => 'Google Cloud', 'scope' => 'asia-east1'],
                ['ipv6Prefix' => '2600:1900:4000::/44', 'service' => 'Google Cloud', 'scope' => 'us-west1'],
            ],
        ]);

        $this->assertSame(
            ['34.80.0.0/15', '2600:1900:4000::/44'],
            ListParser::parse('google', $body)
        );
    }

    public function test_json_formats_reject_invalid_json(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ListParser::parse('aws', '<html>erro</html>');
    }

    public function test_unknown_format_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ListParser::parse('xml', '');
    }
}
