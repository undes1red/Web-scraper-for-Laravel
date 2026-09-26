<?php

namespace Jez500\WebScraperForLaravel\tests\Unit;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Jez500\WebScraperForLaravel\WebScraperHttp;
use Orchestra\Testbench\TestCase;

class WebScraperHttpCookieTest extends TestCase
{
    public function test_real_request_sends_and_rotates_cookie_jar(): void
    {
        [$process, $pipes, $port] = $this->startServer();

        try {
            $jar = new CookieJar(false, [new SetCookie([
                'Name' => 'session',
                'Value' => 'initial',
                'Domain' => '127.0.0.1',
                'Path' => '/',
                'HostOnly' => true,
            ])]);

            $scraper = (new WebScraperHttp)
                ->setUseCache(false)
                ->setCookieJar($jar)
                ->from("http://127.0.0.1:{$port}/")
                ->get();

            $this->assertSame('session=initial', $scraper->getBody());
            $this->assertSame([], $scraper->getErrors());
            $this->assertCount(1, $jar);
            $cookie = $jar->getCookieByName('session');
            $this->assertNotNull($cookie);
            $this->assertSame('rotated', $cookie->getValue());
            $this->assertTrue($cookie->getHostOnly());
            $this->assertTrue($cookie->getHttpOnly());
        } finally {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }
    }

    /** @return array{resource, array<int, resource>, int} */
    private function startServer(): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        $this->assertNotFalse($socket, $errorMessage);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr(strrchr($address, ':'), 1);

        $pipes = [];
        $process = proc_open([
            PHP_BINARY,
            '-S',
            "127.0.0.1:{$port}",
            __DIR__.'/../Fixtures/cookie-server.php',
        ], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        $this->assertIsResource($process);

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $connection = @fsockopen('127.0.0.1', $port);
            if (is_resource($connection)) {
                fclose($connection);

                return [$process, $pipes, $port];
            }
            usleep(20_000);
        }

        $this->fail('Local cookie test server did not start');
    }
}
