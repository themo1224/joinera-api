<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MailboxController extends Controller
{
    public function store(): JsonResponse
    {
        return response()->json([
            'code' => 'mailbox_self_provision_disabled',
            'message' => 'Candidates cannot attach GMX credentials. An Admin attaches the mailbox after create.',
        ], 422);
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
