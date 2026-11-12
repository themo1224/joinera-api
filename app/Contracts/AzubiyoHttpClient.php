<?php

namespace App\Contracts;

interface AzubiyoHttpClient
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function get(string $url, array $options = []): string;
}
