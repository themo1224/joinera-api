<?php

namespace App\Services;

use App\Exceptions\IntakeIdentityContextException;
use App\Exceptions\ListingDroppedException;
use Throwable;

class ImportListings
{
    public function __construct(private readonly UpsertListing $upsertListing)
    {
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{imported: int, errors: list<array{index: int, code: string, message: string}>}
     */
    public function handle(array $rows): array
    {
        $imported = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $errors[] = [
                    'index' => $index,
                    'code' => 'invalid_row',
                    'message' => 'Row must be an object.',
                ];

                continue;
            }

            $source = trim((string) ($row['source'] ?? ''));

            if ($source === '') {
                $errors[] = [
                    'index' => $index,
                    'code' => 'validation_failed',
                    'message' => 'source is required.',
                ];

                continue;
            }

            $kind = strtolower(trim((string) ($row['kind'] ?? '')));

            if ($kind === '') {
                $errors[] = [
                    'index' => $index,
                    'code' => 'kind_required',
                    'message' => 'kind is required.',
                ];

                continue;
            }

            try {
                $result = $this->upsertListing->handle($source, $row);
            } catch (IntakeIdentityContextException $exception) {
                $errors[] = [
                    'index' => $index,
                    'code' => 'intake_identity_context',
                    'message' => $exception->getMessage(),
                ];

                continue;
            } catch (ListingDroppedException $exception) {
                $errors[] = [
                    'index' => $index,
                    'code' => 'invalid_listing',
                    'message' => $exception->getMessage(),
                ];

                continue;
            } catch (Throwable $exception) {
                $errors[] = [
                    'index' => $index,
                    'code' => 'import_failed',
                    'message' => $exception->getMessage(),
                ];

                continue;
            }

            if ($result === null) {
                $errors[] = [
                    'index' => $index,
                    'code' => 'country_filtered',
                    'message' => 'Listing country is outside DE.',
                ];

                continue;
            }

            if ($result['created']) {
                $imported++;
            }
        }

        return [
            'imported' => $imported,
            'errors' => $errors,
        ];
    }
}
