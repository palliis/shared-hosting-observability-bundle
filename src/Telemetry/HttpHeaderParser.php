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

        foreach ($this->splitHeaders($headers) as $header) {
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

    /**
     * @return string[]
     */
    private function splitHeaders(string $headers): array
    {
        $parts = [];
        $current = '';
        $length = strlen($headers);

        for ($i = 0; $i < $length; ++$i) {
            $char = $headers[$i];
            if ('\\' === $char && $i + 1 < $length && (';' === $headers[$i + 1] || '\\' === $headers[$i + 1])) {
                $current .= $headers[$i + 1];
                ++$i;

                continue;
            }

            if (';' === $char) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;

        return $parts;
    }
}
