<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\UpEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('google')
            ->scopes(['openid', 'profile', 'email'])
            ->redirect();
    }

    public function callback()
    {
        $googleUser = Socialite::driver('google')->stateless()->user();
        $email = strtolower((string) $googleUser->getEmail());

        if (!UpEmail::isAllowed($email)) {
            $frontend = rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/');
            return redirect()->away($frontend . '/login?error=upmail_only');
        }

        $name = trim((string) ($googleUser->getName() ?? 'UP User'));
        $parts = preg_split('/\s+/', $name);
        $firstName = $parts[0] ?? 'UP';
        $lastName = count($parts) > 1 ? $parts[count($parts) - 1] : 'User';
        $middleName = count($parts) > 2 ? implode(' ', array_slice($parts, 1, -1)) : null;

        $user = User::firstOrNew(['email' => $email]);
        $user->first_name = $firstName;
        $user->middle_name = $middleName;
        $user->last_name = $lastName;
        $user->google_id = $googleUser->getId();
        $user->auth_provider = 'google';
        $user->avatar_url = $googleUser->getAvatar();
        $user->password = $user->password ?: Hash::make(Str::random(40));
        $user->role = $user->role ?: User::ROLE_END_USER;
        $user->approval_status = $user->approval_status ?: User::APPROVAL_PENDING;
        $user->save();

        $user->tokens()->delete();
        $token = $user->createToken('smart-acsess-google')->plainTextToken;

        $frontend = rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/');
        return redirect()->away($frontend . '/auth/callback?token=' . urlencode($token));
    }
}
