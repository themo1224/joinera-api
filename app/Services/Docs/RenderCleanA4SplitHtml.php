<?php

namespace App\Services\Docs;

use App\Models\Listing;
use App\Models\Profile;
use App\Models\User;

/**
 * Dompdf-safe HTML for the Clean A4 Split CV / cover layout (Figma Template 3).
 *
 * Uses tables instead of flex/grid for Dompdf 3.x compatibility.
 */
class RenderCleanA4SplitHtml
{
    private const ACCENT = '#C9A227';

    private const TEXT = '#111111';

    private const SECONDARY = '#4B4B4B';

    private const META = '#8E8E8E';

    private const DIVIDER = '#E0E0E0';

    public function cv(User $user, Profile $profile, string $aboutText): string
    {
        $name = $this->e($this->displayName($user));
        $title = $this->e($this->professionalTitle($profile));
        $initials = $this->e($this->initials($this->displayName($user)));
        $about = $this->paragraphsHtml($aboutText !== '' ? $aboutText : (string) ($profile->about ?? ''));

        $email = $this->e((string) ($user->email ?? ''));
        $city = $this->e(trim(implode(', ', array_filter([
            (string) ($profile->city ?? ''),
            (string) ($profile->province ?? ''),
        ]))));

        $experienceHtml = $this->experienceColumn($profile);
        $asideHtml = $this->asideColumn($profile);

        $accent = self::ACCENT;
        $text = self::TEXT;
        $secondary = self::SECONDARY;
        $meta = self::META;
        $divider = self::DIVIDER;

        return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<style>
@page { margin: 18mm 16mm 14mm 16mm; }
body {
  font-family: DejaVu Sans, sans-serif;
  font-size: 9pt;
  line-height: 1.35;
  color: {$text};
  margin: 0;
}
.avatar {
  width: 48px;
  height: 48px;
  background: {$accent};
  color: #fff;
  text-align: center;
  vertical-align: middle;
  font-size: 12pt;
  font-weight: bold;
  border-radius: 24px;
}
.name { font-size: 14pt; font-weight: bold; color: {$text}; margin: 0 0 2px; }
.role { font-size: 9pt; color: {$secondary}; margin: 0; }
.contact { font-size: 7.5pt; color: {$secondary}; line-height: 1.45; }
.about { font-size: 8pt; color: {$secondary}; margin: 14px 0 10px; }
.hr { border: 0; border-top: 1px solid {$divider}; margin: 0 0 12px; }
.col-main { width: 62%; vertical-align: top; padding-right: 14px; }
.col-rule { width: 1px; background: {$divider}; }
.col-aside { width: 34%; vertical-align: top; padding-left: 14px; }
.company { font-size: 8.5pt; margin: 0; }
.company strong { color: {$text}; }
.pipe { color: {$accent}; font-weight: bold; padding: 0 3px; }
.company-desc { color: {$secondary}; font-weight: normal; }
.dates { font-size: 7.5pt; color: {$meta}; margin: 1px 0 4px; }
.job-role { font-size: 8.5pt; font-weight: bold; margin: 0 0 2px; padding-left: 10px; }
.job-body { font-size: 8pt; color: {$secondary}; margin: 0 0 12px; padding-left: 10px; }
.job-short { margin-bottom: 10px; }
.aside-title { font-size: 8.5pt; font-weight: bold; color: {$text}; margin: 0 0 6px; }
.aside-block { margin-bottom: 14px; }
.aside-item { margin: 0 0 8px; font-size: 8pt; color: {$secondary}; }
.aside-item strong { display: block; color: {$text}; font-size: 8pt; }
.aside-item .meta { color: {$meta}; font-size: 7.5pt; }
.footer-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  height: 5px;
  background: {$accent};
}
</style>
</head>
<body>
<div class="footer-bar"></div>
<table width="100%" cellspacing="0" cellpadding="0">
  <tr>
    <td width="56" style="vertical-align: top;">
      <div class="avatar">{$initials}</div>
    </td>
    <td style="vertical-align: middle; padding-left: 12px;">
      <p class="name">{$name}</p>
      <p class="role">{$title}</p>
    </td>
    <td width="38%" style="vertical-align: middle; text-align: right;">
      <div class="contact">
        {$email}<br>
        {$city}
      </div>
    </td>
  </tr>
</table>

<div class="about">{$about}</div>
<hr class="hr">

<table width="100%" cellspacing="0" cellpadding="0">
  <tr>
    <td class="col-main">{$experienceHtml}</td>
    <td class="col-rule">&nbsp;</td>
    <td class="col-aside">{$asideHtml}</td>
  </tr>
</table>
</body>
</html>
HTML;
    }

    public function cover(User $user, Profile $profile, string $bodyText, ?Listing $listing = null): string
    {
        $name = $this->e($this->displayName($user));
        $title = $this->e($this->professionalTitle($profile));
        $initials = $this->e($this->initials($this->displayName($user)));
        $email = $this->e((string) ($user->email ?? ''));
        $city = $this->e((string) ($profile->city ?? ''));
        $date = $this->e(now()->format('d.m.Y'));
        $recipient = $this->e($listing?->company ? (string) $listing->company : 'Personalabteilung');
        $coverTitle = $this->e($this->coverTitle($profile, $listing));
        $body = $this->paragraphsHtml($bodyText);

        $accent = self::ACCENT;
        $text = self::TEXT;
        $secondary = self::SECONDARY;
        $meta = self::META;
        $divider = self::DIVIDER;

        return <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<style>
@page { margin: 18mm 16mm 16mm 16mm; }
body {
  font-family: DejaVu Sans, sans-serif;
  font-size: 10pt;
  line-height: 1.45;
  color: {$text};
  margin: 0;
}
.avatar {
  width: 48px;
  height: 48px;
  background: {$accent};
  color: #fff;
  text-align: center;
  vertical-align: middle;
  font-size: 12pt;
  font-weight: bold;
  border-radius: 24px;
}
.name { font-size: 14pt; font-weight: bold; margin: 0 0 2px; }
.role { font-size: 9pt; color: {$secondary}; margin: 0; }
.contact { font-size: 7.5pt; color: {$secondary}; line-height: 1.45; }
.hr { border: 0; border-top: 1px solid {$divider}; margin: 14px 0; }
.meta { font-size: 8.5pt; color: {$meta}; margin: 0 0 14px; }
.cover-title { font-size: 12pt; font-weight: bold; margin: 0 0 14px; }
.body p { font-size: 9.5pt; color: {$secondary}; margin: 0 0 10px; }
.footer-bar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  height: 5px;
  background: {$accent};
}
</style>
</head>
<body>
<div class="footer-bar"></div>
<table width="100%" cellspacing="0" cellpadding="0">
  <tr>
    <td width="56" style="vertical-align: top;">
      <div class="avatar">{$initials}</div>
    </td>
    <td style="vertical-align: middle; padding-left: 12px;">
      <p class="name">{$name}</p>
      <p class="role">{$title}</p>
    </td>
    <td width="38%" style="vertical-align: middle; text-align: right;">
      <div class="contact">
        {$email}<br>
        {$city}
      </div>
    </td>
  </tr>
