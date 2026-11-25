<?php

namespace Webid\Laravel;

use InvalidArgumentException;

/**
 * The site's connection key (WEBID_KEY=wk_…): webid's address (the issuer), the site's client_id and its secret in
 * one line, as webid shows it.
 */
final class ConnectionKey
{
    private function __construct(
        public readonly string $issuer,
        public readonly string $clientId,
        public readonly string $clientSecret,
    ) {}

    public static function parse(?string $key): self
    {
        if (! is_string($key) || ! str_starts_with($key, 'wk_')) {
            throw new InvalidArgumentException('WEBID_KEY is missing or is not a webid connection key (wk_…).');
        }
        $data = json_decode((string) base64_decode(strtr(substr($key, 3), '-_', '+/'), true), true);
        if (! is_array($data) || ! isset($data['iss'], $data['client_id'], $data['client_secret'])) {
            throw new InvalidArgumentException('WEBID_KEY is damaged: copy it again from webid.');
        }

        return new self(rtrim((string) $data['iss'], '/'), (string) $data['client_id'], (string) $data['client_secret']);
    }

    /**
     * webid's API address: the issuer with "api." in front of its host (https://webid.am → https://api.webid.am).
     */
    public function apiUrl(): string
    {
        $parts = parse_url($this->issuer);

        return ($parts['scheme'] ?? 'https').'://api.'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
