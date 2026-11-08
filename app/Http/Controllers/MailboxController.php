<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainRegistrarUnsetException;
use App\Exceptions\MailboxModeRejectedException;
use App\Exceptions\MailboxProviderUnsetException;
use App\Exceptions\MailboxTakenException;
use App\Exceptions\NameRequiredException;
use App\Services\StartMailbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MailboxController extends Controller
{
    public function __construct(private readonly StartMailbox $startMailbox)
    {
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $mailbox = $this->startMailbox->handle($request->user(), $request);
        } catch (MailboxProviderUnsetException) {
            return response()->json([
                'code' => 'mailbox_provider_unset',
                'message' => 'The mailbox provider is not configured.',
            ], 409);
        } catch (DomainRegistrarUnsetException) {
            return response()->json([
                'code' => 'domain_registrar_unset',
                'message' => 'The domain registrar is not configured.',
            ], 409);
        } catch (MailboxModeRejectedException) {
            return response()->json([
                'code' => 'mailbox_mode_rejected',
                'message' => 'Gmail, user-owned, and Joinera-branded shared domains are not allowed.',
            ], 422);
        } catch (NameRequiredException) {
            return response()->json([
                'code' => 'name_required',
                'message' => 'A first and last name are required to create an apply address.',
            ], 422);
        } catch (MailboxTakenException) {
            return response()->json([
                'code' => 'mailbox_taken',
                'message' => 'That apply address or domain is already in use.',
            ], 409);
        }

        return response()->json($mailbox->toPublicArray(), 201);
    }

    public function show(Request $request): JsonResponse
    {
        $mailbox = $request->user()->mailboxIdentity()->first();

        if ($mailbox === null) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Mailbox not found.',
            ], 404);
        }

        return response()->json($mailbox->toPublicArray());
    }
}
