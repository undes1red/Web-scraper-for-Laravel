# Web Scraper for Laravel

A package to make it easier to scrape external web pages using laravel.

## Key Features
* Support for standard HTTP requests (Using Laravel's HTTP client)
* Support for scraping javascript rendered pages (Using https://github.com/amerkurev/scrapper)
* Rotating user agents to avoid being blocked
* Support for extracting data using CSS selectors
* Support for extracting data using XPath expressions
* Support for extracting data using dot notation from JSON responses
* Support for extracting data using regular expressions
* Caching responses to avoid repeated requests

## Usage examples

```php
use Jez500\WebScraperForLaravel\Facades\WebScraper;

// Get an instance of the scraper with the body of the page loaded.
$scraper = WebScraper::http()->from('https://example.com')->get();

// Get the full page body
$body = $scraper->getBody();

// Get the first title element
$title = $scraper->getSelector('title')->first(); 

// Get the content attribute of the first meta tag with property og:image
$image = $scraper->getSelector('meta[property=og:image]|content')->first(); 

// Get all paragraph innerHtml as an array
$links = $scraper->getSelector('p')->all();

// Get the first h1 element using XPath
$h1 = $scraper->getXpath('//h1')->first();

// Get the href attribute of the first link using XPath
$linkHref = $scraper->getXpath('//a', 'attr', ['href'])->first();
 
 // Get values from the page via regex
$author = $scraper->getRegex('~"user"\:"(.*)"~')->first();

// Get JSON data
$author = WebScraper::http()
    ->from('https://example.com/page.json')
    ->get()
    ->getJson('user.name')
    ->first();

// Get title from a javascript rendered page
$title = WebScraper::api()
    ->from('https://example.com')
    ->get()
    ->getSelector('title')
    ->first();
```

## Secure browser sessions

Ordinary API calls retain the legacy GET request. Calls carrying cookies,
credentials, or other sensitive browser state use POST with a JSON body, never
putting that state in the URL. Use a Guzzle cookie jar for a managed browser
session; the returned browser snapshot replaces the jar:

```php
use GuzzleHttp\Cookie\CookieJar;
use Jez500\WebScraperForLaravel\Facades\WebScraper;

$jar = new CookieJar;
$scraper = WebScraper::api()
    ->setCookieJar($jar)
    ->setScraperApiToken('redacted-token')
    ->from('https://example.com/account')
    ->get();
```

For legacy callers, raw cookies remain supported and are converted to
host-only cookies for the target URL:

```php
$scraper = WebScraper::api()
    ->setCookies('session=redacted-value')
    ->from('https://example.com/account')
    ->get();
```

## Installation

```shell
composer require jez500/web-scraper-for-laravel
```

## Contributing

PRs are welcome! It's a good idea to run coding standards and tests locally before submitting a PR.
This can be done with:

```shell
composer analyse
composer test
```

## Author
[Jeremy Graham](https://github.com/jez500)
