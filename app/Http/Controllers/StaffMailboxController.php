<?php

namespace App\Http\Controllers;

use App\Contracts\MailboxInboundSync;
use App\Enums\MailboxState;
use App\Enums\Role;
use App\Exceptions\MailboxAddressRejectedException;
use App\Exceptions\MailboxAuthFailedException;
use App\Exceptions\MailboxProviderUnsetException;
use App\Exceptions\MailboxTakenException;
use App\Http\Requests\AttachMailboxCredentialsRequest;
use App\Http\Requests\ReplyMailboxMessageRequest;
use App\Models\MailboxMessage;
use App\Models\User;
use App\Services\AttachGmxMailbox;
use App\Services\ReplyMailboxMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class StaffMailboxController extends Controller
{
    public function __construct(
        private readonly AttachGmxMailbox $attachGmxMailbox,
        private readonly ReplyMailboxMessage $replyMailboxMessage,
        private readonly MailboxInboundSync $inboundSync,
    ) {
    }

    public function show(User $user): JsonResponse
    {
        if ($user->role !== Role::User) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Not found.',
            ], 404);
        }

        $mailbox = $user->mailboxIdentity()->first();

        if ($mailbox === null) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Mailbox not found.',
            ], 404);
        }

        return response()->json($mailbox->toPublicArray());
    }

    public function updateCredentials(AttachMailboxCredentialsRequest $request, User $user): JsonResponse
    {
        if ($user->role !== Role::User) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Not found.',
            ], 404);
        }

        try {
            $mailbox = $this->attachGmxMailbox->handle(
                $user,
                $request->string('address')->toString(),
                $request->string('password')->toString(),
            );
        } catch (MailboxProviderUnsetException) {
            return response()->json([
                'code' => 'mailbox_provider_unset',
                'message' => 'The mailbox mode is not configured.',
            ], 409);
        } catch (MailboxAddressRejectedException) {
            return response()->json([
                'code' => 'invalid_gmx_address',
                'message' => 'That GMX address is not allowed.',
            ], 422);
        } catch (MailboxTakenException) {
            return response()->json([
                'code' => 'mailbox_taken',
                'message' => 'That apply address is already in use.',
            ], 409);
        } catch (MailboxAuthFailedException) {
            $failed = $user->mailboxIdentity()->first();

            return response()->json(array_merge([
                'code' => 'gmx_auth_failed',
                'message' => 'GMX IMAP login failed. Check credentials and IMAP settings.',
            ], $failed?->toPublicArray() ?? []), 422);
        }

        return response()->json($mailbox->toPublicArray());
    }

    public function sync(User $user): JsonResponse
    {
        if ($user->role !== Role::User) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Not found.',
            ], 404);
        }

        $mailbox = $user->mailboxIdentity()->first();

        if ($mailbox === null) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Mailbox not found.',
            ], 404);
        }

        if ($mailbox->state !== MailboxState::Ready && $mailbox->state !== MailboxState::Failed) {
            return response()->json([
                'code' => 'mailbox_not_ready',
                'message' => 'Mailbox is not ready to sync.',
            ], 409);
        }

        if ((string) $mailbox->password === '') {
            return response()->json([
                'code' => 'gmx_auth_failed',
                'message' => 'GMX credentials are missing.',
            ], 422);
        }

        // Run sync inline so Admin "Sync now" gets an immediate result.
        $result = $this->inboundSync->sync($mailbox->fresh() ?? $mailbox);
        $fresh = $user->mailboxIdentity()->first();

        if ($result->authFailed) {
            return response()->json(array_merge([
                'code' => 'gmx_auth_failed',
                'message' => 'GMX IMAP login failed during sync.',
                'upserted' => 0,
            ], $fresh?->toPublicArray() ?? []), 422);
        }

        if ($result->error !== null) {
            return response()->json(array_merge([
                'code' => 'gmx_sync_failed',
                'message' => 'GMX IMAP sync failed.',
                'upserted' => 0,
            ], $fresh?->toPublicArray() ?? []), 422);
        }

        return response()->json(array_merge(
            $fresh?->toPublicArray() ?? [],
            ['upserted' => $result->upserted],
        ));
    }

    public function messages(Request $request, User $user): JsonResponse
    {
        if ($user->role !== Role::User) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Not found.',
            ], 404);
        }

        $mailbox = $user->mailboxIdentity()->first();

        if ($mailbox === null) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Mailbox not found.',
            ], 404);
        }

        $page = max(1, (int) $request->integer('page', 1));
        $limit = min(100, max(1, (int) $request->integer('limit', 20)));
        $offset = ($page - 1) * $limit;

        $query = $mailbox->messages()->orderByDesc('id');
        $total = $query->count();
        $items = $query->offset($offset)->limit($limit)->get()
            ->map(fn (MailboxMessage $message): array => $message->toPublicArray())
            ->values();

        return response()->json([
            'success' => true,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'messages' => $items,
        ]);
    }

    public function showMessage(User $user, MailboxMessage $message): JsonResponse
    {
        if ($user->role !== Role::User) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Not found.',
            ], 404);
        }

        $mailbox = $user->mailboxIdentity()->first();

        if ($mailbox === null || $message->mailbox_identity_id !== $mailbox->id) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Message not found.',
            ], 404);
        }

        return response()->json($message->toPublicArray());
    }

    public function reply(ReplyMailboxMessageRequest $request, User $user, MailboxMessage $message): JsonResponse
    {
        if ($user->role !== Role::User) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Not found.',
            ], 404);
        }

        $mailbox = $user->mailboxIdentity()->first();

        if ($mailbox === null || $message->mailbox_identity_id !== $mailbox->id) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Message not found.',
            ], 404);
        }

        try {
            $outbound = $this->replyMailboxMessage->handle(
                $mailbox,
                $message,
                $request->string('body')->toString(),
            );
        } catch (MailboxAuthFailedException $exception) {
            return response()->json([
                'code' => $exception->getMessage() === 'gmx_smtp_failed' ? 'gmx_smtp_failed' : 'gmx_auth_failed',
                'message' => 'Failed to send reply via GMX SMTP.',
            ], 422);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'mailbox_not_ready') {
                return response()->json([
                    'code' => 'mailbox_not_ready',
                    'message' => 'Mailbox is not ready for replies.',
                ], 409);
            }

            return response()->json([
                'code' => 'not_found',
                'message' => 'Message not found.',
            ], 404);
        }

        return response()->json($outbound->toPublicArray(), 201);
    }
}
