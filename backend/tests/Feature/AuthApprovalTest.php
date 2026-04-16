<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_rejects_non_up_email_addresses(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@gmail.com',
            'password' => 'Password@123',
            'password_confirmation' => 'Password@123',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_registration_creates_pending_end_user_and_returns_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@up.edu.ph',
            'password' => 'Password@123',
            'password_confirmation' => 'Password@123',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user.role', User::ROLE_END_USER)
            ->assertJsonPath('user.approval_status', User::APPROVAL_PENDING)
            ->assertJsonStructure(['token']);
    }

    public function test_pending_users_cannot_access_approved_modules(): void
    {
        $user = User::factory()->create([
            'approval_status' => User::APPROVAL_PENDING,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/chat', [
            'message' => 'Hello',
        ])
            ->assertForbidden()
            ->assertJsonPath('approval_status', User::APPROVAL_PENDING);
    }

    public function test_approved_end_users_can_access_allowed_modules(): void
    {
        $user = User::factory()->create([
            'approval_status' => User::APPROVAL_APPROVED,
            'role' => User::ROLE_END_USER,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/chat', [
            'message' => 'Hello',
        ])->assertOk();
    }

    public function test_admin_can_approve_pending_users(): void
    {
        $admin = User::factory()->create([
            'approval_status' => User::APPROVAL_APPROVED,
            'role' => User::ROLE_ADMIN,
            'email' => 'admin@uplb.edu.ph',
        ]);

        $pendingUser = User::factory()->create([
            'approval_status' => User::APPROVAL_PENDING,
        ]);

        Sanctum::actingAs($admin);

        $this->patchJson("/api/users/{$pendingUser->id}/approval", [
            'approval_status' => User::APPROVAL_APPROVED,
        ])
            ->assertOk()
            ->assertJsonPath('approval_status', User::APPROVAL_APPROVED);

        $this->assertDatabaseHas('users', [
            'id' => $pendingUser->id,
            'approval_status' => User::APPROVAL_APPROVED,
        ]);
    }

    public function test_non_admins_cannot_approve_users(): void
    {
        $endUser = User::factory()->create([
            'approval_status' => User::APPROVAL_APPROVED,
            'role' => User::ROLE_END_USER,
        ]);

        $pendingUser = User::factory()->create([
            'approval_status' => User::APPROVAL_PENDING,
        ]);

        Sanctum::actingAs($endUser);

        $this->patchJson("/api/users/{$pendingUser->id}/approval", [
            'approval_status' => User::APPROVAL_APPROVED,
        ])->assertForbidden();
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        $admin = User::factory()->create([
            'approval_status' => User::APPROVAL_APPROVED,
            'role' => User::ROLE_ADMIN,
            'email' => 'admin@uplb.edu.ph',
        ]);

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/users/{$admin->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'You cannot delete the account you are currently using.');
    }

    public function test_admin_accounts_cannot_be_deleted_from_user_management_endpoint(): void
    {
        $admin = User::factory()->create([
            'approval_status' => User::APPROVAL_APPROVED,
            'role' => User::ROLE_ADMIN,
            'email' => 'admin@uplb.edu.ph',
        ]);

        $otherAdmin = User::factory()->create([
            'approval_status' => User::APPROVAL_APPROVED,
            'role' => User::ROLE_ADMIN,
            'email' => 'other-admin@up.edu.ph',
        ]);

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/users/{$otherAdmin->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Admin accounts cannot be deleted from this endpoint.');
    }
}
