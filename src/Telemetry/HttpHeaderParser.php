<?php

namespace Palliis\SharedHostingObservabilityBundle\Telemetry;

final class HttpHeaderParser
{
    /**
     * Parses semicolon-separated headers: "Authorization=Bearer token;X-Scope-OrgID=tenant".
     *
     * @return array<string, string>
     */
    public function parse(string $headers): array
    {
        $parsed = [];

        foreach (explode(';', $headers) as $header) {
            $header = trim($header);
            if ('' === $header || !str_contains($header, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $header, 2);
            $name = trim($name);
            if ('' === $name) {
                continue;
            }

            $parsed[$name] = trim($value);
        }

        return $parsed;
    }
}
