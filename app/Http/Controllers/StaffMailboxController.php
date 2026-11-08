<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;

class StaffMailboxController extends Controller
{
    public function show(User $user): JsonResponse
    {
        $mailbox = $user->mailboxIdentity()->first();

        if ($mailbox === null) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Mailbox not found.',
            ], 404);
        }

        return response()->json($mailbox->toPublicArray());
    }
}
