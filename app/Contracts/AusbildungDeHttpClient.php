<?php

namespace App\Contracts;

interface AusbildungDeHttpClient
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function get(string $url, array $options = []): string;
}
