<?php

namespace App\Contracts;

interface ArbeitsagenturDeHttpClient
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function get(string $url, array $options = []): string;
}
