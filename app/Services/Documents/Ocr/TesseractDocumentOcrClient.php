<?php

namespace App\Services\Documents\Ocr;

use App\Contracts\DocumentOcrClient;
use Symfony\Component\Process\Process;

final class TesseractDocumentOcrClient implements DocumentOcrClient
{
    public function __construct(
        private readonly string $binary = 'tesseract',
        private readonly int $timeoutSeconds = 60,
    ) {
    }

    public function isAvailable(): bool
    {
        $process = new Process([$this->binary, '--version']);
        $process->setTimeout(5);

        try {
            $process->run();
        } catch (\Throwable) {
            return false;
        }

        return $process->isSuccessful();
    }

    public function recognizeFile(string $absolutePath, array $languages = ['deu', 'eng']): string
    {
        if (! is_file($absolutePath)) {
            return '';
        }

        $lang = implode('+', array_values(array_filter($languages)));
        if ($lang === '') {
            $lang = 'deu+eng';
        }

        $process = new Process([
            $this->binary,
            $absolutePath,
            'stdout',
            '-l',
            $lang,
            '--psm',
            '6',
        ]);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (\Throwable) {
            return '';
        }

        if (! $process->isSuccessful()) {
            return '';
        }

        return trim($process->getOutput());
    }
}
