<?php

namespace Tests\Unit;

use App\Models\Listing;
use App\Models\Profile;
use App\Models\User;
use App\Services\Docs\RenderCleanA4SplitHtml;
use PHPUnit\Framework\TestCase;

class RenderCleanA4SplitHtmlTest extends TestCase
{
    public function test_cv_html_uses_clean_a4_split_chrome_and_profile_fields(): void
    {
        $user = new User(['name' => 'Lena Hoffmann', 'email' => 'lena@example.test']);
        $profile = new Profile([
            'preferred_occupation' => 'Full-Stack Entwicklerin',
            'city' => 'Berlin',
            'province' => 'Berlin',
            'industry' => 'IT',
            'experiences' => [
                [
                    'title' => 'Senior Engineer',
                    'organization' => 'Nordwerk',
                    'location' => 'Berlin',
                    'start' => '2022',
                    'current' => true,
                    'description' => 'Pipeline und Ops.',
                ],
                [
                    'title' => 'Engineer',
                    'organization' => 'Klarform',
                    'location' => 'Hamburg',
                    'start' => '2019',
                    'end' => '2022',
                    'description' => 'APIs.',
                ],
                [
                    'title' => 'Junior',
                    'organization' => 'Freelance',
                    'location' => 'Remote',
                    'start' => '2017',
                    'end' => '2019',
                ],
            ],
            'education_entries' => [
                [
                    'program' => 'B.Sc. Informatik',
                    'institution' => 'TU Berlin',
                    'start' => '2013',
                    'end' => '2017',
                ],
            ],
            'skills' => [
                ['name' => 'Laravel', 'level' => 'sehr gut'],
                ['name' => 'PHP', 'level' => 'gut'],
            ],
            'languages' => [
                ['language' => 'Deutsch', 'level' => 'C1'],
            ],
        ]);

        $html = (new RenderCleanA4SplitHtml)->cv($user, $profile, 'Motivierte Bewerberin mit Fokus auf Ops.');

        $this->assertStringContainsString('Lena Hoffmann', $html);
        $this->assertStringContainsString('Full-Stack Entwicklerin', $html);
        $this->assertStringContainsString('Nordwerk', $html);
        $this->assertStringContainsString('class="pipe"', $html);
        $this->assertStringContainsString('Ausbildung', $html);
        $this->assertStringContainsString('Technische Kenntnisse', $html);
        $this->assertStringContainsString('Laravel (sehr gut)', $html);
        $this->assertStringContainsString('footer-bar', $html);
        $this->assertStringContainsString('#C9A227', $html);
        $this->assertStringNotContainsString('<h1>Lebenslauf</h1>', $html);
        // Profile preference city/province are temporarily unused in contact chrome.
        $this->assertStringNotContainsString('Berlin, Berlin', $html);
    }

    public function test_cover_html_includes_recipient_and_body(): void
    {
        $user = new User(['name' => 'Lena Hoffmann', 'email' => 'lena@example.test']);
        $profile = new Profile([
            'preferred_occupation' => 'Full-Stack Entwicklerin',
            'city' => 'Berlin',
        ]);
        $listing = new Listing([
            'title' => 'Software Engineer',
            'company' => 'Joinera GmbH',
        ]);

        $html = (new RenderCleanA4SplitHtml)->cover(
            $user,
            $profile,
            "Sehr geehrte Damen und Herren,\n\nmit Interesse bewerbe ich mich.",
            $listing,
        );

        $this->assertStringContainsString('Joinera GmbH', $html);
        $this->assertStringContainsString('Bewerbung als Software Engineer', $html);
        $this->assertStringContainsString('mit Interesse bewerbe ich mich.', $html);
        $this->assertStringContainsString('footer-bar', $html);
        $this->assertStringNotContainsString('<h1>Anschreiben</h1>', $html);
    }
}
