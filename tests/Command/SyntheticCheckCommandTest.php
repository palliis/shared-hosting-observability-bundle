<?php

namespace Palliis\SharedHostingObservabilityBundle\Tests\Command;

use Palliis\SharedHostingObservabilityBundle\Command\SyntheticCheckCommand;
use Palliis\SharedHostingObservabilityBundle\Metrics\MetricsRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SyntheticCheckCommandTest extends TestCase
{
    public function testRelativeCheckRequiresConfiguredBaseUrl(): void
    {
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::fail('The synthetic check should fail before making an HTTP request.');
        });

        $command = new SyntheticCheckCommand(
            $httpClient,
            new NullLogger(),
            new MetricsRegistry(new ArrayAdapter()),
            null,
            '',
            ['health' => '/health'],
            '',
            1.0,
        );

        $tester = new CommandTester($command);
        $result = $tester->execute([]);

        self::assertSame(Command::INVALID, $result);
        self::assertStringContainsString('synthetics.base_url', $tester->getDisplay());
    }

    public function testAbsoluteCheckDoesNotRequireBaseUrl(): void
    {
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame('https://example.test/health', $url);

            return new MockResponse('', ['http_code' => 200]);
        });

        $command = new SyntheticCheckCommand(
            $httpClient,
            new NullLogger(),
            new MetricsRegistry(new ArrayAdapter()),
            null,
            '',
            ['health' => 'https://example.test/health'],
            '',
            1.0,
        );

        $result = (new CommandTester($command))->execute([]);

        self::assertSame(Command::SUCCESS, $result);
    }
}
