<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\SignupRequest;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function signup(SignupRequest $request): JsonResponse
    {
        $user = User::query()->create([
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'password' => $request->string('password')->toString(),
            'role' => Role::User,
        ]);

        return response()->json([
            'token' => $user->createToken('panel')->plainTextToken,
            'user' => $user->toAuthArray(),
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $limit = $this->loginLimit($request);

        if (RateLimiter::tooManyAttempts($limit->key, $limit->maxAttempts)) {
            return response()->json([
                'code' => 'too_many_attempts',
                'message' => 'Too many attempts.',
            ], 429);
        }

        $user = User::query()->where('email', $request->string('email')->toString())->first();

        if ($user === null || ! Hash::check($request->string('password')->toString(), $user->password)) {
            RateLimiter::hit($limit->key, $limit->decaySeconds);

            return response()->json([
                'code' => 'invalid_credentials',
                'message' => 'These credentials do not match our records.',
            ], 401);
        }

        RateLimiter::clear($limit->key);

        return response()->json([
            'token' => $user->createToken('panel')->plainTextToken,
            'user' => $user->toAuthArray(),
        ]);
    }

    public function logout(Request $request): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user()->toAuthArray());
    }

    private function loginLimit(LoginRequest $request): Limit
    {
        $limiter = RateLimiter::limiter('login');

        return $limiter($request);
    }
}
