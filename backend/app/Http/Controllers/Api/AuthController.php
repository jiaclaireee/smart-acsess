<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\UpEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'first_name' => ['required','string','max:100'],
            'middle_name' => ['nullable','string','max:100'],
            'last_name' => ['required','string','max:100'],
            'contact_no' => ['nullable','string','max:50'],
            'office_department' => ['nullable','string','max:150'],
            'college_course' => ['nullable','string','max:150'],
            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (!UpEmail::isAllowed((string) $value)) {
                        $fail(UpEmail::validationMessage());
                    }
                },
            ],
            'password' => [
                'required',
                'confirmed',
                Password::min(8)->letters()->mixedCase()->numbers()->symbols(),
            ],
        ], [
            'first_name.required' => 'First name is required.',
            'last_name.required' => 'Last name is required.',
            'email.required' => 'UP Mail is required.',
            'email.email' => 'Enter a valid UP Mail address.',
            'email.unique' => 'This UP Mail address is already registered.',
            'password.confirmed' => 'Password confirmation does not match.',
        ]);

        $user = User::create([
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => $data['last_name'],
            'contact_no' => $data['contact_no'] ?? null,
            'office_department' => $data['office_department'] ?? null,
            'college_course' => $data['college_course'] ?? null,
            'email' => strtolower($data['email']),
            'password' => Hash::make($data['password']),
            'auth_provider' => 'password',
            'role' => User::ROLE_END_USER,
            'approval_status' => User::APPROVAL_PENDING,
        ]);

        $token = $user->createToken('smart-acsess')->plainTextToken;

        return response()->json([
            'message' => 'Registration successful. Your account is pending admin approval.',
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required','email'],
            'password' => ['required'],
        ], [
            'email.required' => 'UP Mail is required.',
            'email.email' => 'Enter a valid UP Mail address.',
            'password.required' => 'Password is required.',
        ]);

        $user = User::where('email', strtolower($data['email']))->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        $token = $user->createToken('smart-acsess')->plainTextToken;

        $message = match ($user->approval_status) {
            User::APPROVAL_REJECTED => 'Login successful, but your account has been rejected.',
            User::APPROVAL_PENDING => 'Login successful, but your account is still pending approval.',
            default => 'Login successful.',
        };

        return response()->json([
            'message' => $message,
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }
}
