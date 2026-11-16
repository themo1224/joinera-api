<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModeBWorkerSecret
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('joinera.mode_b.worker_secret');
        if ($expected === '') {
            return response()->json([
                'code' => 'mode_b_worker_unconfigured',
                'message' => 'Mode B worker secret is not configured.',
            ], 503);
        }

        $provided = (string) $request->header('X-Mode-B-Worker-Secret', '');
        if (! hash_equals($expected, $provided)) {
            return response()->json([
                'code' => 'forbidden',
                'message' => 'Invalid Mode B worker secret.',
            ], 403);
        }

        return $next($request);
    }
}
