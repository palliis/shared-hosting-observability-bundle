<?php

namespace Palliis\SharedHostingObservabilityBundle\Tests\Telemetry;

use Palliis\SharedHostingObservabilityBundle\Telemetry\HttpHeaderParser;
use PHPUnit\Framework\TestCase;

final class HttpHeaderParserTest extends TestCase
{
    public function testParsesEscapedSemicolonInHeaderValue(): void
    {
        $headers = (new HttpHeaderParser())->parse('X-Scope-OrgID=tenant\;a;Authorization=Bearer token');

        self::assertSame('tenant;a', $headers['X-Scope-OrgID']);
        self::assertSame('Bearer token', $headers['Authorization']);
    }
}
