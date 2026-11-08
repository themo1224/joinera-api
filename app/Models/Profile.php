<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\ParseStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'industry', 'city', 'province', 'language', 'parse_status', 'user_edited_fields', 'priorities', 'approval', 'approved_by', 'approved_at'])]
class Profile extends Model
{
    public const DEFAULT_PRIORITIES = ['industry', 'city', 'province'];

    private const NAMED_FIELDS = ['industry', 'city', 'province', 'language'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parse_status' => ParseStatus::class,
            'user_edited_fields' => 'array',
            'priorities' => 'array',
            'approval' => ApprovalStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function approveOnce(User $actor): void
    {
        if ($this->approval === ApprovalStatus::Approved) {
            return;
        }

        $this->approval = ApprovalStatus::Approved;
        $this->approved_by = $actor->id;
        $this->approved_at = now();
        $this->save();
    }

    /**
     * @return list<string>
     */
    public function ensurePriorities(): array
    {
        if ($this->priorities === null || $this->priorities === []) {
            $this->priorities = self::DEFAULT_PRIORITIES;
            $this->save();
        }

        return $this->normalizedPriorities();
    }

    /**
     * @param  list<string>  $priorities
     * @return bool True when the stored order changed
     */
    public function setPriorities(array $priorities): bool
    {
        $next = array_values($priorities);
        $current = $this->normalizedPriorities();

        if ($next === $current) {
            if ($this->priorities === null || $this->priorities === []) {
                $this->priorities = $next;
                $this->save();
            }

            return false;
        }

        $this->priorities = $next;
        $this->save();

        return true;
    }

    /**
     * @return list<string>
     */
    public function normalizedPriorities(): array
    {
        /** @var list<string>|null $priorities */
        $priorities = $this->priorities;

        if ($priorities === null || $priorities === []) {
            return self::DEFAULT_PRIORITIES;
        }

        return array_values($priorities);
    }

    public function isComplete(?CvAsset $cvAsset = null): bool
    {
        $cvAsset ??= $this->user?->cvAsset;

        return $cvAsset !== null
            && $this->isFilled($this->industry)
            && $this->isFilled($this->city)
            && $this->isFilled($this->province)
            && $this->isFilled($this->language);
    }

    /**
     * @param  list<string>  $fields
     */
    public function markFieldsEdited(array $fields): void
    {
        $edited = $this->editedFields();

        foreach ($fields as $field) {
            if (in_array($field, self::NAMED_FIELDS, true) && ! in_array($field, $edited, true)) {
                $edited[] = $field;
            }
        }

        $this->user_edited_fields = $edited;
    }

    /**
     * @return list<string>
     */
    public function editedFields(): array
    {
        /** @var list<string>|null $fields */
        $fields = $this->user_edited_fields;

        return array_values(array_filter(
            $fields ?? [],
            fn (mixed $field): bool => is_string($field) && in_array($field, self::NAMED_FIELDS, true),
        ));
    }

    public function isFieldEdited(string $field): bool
    {
        return in_array($field, $this->editedFields(), true);
    }

    /**
     * @return array{
     *     industry: string|null,
     *     city: string|null,
     *     province: string|null,
     *     language: string|null,
     *     complete: bool,
     *     parse_status: string,
     *     approval: string,
     *     cv: array{original_name: string, mime: string, size: int}|null
     * }
     */
    public function toOwnerArray(?CvAsset $cvAsset = null): array
    {
        $cvAsset ??= $this->user?->cvAsset;

        return [
            ...$this->toFieldArray($cvAsset),
            'cv' => $cvAsset === null ? null : [
                'original_name' => $cvAsset->original_name,
                'mime' => $cvAsset->mime,
                'size' => $cvAsset->size,
            ],
        ];
    }

    /**
     * @return array{
     *     industry: string|null,
     *     city: string|null,
     *     province: string|null,
     *     language: string|null,
     *     complete: bool,
     *     parse_status: string,
     *     approval: string
     * }
     */
    public function toStaffArray(?CvAsset $cvAsset = null): array
    {
        return $this->toFieldArray($cvAsset ?? $this->user?->cvAsset);
    }

    /**
     * @return array{
     *     industry: string|null,
     *     city: string|null,
     *     province: string|null,
     *     language: string|null,
     *     complete: bool,
     *     parse_status: string,
     *     approval: string
     * }
     */
    private function toFieldArray(?CvAsset $cvAsset): array
    {
        return [
            'industry' => $this->industry,
            'city' => $this->city,
            'province' => $this->province,
            'language' => $this->language,
            'complete' => $this->isComplete($cvAsset),
            'parse_status' => ($this->parse_status ?? ParseStatus::Unparsed)->value,
            'approval' => ($this->approval ?? ApprovalStatus::Pending)->value,
        ];
    }

    private function isFilled(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
