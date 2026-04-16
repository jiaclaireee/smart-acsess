<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\UpEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index()
    {
        return response()->json(User::orderBy('id','desc')->paginate(20));
    }

    public function store(Request $request)
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
                'string',
                Password::min(8)->letters()->mixedCase()->numbers()->symbols(),
            ],
            'role' => ['nullable', Rule::in([User::ROLE_ADMIN, User::ROLE_END_USER])],
            'approval_status' => ['nullable', Rule::in([
                User::APPROVAL_PENDING,
                User::APPROVAL_APPROVED,
                User::APPROVAL_REJECTED,
            ])],
        ], [
            'first_name.required' => 'First name is required.',
            'last_name.required' => 'Last name is required.',
            'email.required' => 'UP Mail is required.',
            'email.email' => 'Enter a valid UP Mail address.',
            'email.unique' => 'This UP Mail address is already registered.',
            'role.in' => 'Select a valid user role.',
            'approval_status.in' => 'Select a valid approval status.',
        ]);

        $u = User::create([
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?? null,
            'last_name' => $data['last_name'],
            'contact_no' => $data['contact_no'] ?? null,
            'office_department' => $data['office_department'] ?? null,
            'college_course' => $data['college_course'] ?? null,
            'email' => strtolower($data['email']),
            'password' => Hash::make($data['password']),
            'auth_provider' => 'password',
            'role' => $data['role'] ?? User::ROLE_END_USER,
            'approval_status' => $data['approval_status'] ?? User::APPROVAL_PENDING,
        ]);

        return response()->json($u, 201);
    }

    public function show(User $user)
    {
        return response()->json($user);
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'first_name' => ['sometimes','required','string','max:100'],
            'middle_name' => ['nullable','string','max:100'],
            'last_name' => ['sometimes','required','string','max:100'],
            'contact_no' => ['nullable','string','max:50'],
            'office_department' => ['nullable','string','max:150'],
            'college_course' => ['nullable','string','max:150'],
            'email' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('users','email')->ignore($user->id),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (!UpEmail::isAllowed((string) $value)) {
                        $fail(UpEmail::validationMessage());
                    }
                },
            ],
            'password' => ['nullable', 'string', Password::min(8)->letters()->mixedCase()->numbers()->symbols()],
            'role' => ['sometimes','required', Rule::in([User::ROLE_ADMIN, User::ROLE_END_USER])],
            'approval_status' => ['sometimes','required', Rule::in([
                User::APPROVAL_PENDING,
                User::APPROVAL_APPROVED,
                User::APPROVAL_REJECTED,
            ])],
        ], [
            'email.email' => 'Enter a valid UP Mail address.',
            'email.unique' => 'This UP Mail address is already registered.',
            'role.in' => 'Select a valid user role.',
            'approval_status.in' => 'Select a valid approval status.',
        ]);

        if (($data['role'] ?? $user->role) !== User::ROLE_ADMIN && $user->isAdmin()) {
            return response()->json([
                'message' => 'Admin accounts cannot be downgraded from this endpoint.',
            ], 422);
        }

        if (array_key_exists('password', $data) && $data['password']) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        if (array_key_exists('email', $data)) {
            $data['email'] = strtolower($data['email']);
        }

        $user->update($data);
        return response()->json($user);
    }

    public function destroy(Request $request, User $user)
    {
        if ($request->user()?->is($user)) {
            return response()->json([
                'message' => 'You cannot delete the account you are currently using.',
            ], 422);
        }

        if ($user->isAdmin()) {
            return response()->json([
                'message' => 'Admin accounts cannot be deleted from this endpoint.',
            ], 422);
        }

        $user->delete();
        return response()->json(['ok' => true]);
    }

    public function updateApproval(Request $request, User $user)
    {
        $data = $request->validate([
            'approval_status' => ['required', Rule::in([
                User::APPROVAL_PENDING,
                User::APPROVAL_APPROVED,
                User::APPROVAL_REJECTED,
            ])],
        ]);

        if ($user->isAdmin() && $data['approval_status'] !== User::APPROVAL_APPROVED) {
            return response()->json([
                'message' => 'Admin accounts must remain approved.',
            ], 422);
        }

        $user->approval_status = $data['approval_status'];
        $user->save();

        return response()->json($user);
    }
}
