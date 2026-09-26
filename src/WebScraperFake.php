<?php

namespace Jez500\WebScraperForLaravel;

class WebScraperFake extends AbstractWebScraper
{
    public function from(string $url): self
    {
        $this->setUrl($url);

        return $this;
    }

    public function get(): self
    {
        return $this;
    }
}
