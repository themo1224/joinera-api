<?php

namespace App\Services\Docs;

class WrapDocHtml
{
    public function handle(string $title, string $bodyText): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $paragraphs = preg_split("/\R{2,}/", trim($bodyText)) ?: [trim($bodyText)];
        $htmlParts = [];

        foreach ($paragraphs as $paragraph) {
            $safe = nl2br(htmlspecialchars(trim($paragraph), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            if ($safe === '') {
                continue;
            }
            $htmlParts[] = '<p>'.$safe.'</p>';
        }

        $body = $htmlParts === [] ? '<p></p>' : implode("\n", $htmlParts);

        return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<style>
body { font-family: DejaVu Sans, sans-serif; font-size: 12pt; line-height: 1.4; color: #111; }
h1 { font-size: 16pt; margin: 0 0 16px; }
p { margin: 0 0 10px; }
</style>
</head>
<body>
<h1>{$safeTitle}</h1>
{$body}
</body>
</html>
HTML;
    }
}
