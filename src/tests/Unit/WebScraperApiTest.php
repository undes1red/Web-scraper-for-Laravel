<?php

namespace Jez500\WebScraperForLaravel\tests\Unit;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Jez500\WebScraperForLaravel\Enums\ScraperServicesEnum;
use Jez500\WebScraperForLaravel\Facades\WebScraper;
use Jez500\WebScraperForLaravel\WebScraperApi;

class WebScraperApiTest extends WebScraperTest
{
    protected string $scraperName = ScraperServicesEnum::Api->value;

    protected string $expectedClass = WebScraperApi::class;

    protected string $mockResponseFile = 'api-response.json';

    public function test_can_get_body()
    {
        $body = $this->getScraper()->from('https://example.com/')->get()->getBody();
        $this->assertSame(data_get(json_decode(parent::getMockResponse()), 'fullContent'), $body);
    }

    public function test_can_set_base_url()
    {
        $scraper = WebScraper::api();
        $baseUrl = 'https://test-scraper-host';

        $scraper->setScraperApiBaseUrl($baseUrl); // @phpstan-ignore-line

        Http::fake([
            'test-scraper-host/*' => Http::response('{"fullContent": "test"}', 200),
        ]);

        $this->assertSame($baseUrl.'/api/article', $scraper->getScraperApiBaseUrl()); // @phpstan-ignore-line
        $this->assertSame('test', $scraper->from('http://foo.bar')->get()->getBody());
    }

    public function test_can_set_cookies()
    {
        $scraper = new WebScraperApi;
        $cookies = 'cookie1=value1; cookie2=value2';
        $scraper->setCookies($cookies);
        $reqParams = $scraper->getRequestParams();
        $this->assertArrayHasKey('extra-http-headers', $reqParams);
        $this->assertSame($reqParams['extra-http-headers'], "Cookie:$cookies");
    }

    public function test_cookies_dont_override_extra_headers()
    {
        $scraper = new WebScraperApi;
        $cookies = 'cookie1=value1; cookie2=value2';
        $scraper->setOptions(['extra-http-headers' => 'X-Test-Header: test-value']);
        $scraper->setCookies($cookies);
        $reqParams = $scraper->getRequestParams();
        $this->assertSame($reqParams['extra-http-headers'], 'X-Test-Header: test-value');
    }

