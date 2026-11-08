<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\StoreStaffUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class StaffUserController extends Controller
{
    public function store(StoreStaffUserRequest $request): JsonResponse
    {
        $user = User::query()->create([
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'password' => $request->string('password')->toString(),
            'role' => Role::from($request->string('role')->toString()),
        ]);

        return response()->json($user->toAuthArray(), 201);
    }
}
