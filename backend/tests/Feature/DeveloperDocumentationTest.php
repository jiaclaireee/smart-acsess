<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeveloperDocumentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_fetch_the_developer_documentation_payload(): void
    {
        Sanctum::actingAs($this->makeUser(User::ROLE_ADMIN, User::APPROVAL_APPROVED));

        $this->getJson('/api/developers/documentation')
            ->assertOk()
            ->assertJsonPath('title', 'SMART-ACSESS for Developers')
            ->assertJsonPath('audience', 'Admin only')
            ->assertJsonPath('overview.plain_language', 'In simple terms, this is the main developer handbook for teams that want to connect another system to SMART-ACSESS and reuse its dashboard and chatbot features.')
            ->assertJsonPath('database_columns.required.0.column', 'id')
            ->assertJsonPath('database_columns.recommended.0.column', 'location')
            ->assertJsonPath('database_columns.optional.0.column', 'assigned_to')
            ->assertJsonPath('dashboard.endpoints.3.endpoint', '/api/analytics/report')
            ->assertJsonPath('chatbot.endpoints.1.endpoint', '/api/chatbot/ask');
    }

    public function test_non_admin_user_cannot_fetch_the_developer_documentation_payload(): void
    {
        Sanctum::actingAs($this->makeUser(User::ROLE_END_USER, User::APPROVAL_APPROVED));

        $this->getJson('/api/developers/documentation')
            ->assertForbidden()
            ->assertJsonPath('message', 'You are not authorized to access this resource.');
    }

    public function test_admin_can_download_the_developer_documentation_pdf(): void
    {
        Sanctum::actingAs($this->makeUser(User::ROLE_ADMIN, User::APPROVAL_APPROVED));

        $response = $this->get('/api/developers/documentation/pdf');

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString(
            'smart-acsess-for-developers.pdf',
            (string) $response->headers->get('content-disposition')
        );
    }

    private function makeUser(string $role, string $approvalStatus): User
    {
        return User::factory()->create([
            'role' => $role,
            'approval_status' => $approvalStatus,
        ]);
    }
}