    public function test_ordinary_requests_keep_legacy_get(): void
    {
        $scraper = $this->getScraper()->from('https://example.com/article');
        $scraper->get();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->data()['url'] === 'https://example.com/article');
    }

    public function test_sensitive_request_is_post_json_without_secret_in_url(): void
    {
        $secret = 'session-secret';
        $this->getScraper()
            ->setCookies('session='.$secret)
            ->from('https://example.com/account')
            ->get();

        Http::assertSent(function (Request $request) use ($secret): bool {
            $data = $request->data();

            return $request->method() === 'POST'
                && ! str_contains($request->url(), $secret)
                && $data['url'] === 'https://example.com/account'
                && $data['cookies'][0]['value'] === $secret;
        });
    }

    public function test_cookie_payload_omits_null_optional_values(): void
    {
        $this->getScraper()
            ->setCookies('session=value')
            ->from('https://example.com/account')
            ->get();

        Http::assertSent(function (Request $request): bool {
            $cookie = $request->data()['cookies'][0];

            return ! array_key_exists('sameSite', $cookie)
                && ! array_key_exists('expires', $cookie);
        });
    }

    public function test_structured_cookies_preserve_duplicates_and_host_only(): void
    {
        $jar = new CookieJar;
        $hostOnly = new SetCookie([
            'Name' => 'session', 'Value' => 'host', 'Domain' => 'example.com',
            'Path' => '/', 'HostOnly' => true, 'SameSite' => 'Lax',
            'Priority' => 'High', 'SourceScheme' => 'Secure', 'SourcePort' => 443,
            'PartitionKey' => 'https://example.com',
        ]);
        $domainCookie = new SetCookie([
            'Name' => 'session', 'Value' => 'domain', 'Domain' => 'example.com',
            'Path' => '/', 'HostOnly' => false,
        ]);
        $jar->setCookie($hostOnly);
        $jar->setCookie($domainCookie);

        $this->getScraper()->setCookieJar($jar)->from('https://example.com/account')->get();

        Http::assertSent(function (Request $request): bool {
            $cookies = $request->data()['cookies'];

            return count($cookies) === 2
                && $cookies[0]['hostOnly'] === true
                && $cookies[1]['hostOnly'] === false
                && $cookies[0]['sameSite'] === 'Lax'
                && $cookies[0]['priority'] === 'High'
                && $cookies[0]['sourceScheme'] === 'Secure'
                && $cookies[0]['sourcePort'] === 443
                && $cookies[0]['partitionKey'] === 'https://example.com'
                && ! array_key_exists('sameSite', $cookies[1]);
        });
    }

    public function test_returned_cookie_snapshot_replaces_and_deletes_jar(): void
    {
        $jar = CookieJar::fromArray(['old' => 'value'], 'example.com');
        $scraper = new WebScraperApi;
        $scraper->setUseCache(false);
        $scraper->setCookieJar($jar);
        $scraper->setScraperApiBaseUrl('https://cookies.test');
        Http::fakeSequence('cookies.test/*')
            ->push(['fullContent' => 'first', 'cookies' => [[
                'name' => 'new', 'value' => 'value', 'domain' => 'example.com',
                'path' => '/', 'hostOnly' => true, 'sameSite' => 'Strict',
                'priority' => 'Low', 'sourceScheme' => 'Secure', 'sourcePort' => 443,
                'partitionKey' => 'https://example.com',
            ]]])
            ->push(['fullContent' => 'second', 'cookies' => []]);

        $scraper->from('https://example.com/one');
        $scraper->get();
        $cookies = $jar->toArray();
        $this->assertCount(1, $cookies);
        $this->assertSame('new', $cookies[0]['Name']);
        $this->assertTrue($cookies[0]['HostOnly']);
        $this->assertSame('Strict', $cookies[0]['SameSite']);
        $this->assertSame('Low', $cookies[0]['Priority']);
        $this->assertSame('Secure', $cookies[0]['SourceScheme']);
        $this->assertSame(443, $cookies[0]['SourcePort']);
        $this->assertSame('https://example.com', $cookies[0]['PartitionKey']);

        $scraper->from('https://example.com/two')->get();
        $this->assertCount(0, $jar);
    }

    public function test_chrome_session_cookie_with_minus_one_expiry_is_not_discarded(): void
    {
        $jar = new CookieJar;
        Http::fake(['session.test/*' => Http::response([
            'fullContent' => 'body',
            'cookies' => [[
                'name' => 'session', 'value' => 'rotated', 'domain' => 'example.com',
                'path' => '/', 'hostOnly' => true, 'session' => true, 'expires' => -1,
            ]],
        ])]);

        (new WebScraperApi)->setScraperApiBaseUrl('https://session.test')
            ->setCookieJar($jar)->from('https://example.com')->get();

        $cookie = $jar->getCookieByName('session');
        $this->assertNotNull($cookie);
        $this->assertSame('rotated', $cookie->getValue());
        $this->assertTrue($cookie->getDiscard());
        $this->assertNull($cookie->getExpires());
    }

    public function test_malformed_cookie_snapshot_does_not_clear_jar(): void
    {
        $jar = CookieJar::fromArray(['old' => 'value'], 'example.com');
        Http::fake(['scraper:3000/*' => Http::response([
            'fullContent' => 'body', 'cookies' => [['value' => 'missing-name']],
        ])]);

        $this->getScraper()->setCookieJar($jar)->from('https://example.com')->get();

        $this->assertCount(1, $jar);
        $this->assertSame('old', $jar->toArray()[0]['Name']);
    }

    public function test_bearer_token_is_sent_and_disables_cache(): void
    {
        $token = 'api-secret';
        $scraper = $this->getScraper()->setScraperApiToken($token);
        $this->assertFalse($scraper->shouldUseCache());
        $scraper->from('https://example.com')->get();

        Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer '.$token]);
    }

    public function test_sensitive_options_disable_cache_and_use_post(): void
    {
        foreach ([
            ['options' => [], 'cookies' => null, 'jar' => new CookieJar],
            ['options' => [], 'cookies' => 'raw=secret', 'jar' => null],
            ['options' => ['http-credentials' => ['username' => 'secret']], 'cookies' => null, 'jar' => null],
            ['options' => ['extra-http-headers' => 'X-Secret: value'], 'cookies' => null, 'jar' => null],
            ['options' => ['cookies' => [], 'return-cookies' => true], 'cookies' => null, 'jar' => null],
        ] as $case) {
            $scraper = $this->getScraper()->setOptions($case['options']);
            if ($case['cookies'] !== null) {
                $scraper->setCookies($case['cookies']);
            }
            if ($case['jar'] !== null) {
                $scraper->setCookieJar($case['jar']);
            }
            $this->assertFalse($scraper->shouldUseCache());
        }
    }

    public function test_each_sensitive_option_uses_post_body_only(): void
    {
        $secret = 'request-secret';
        $cases = [
            ['http-credentials' => ['username' => 'reader', 'password' => $secret]],
            ['extra-http-headers' => ['X-Secret' => $secret]],
            ['cookies' => [[
                'name' => 'session', 'value' => $secret,
                'domain' => 'example.com', 'path' => '/',
            ]]],
        ];

        foreach ($cases as $options) {
            Http::fake([
                'scraper:3000/*' => Http::response(['fullContent' => 'fresh']),
            ]);
            Cache::put(
                'web_scraper:WebScraperApi:'.md5('https://example.com/account'),
                'stale',
            );

            $scraper = $this->getScraper()
                ->setOptions($options)
                ->from('https://example.com/account')
                ->get();

            $this->assertNotSame('stale', $scraper->getBody());
            Http::assertSentCount(1);
            Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
                && ! str_contains($request->url(), $secret)
                && str_contains(json_encode($request->data(), JSON_THROW_ON_ERROR), $secret));
        }
    }

    public function test_url_option_cannot_override_target(): void
    {
        $scraper = $this->getScraper()
            ->setOptions(['url' => 'https://attacker.invalid/secret'])
            ->from('https://example.com/target');
        $scraper->get();

        Http::assertSent(fn (Request $request): bool => $request->data()['url'] === 'https://example.com/target');
    }

    public function test_non_success_errors_are_sanitized(): void
    {
        Http::fake(['scraper:3000/*' => Http::response([
            'fullContent' => 'body-secret', 'params' => 'params-secret',
        ], 500)]);
        $scraper = $this->getScraper()->setCookies('session=secret')->from('https://example.com')->get();
        $errors = json_encode($scraper->getErrors());

        $this->assertStringNotContainsString('secret', $errors);
        $this->assertStringNotContainsString('request_params', $errors);
        $this->assertStringNotContainsString('body-secret', $errors);
    }

    public function test_from_preserves_configuration_but_resets_response_state(): void
    {
        $jar = new CookieJar;
        $scraper = new WebScraperApi;
        $scraper->setUseCache(false);
        $scraper->setScraperApiBaseUrl('https://state.test');
        Http::fake(['state.test/*' => Http::response(['fullContent' => ''])]);
        $scraper = $scraper
            ->setOptions(['device' => 'Desktop'])
            ->setCookieJar($jar)
            ->setScraperApiToken('token')
            ->setConnectTimeout(11)
            ->setRequestTimeout(12)
            ->from('https://example.com/old')
            ->get();
        $this->assertNotEmpty($scraper->getErrors());
        $scraper->setBody('stale-body')->from('https://example.com/old');

        $this->assertSame('', $scraper->getBody());
        $this->assertSame([], $scraper->getErrors());
        $this->assertSame(['device' => 'Desktop'], $scraper->getOptions());
        $this->assertSame($jar, $scraper->getCookieJar());
        $this->assertSame('token', $scraper->getScraperApiToken());
        $this->assertSame(11, $scraper->getConnectTimeout());
        $this->assertSame(12, $scraper->getRequestTimeout());
    }

    protected function setupMocks(): void
    {
        WebScraperApi::$scraperApiUrl = 'http://scraper:3000/api/article';
        Http::fake([
            'scraper:3000/*' => Http::response($this->getMockResponse()),
        ]);
    }
}