</table>

<hr class="hr">
<p class="meta">{$recipient}<br>{$city}, {$date}</p>
<p class="cover-title">{$coverTitle}</p>
<div class="body">{$body}</div>
</body>
</html>
HTML;
    }

    private function experienceColumn(Profile $profile): string
    {
        $entries = is_array($profile->experiences) ? $profile->experiences : [];
        if ($entries === []) {
            return '<p class="dates">Keine Berufserfahrung hinterlegt.</p>';
        }

        $html = '';
        $index = 0;
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $org = $this->str($entry['organization'] ?? null) ?? 'Organisation';
            $loc = $this->str($entry['location'] ?? null);
            $role = $this->str($entry['title'] ?? null) ?? '';
            $desc = $this->str($entry['description'] ?? null) ?? '';
            $end = ! empty($entry['current']) ? 'heute' : ($this->str($entry['end'] ?? null) ?? '');
            $start = $this->str($entry['start'] ?? null) ?? '';
            $period = trim($start.($end !== '' ? ' – '.$end : ''));

            $companyLine = '<p class="company"><strong>'.$this->e($org).'</strong>';
            if ($loc !== null) {
                $companyLine .= '<span class="pipe">|</span><span class="company-desc">'.$this->e($loc).'</span>';
            }
            $companyLine .= '</p>';

            $dates = $period !== '' ? '<p class="dates">'.$this->e($period).'</p>' : '';

            if ($index < 2) {
                $html .= $companyLine.$dates;
                if ($role !== '') {
                    $html .= '<p class="job-role">'.$this->e($role).'</p>';
                }
                if ($desc !== '') {
                    $html .= '<p class="job-body">'.$this->e($desc).'</p>';
                } else {
                    $html .= '<div style="margin-bottom:12px;"></div>';
                }
            } else {
                $html .= '<div class="job-short">'.$companyLine.$dates.'</div>';
            }

            $index++;
        }

        return $html !== '' ? $html : '<p class="dates">Keine Berufserfahrung hinterlegt.</p>';
    }

    private function asideColumn(Profile $profile): string
    {
        $parts = [];

        $eduItems = '';
        foreach (is_array($profile->education_entries) ? $profile->education_entries : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $degree = $this->str($entry['program'] ?? null)
                ?? $this->str($entry['level'] ?? null)
                ?? 'Ausbildung';
            $school = $this->str($entry['institution'] ?? null) ?? '';
            $range = $this->range($this->str($entry['start'] ?? null), $this->str($entry['end'] ?? null)) ?? '';
            $eduItems .= '<div class="aside-item"><strong>'.$this->e($degree).'</strong>';
            if ($range !== '') {
                $eduItems .= '<div class="meta">'.$this->e($range).'</div>';
            }
            if ($school !== '') {
                $eduItems .= '<div>'.$this->e($school).'</div>';
            }
            $eduItems .= '</div>';
        }
        if ($eduItems !== '') {
            $parts[] = '<div class="aside-block"><p class="aside-title">Ausbildung</p>'.$eduItems.'</div>';
        } elseif ($this->str($profile->education ?? null) !== null) {
            $parts[] = '<div class="aside-block"><p class="aside-title">Ausbildung</p><div class="aside-item">'
                .$this->e((string) $profile->education).'</div></div>';
        }

        $skills = [];
        foreach (is_array($profile->skills) ? $profile->skills : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = $this->str($entry['name'] ?? null);
            if ($name === null) {
                continue;
            }
            $level = $this->str($entry['level'] ?? null);
            $skills[] = $level !== null ? $name.' ('.$level.')' : $name;
        }
        if ($skills !== []) {
            $parts[] = '<div class="aside-block"><p class="aside-title">Technische Kenntnisse</p>'
                .'<div class="aside-item">'.$this->e(implode(', ', $skills)).'</div></div>';
        }

        $proBits = array_filter([
            $this->str($profile->preferred_occupation ?? null),
            $this->str($profile->desired_path ?? null),
            $this->str($profile->industry ?? null),
        ]);
        if ($proBits !== []) {
            $parts[] = '<div class="aside-block"><p class="aside-title">Berufliche Kenntnisse</p>'
                .'<div class="aside-item">'.$this->e(implode(', ', $proBits)).'</div></div>';
        }

        $langs = [];
        foreach (is_array($profile->languages) ? $profile->languages : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $language = $this->str($entry['language'] ?? null);
            if ($language === null) {
                continue;
            }
            $level = $this->str($entry['level'] ?? null);
            $langs[] = $level !== null ? $language.' ('.$level.')' : $language;
        }
        if ($langs !== []) {
            $parts[] = '<div class="aside-block"><p class="aside-title">Sprachen</p>'
                .'<div class="aside-item">'.$this->e(implode(', ', $langs)).'</div></div>';
        }

        $licenses = is_array($profile->driving_licenses) ? $profile->driving_licenses : [];
        $licenseLine = implode(', ', array_map('strval', $licenses));
        $note = $this->str($profile->work_residence_note ?? null);
        if ($licenseLine !== '' || $note !== null) {
            $extra = '';
            if ($licenseLine !== '') {
                $extra .= '<div class="aside-item"><strong>Führerschein</strong><div>'.$this->e($licenseLine).'</div></div>';
            }
            if ($note !== null) {
                $extra .= '<div class="aside-item"><strong>Hinweis</strong><div>'.$this->e($note).'</div></div>';
            }
            $parts[] = '<div class="aside-block"><p class="aside-title">Sonstiges</p>'.$extra.'</div>';
        }

        return $parts === [] ? '<p class="dates">—</p>' : implode('', $parts);
    }

    private function paragraphsHtml(string $bodyText): string
    {
        $paragraphs = preg_split("/\R{2,}/", trim($bodyText)) ?: [trim($bodyText)];
        $htmlParts = [];

        foreach ($paragraphs as $paragraph) {
            $safe = nl2br($this->e(trim($paragraph)));
            if ($safe === '') {
                continue;
            }
            $htmlParts[] = '<p>'.$safe.'</p>';
        }

        return $htmlParts === [] ? '<p></p>' : implode("\n", $htmlParts);
    }

    private function displayName(User $user): string
    {
        $name = trim((string) $user->name);

        return $name !== '' ? $name : 'Bewerber';
    }

    private function professionalTitle(Profile $profile): string
    {
        return $this->str($profile->preferred_occupation ?? null)
            ?? $this->str($profile->industry ?? null)
            ?? 'Bewerber';
    }

    private function coverTitle(Profile $profile, ?Listing $listing): string
    {
        $role = $this->str($listing?->title)
            ?? $this->str($profile->preferred_occupation ?? null)
            ?? $this->str($profile->industry ?? null);

        return $role !== null ? 'Bewerbung als '.$role : 'Bewerbung';
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $letters .= mb_strtoupper(mb_substr($part, 0, 1));
            if (mb_strlen($letters) >= 2) {
                break;
            }
        }

        return $letters !== '' ? $letters : 'B';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function str(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function range(?string $start, ?string $end): ?string
    {
        if ($start === null && $end === null) {
            return null;
        }
        if ($start !== null && $end !== null) {
            return $start.'–'.$end;
        }

        return $start ?? $end;
    }
}
