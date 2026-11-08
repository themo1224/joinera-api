<?php

namespace Tests\Feature;

use App\Contracts\CvParser;
use App\Exceptions\CvUnreadableException;
use App\Models\User;
use App\Services\CvStorage;
use App\Support\CvParseResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeCvParser;
use Tests\TestCase;

class ProfileParseTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixture_parser_fills_empty_named_fields_and_status_becomes_parsed(): void
    {
        Storage::fake(CvStorage::DISK);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->post('/api/v1/profile/cv', [
                'cv' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
            ])
            ->assertCreated()
            ->assertJsonPath('parse_status', 'unparsed');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/profile/parse')
            ->assertOk()
            ->assertJsonPath('industry', 'IT')
            ->assertJsonPath('city', 'Berlin')
            ->assertJsonPath('province', 'BE')
            ->assertJsonPath('language', 'de')
            ->assertJsonPath('parse_status', 'parsed')
            ->assertJsonPath('complete', true);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('parse_status', 'parsed');
    }

    public function test_unreadable_cv_returns_422_parse_failed_and_prior_industry_remains(): void
    {
        Storage::fake(CvStorage::DISK);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile', ['industry' => 'Nursing'])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->post('/api/v1/profile/cv', [
                'cv' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
            ])
            ->assertCreated();

        $this->swap(CvParser::class, new FakeCvParser([
            new CvUnreadableException('The CV could not be read.'),
        ]));

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/profile/parse')
            ->assertStatus(422)
            ->assertJsonPath('code', 'cv_unreadable');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('industry', 'Nursing')
            ->assertJsonPath('parse_status', 'parse_failed');
    }

    public function test_user_edited_city_is_not_overwritten_by_second_parse(): void
    {
        Storage::fake(CvStorage::DISK);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->post('/api/v1/profile/cv', [
                'cv' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
            ])
            ->assertCreated();

        $this->swap(CvParser::class, new FakeCvParser([
            new CvParseResult(
                industry: 'IT',
                city: 'Berlin',
                province: 'BE',
                language: 'de',
            ),
            new CvParseResult(
                industry: 'Healthcare',
                city: 'Hamburg',
                province: 'HH',
                language: 'en',
            ),
        ]));

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/profile/parse')
            ->assertOk()
            ->assertJsonPath('city', 'Berlin')
            ->assertJsonPath('parse_status', 'parsed');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/profile', ['city' => 'Munich'])
            ->assertOk()
            ->assertJsonPath('city', 'Munich');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/profile/parse')
            ->assertOk()
            ->assertJsonPath('city', 'Munich')
            ->assertJsonPath('industry', 'Healthcare')
            ->assertJsonPath('province', 'HH')
            ->assertJsonPath('language', 'en')
            ->assertJsonPath('parse_status', 'parsed');
    }

    public function test_user_with_no_cv_gets_422_and_no_parse_status_change(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('parse_status', 'unparsed');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/profile/parse')
            ->assertStatus(422)
            ->assertJsonPath('code', 'cv_missing');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('parse_status', 'unparsed');
    }

    public function test_another_user_cannot_parse_this_users_cv_via_id_route(): void
    {
        Storage::fake(CvStorage::DISK);
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $this->actingAs($owner, 'sanctum')
            ->post('/api/v1/profile/cv', [
                'cv' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
            ])
            ->assertCreated();

        $this->actingAs($intruder, 'sanctum')
            ->postJson('/api/v1/profile/'.$owner->id.'/parse')
            ->assertNotFound();
    }

    public function test_staff_token_is_403_on_profile_parse(): void
    {
        $staff = User::factory()->staff()->create();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/profile/parse')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');
    }
}
