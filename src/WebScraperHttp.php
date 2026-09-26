<?php

namespace Jez500\WebScraperForLaravel;

use Illuminate\Http\Client\PendingRequest;
use Throwable;

class WebScraperHttp extends AbstractWebScraper
{
    public function getRequest(): PendingRequest
    {
        return parent::getRequest()->withHeaders($this->buildHeaders());
    }

    public function get(): self
    {
        $this->body = $this->fetchWithCache(function (): ?string {
            try {
                $response = $this->getRequest()->get($this->url);
                if (! $response->successful()) {
                    $this->errors[] = [
                        'message' => 'Web request failed',
                        'code' => $response->status(),
                    ];

                    return null;
                }

                return $response->body();
            } catch (Throwable $e) {
                $this->errors[] = [
                    'message' => 'Web request failed',
                    'code' => $e->getCode(),
                ];
            }

            return null;
        });

        return $this;
    }
}
