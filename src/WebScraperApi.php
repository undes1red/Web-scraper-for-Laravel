<?php

namespace Jez500\WebScraperForLaravel;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

class WebScraperApi extends AbstractWebScraper
{
    public static string $scraperApiUrl = 'http://scraper:3000/api/article';

    protected bool $scraperApiBaseUrlConfigured = false;

    protected array $defaultRequestParams = [
        'sleep' => 2000,
        'full-content' => true,
        'device' => 'Desktop Chrome',
        'wait-until' => 'domcontentloaded',
        'timeout' => 30000,
        'cache' => false, // We cache in this app.
    ];

    public function setScraperApiBaseUrl(string $scraperApiBaseUrl): self
    {
        static::$scraperApiUrl = trim($scraperApiBaseUrl, '/').'/api/article';
        $this->scraperApiBaseUrlConfigured = true;

        return $this;
    }

    public function getScraperApiBaseUrl(): string
    {
        return static::$scraperApiUrl;
    }

    protected function hasMutableRequestContext(): bool
    {
        return $this->scraperApiBaseUrlConfigured;
    }

    public function getRequest(): PendingRequest
    {
        $headers = $this->scraperApiToken === null
            ? []
            : ['Authorization' => 'Bearer '.$this->scraperApiToken];

        return Http::withHeaders($headers)
            ->connectTimeout($this->getConnectTimeout())
            ->timeout($this->getRequestTimeout());
    }

    public function get(): self
    {
        $this->body = $this->fetchWithCache(function (): ?string {
            try {
                $result = $this->usesSensitiveRequest()
                    ? $this->getRequest()->post(
                        $this->getScraperApiBaseUrl(),
                        $this->getBrowserRequestParams()
                    )
                    : $this->getRequest()->get(
                        $this->getScraperApiBaseUrl(),
                        $this->getRequestParams()
                    );

                if (! $result->successful()) {
                    $this->errors[] = [
                        'message' => 'Scraper API request failed',
                        'code' => $result->status(),
                    ];

                    return null;
                }

                $json = $result->json();
                if ($this->cookieJarConfigured && is_array($json) && array_key_exists('cookies', $json)) {
                    $this->replaceCookieJar($json['cookies']);
                }

                $fullContent = data_get($json, 'fullContent', '');
                if (! $fullContent) {
                    $this->errors[] = [
                        'message' => 'No content found',
                        'code' => 204,
                    ];

                    return null;
                }

                return $fullContent;
            } catch (Throwable $e) {
                $this->errors[] = [
                    'message' => 'Scraper API request failed',
                    'code' => $e->getCode(),
                ];
            }

            return null;
        });

        return $this;
    }

    /**
     * Kept for callers that inspect the legacy request representation.
     * Sensitive browser requests use getBrowserRequestParams() and POST JSON.
     */
    public function getRequestParams(): array
    {
        $defaultParams = $this->defaultRequestParams;
        $defaultParams['timeout'] = $this->getRequestTimeout() * 1000;

        if ($this->cookies && ! array_key_exists('extra-http-headers', $this->getOptions())) {
            $defaultParams['extra-http-headers'] = "Cookie:{$this->cookies}";
        }

        $params = array_merge(['url' => $this->url], $defaultParams, $this->getOptions());
        $params['url'] = $this->url;

        return $params;
    }

    protected function usesSensitiveRequest(): bool
    {
        if ($this->cookieJarConfigured || $this->cookies !== '') {
            return true;
        }

        return array_intersect(
            ['http-credentials', 'extra-http-headers', 'cookies', 'return-cookies'],
            array_keys($this->getOptions())
        ) !== [];
    }

    protected function getBrowserRequestParams(): array
    {
        $params = array_merge(
            $this->defaultRequestParams,
            ['timeout' => $this->getRequestTimeout() * 1000],
            $this->getOptions()
        );
        $params['url'] = $this->url;

        if ($this->cookieJarConfigured) {
            $params['cookies'] = $this->getBrowserCookies();
            $params['return-cookies'] = true;
        } elseif ($this->cookies !== '') {
            $params['cookies'] = $this->getRawCookies();
        }

        return $params;
    }

