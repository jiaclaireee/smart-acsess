<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            abort(401);
        }

        if (in_array($user->role, $roles, true)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'You are not authorized to access this resource.',
        ], 403);
    }
}
