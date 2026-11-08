<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUser
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role !== Role::User) {
            return response()->json([
                'code' => 'forbidden',
                'message' => 'This action is unauthorized.',
            ], 403);
        }

        return $next($request);
    }
}
