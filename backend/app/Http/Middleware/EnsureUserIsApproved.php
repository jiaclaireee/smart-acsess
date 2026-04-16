<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            abort(401);
        }

        if ($user->approval_status === User::APPROVAL_APPROVED) {
            return $next($request);
        }

        $message = match ($user->approval_status) {
            User::APPROVAL_REJECTED => 'Your account has been rejected. Please contact an administrator.',
            default => 'Your account is pending admin approval.',
        };

        return response()->json([
            'message' => $message,
            'approval_status' => $user->approval_status,
        ], 403);
    }
}