    protected function getRawCookies(): array
    {
        $host = (string) parse_url((string) $this->url, PHP_URL_HOST);
        $secure = parse_url((string) $this->url, PHP_URL_SCHEME) === 'https';

        return array_values(array_filter(array_map(function (string $cookie) use ($host, $secure): ?array {
            $parts = explode('=', trim($cookie), 2);
            if (count($parts) !== 2 || trim($parts[0]) === '') {
                return null;
            }

            return [
                'name' => trim($parts[0]),
                'value' => $parts[1],
                'domain' => $host,
                'path' => '/',
                'hostOnly' => true,
                'secure' => $secure,
                'httpOnly' => false,
                'session' => true,
            ];
        }, explode(';', $this->cookies))));
    }

    protected function getBrowserCookies(): array
    {
        if ($this->cookieJar === null) {
            return [];
        }

        return array_map(function (SetCookie $cookie): array {
            $data = $cookie->toArray();
            $result = [
                'name' => $data['Name'] ?? '',
                'value' => $data['Value'] ?? '',
                'domain' => (string) ($data['Domain'] ?? ''),
                'path' => $data['Path'] ?? '/',
                'hostOnly' => $cookie->getHostOnly(),
                'secure' => (bool) ($data['Secure'] ?? false),
                'httpOnly' => (bool) ($data['HttpOnly'] ?? false),
                'session' => (bool) ($data['Discard'] ?? (($data['Expires'] ?? null) === null)),
            ];

            foreach ([
                'Expires' => 'expires',
                'SameSite' => 'sameSite',
                'Priority' => 'priority',
                'SourceScheme' => 'sourceScheme',
                'SourcePort' => 'sourcePort',
                'PartitionKey' => 'partitionKey',
            ] as $setCookieKey => $browserKey) {
                if (array_key_exists($setCookieKey, $data) && $data[$setCookieKey] !== null) {
                    $result[$browserKey] = $data[$setCookieKey];
                }
            }

            return $result;
        }, iterator_to_array($this->cookieJar));
    }

    protected function replaceCookieJar(mixed $cookies): void
    {
        if ($this->cookieJar === null || ! is_array($cookies) || ! array_is_list($cookies)) {
            return;
        }

        $replacement = new CookieJar;
        $setCookies = [];
        foreach ($cookies as $cookie) {
            if (! is_array($cookie)) {
                return;
            }

            $setCookie = $this->makeSetCookie($cookie);
            if ($setCookie === null || $setCookie->validate() !== true || ! $replacement->setCookie($setCookie)) {
                return;
            }
        }

        // Validate and build the complete snapshot before touching credentials.
        $this->cookieJar->clear();
        foreach ($replacement as $setCookie) {
            $this->cookieJar->setCookie($setCookie);
        }
    }

    protected function makeSetCookie(array $cookie): ?SetCookie
    {
        if (! array_key_exists('name', $cookie) || ! is_string($cookie['name']) || $cookie['name'] === '') {
            return null;
        }
        if (array_key_exists('value', $cookie) && ! is_scalar($cookie['value'])) {
            return null;
        }
        if (array_key_exists('hostOnly', $cookie) && ! is_bool($cookie['hostOnly'])) {
            return null;
        }

        $data = [
            'Name' => $cookie['name'],
            'Value' => (string) ($cookie['value'] ?? ''),
            'Domain' => $cookie['domain'] ?? null,
            'Path' => $cookie['path'] ?? '/',
            'Secure' => (bool) ($cookie['secure'] ?? false),
            'HttpOnly' => (bool) ($cookie['httpOnly'] ?? false),
            'Discard' => (bool) ($cookie['session'] ?? (($cookie['expires'] ?? null) === null)),
        ];

        if (array_key_exists('hostOnly', $cookie)) {
            $data['HostOnly'] = $cookie['hostOnly'];
        }
        if (array_key_exists('expires', $cookie) && $cookie['expires'] !== null
            && ! ($data['Discard'] && (float) $cookie['expires'] < 0)) {
            $data['Expires'] = $cookie['expires'];
        }

        foreach ([
            'sameSite' => 'SameSite',
            'priority' => 'Priority',
            'sourceScheme' => 'SourceScheme',
            'sourcePort' => 'SourcePort',
            'partitionKey' => 'PartitionKey',
        ] as $browserKey => $setCookieKey) {
            if (array_key_exists($browserKey, $cookie) && $cookie[$browserKey] !== null) {
                $data[$setCookieKey] = $cookie[$browserKey];
            }
        }

        try {
            return new SetCookie($data);
        } catch (Throwable) {
            return null;
        }
    }
}
