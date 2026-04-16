<?php

namespace Tests\Feature;

use App\Models\ConnectedDatabase;
use App\Models\User;
use App\Services\Chatbot\ChatbotKnowledgeIndexService;
use App\Services\Chatbot\Contracts\ChatbotLanguageModel;
use App\Services\Database\Contracts\DatabaseConnector;
use App\Services\Database\DatabaseConnectorManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ChatbotFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_end_user_can_prepare_cross_database_context_without_manual_database_selection(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $first = $this->makeDatabaseConnection('Operations DB');
        $second = $this->makeDatabaseConnection('Reports DB');
        $this->mockKnowledgeIndex([$first, $second]);

        $this->postJson('/api/chatbot/context', [])
            ->assertOk()
            ->assertJsonPath('overview.accessible_database_count', 2)
            ->assertJsonPath('overview.known_record_total', 155)
            ->assertJsonCount(2, 'databases');
    }

    public function test_chatbot_can_answer_taglish_count_question_without_database_selection(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $first = $this->makeDatabaseConnection('Operations DB');
        $second = $this->makeDatabaseConnection('Reports DB');
        $this->mockKnowledgeIndex([$first, $second]);
        $this->mockConnectorManager();

        $this->postJson('/api/chatbot/ask', [
            'prompt' => 'Ilan ang total records natin this month?',
        ])
            ->assertOk()
            ->assertJsonPath('intent', 'count')
            ->assertJsonPath('grounded', true)
            ->assertJsonPath('table.rows.0.record_count', 42)
            ->assertJsonPath('table.rows.1.record_count', 30);
    }

    public function test_chatbot_can_answer_tagalog_growth_question(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $first = $this->makeDatabaseConnection('Operations DB');
        $second = $this->makeDatabaseConnection('Reports DB');
        $this->mockKnowledgeIndex([$first, $second]);
        $this->mockConnectorManager();

        $this->postJson('/api/chatbot/ask', [
            'prompt' => 'May growth ba this quarter?',
        ])
            ->assertOk()
            ->assertJsonPath('intent', 'growth')
            ->assertJsonPath('grounded', true)
            ->assertJsonPath('table.rows.0.period', 'current');
    }

    public function test_chatbot_answers_tagalog_trend_prompt_in_tagalog(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $first = $this->makeDatabaseConnection('Operations DB');
        $second = $this->makeDatabaseConnection('Reports DB');
        $this->mockKnowledgeIndex([$first, $second]);
        $this->mockConnectorManager();

        $this->postJson('/api/chatbot/ask', [
            'prompt' => 'Ano ang trend ng reports this year?',
        ])
            ->assertOk()
            ->assertJsonPath('intent', 'trend')
            ->assertJsonPath('language_style', 'taglish')
            ->assertJsonPath('answer', 'Nakabuo ako ng grounded monthly trend mula sa 2 data sources.');
    }

    public function test_chatbot_routes_multilingual_expected_traffic_prompt_to_projection(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection('SP Database');
        $this->mockVehicleKnowledgeIndex([$database]);
        $this->mockConnectorManager();

        $response = $this->postJson('/api/chatbot/ask', [
            'prompt' => 'Ano ang expected traffic volume sa campus ngayong April 2026?',
        ]);

        $response->assertOk()
            ->assertJsonPath('intent', 'projection')
            ->assertJsonPath('grounded', true);

        $this->assertContains($response->json('language_style'), ['tagalog', 'taglish']);
        $this->assertStringContainsString('vehicle_movements.timestamp', (string) $response->json('answer'));
    }

    public function test_chatbot_context_includes_schema_training_profile_for_connected_database(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection('SP Database');
        $this->mockVehicleKnowledgeIndex([$database]);

        $this->postJson('/api/chatbot/context', [])
            ->assertOk()
            ->assertJsonPath('training_profile.resource_count', 3)
            ->assertJsonPath('training_profile.forecastable_resources.0', 'vehicle_movements');
    }

    public function test_chatbot_can_route_forecast_prompt_using_schema_training_profile(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection('SP Database');
        $this->mockVehicleKnowledgeIndex([$database]);
        $this->mockConnectorManager();

        $this->postJson('/api/chatbot/ask', [
            'prompt' => 'Forecast next month for vehicle movements.',
        ])
            ->assertOk()
            ->assertJsonPath('intent', 'projection')
            ->assertJsonPath('grounded', true)
            ->assertJsonPath('sources.0.resource', 'vehicle_movements')
            ->assertJsonPath('table.columns.0', 'forecast_period');
    }

    public function test_chatbot_can_continue_trend_conversation_with_forecast_follow_up(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection('SP Database');
        $this->mockVehicleKnowledgeIndex([$database]);
        $this->mockConnectorManager();

        $first = $this->postJson('/api/chatbot/ask', [
            'prompt' => 'Show monthly trend for vehicle_movements.',
        ]);

        $first->assertOk()
            ->assertJsonPath('intent', 'trend')
            ->assertJsonPath('sources.0.resource', 'vehicle_movements');

        $this->postJson('/api/chatbot/ask', [
            'context_id' => $first->json('context_id'),
            'prompt' => 'what about next month forecast?',
        ])
            ->assertOk()
            ->assertJsonPath('intent', 'projection')
            ->assertJsonPath('grounded', true)
            ->assertJsonPath('sources.0.resource', 'vehicle_movements')
            ->assertJsonPath('table.columns.0', 'forecast_period');
    }

    public function test_chatbot_can_answer_english_top_categories_question_across_data(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $first = $this->makeDatabaseConnection('Operations DB');
        $second = $this->makeDatabaseConnection('Reports DB');
        $this->mockKnowledgeIndex([$first, $second]);
        $this->mockConnectorManager();

        $this->postJson('/api/chatbot/ask', [
            'prompt' => 'What are the top categories across the data?',
        ])
            ->assertOk()
            ->assertJsonPath('intent', 'top_categories')
            ->assertJsonPath('grounded', true)
            ->assertJsonPath('table.rows.0.label', 'Open');
    }

    public function test_chatbot_can_lookup_license_number_without_manual_database_selection(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection('SP Database');
        $this->mockVehicleKnowledgeIndex([$database]);
        $this->mockConnectorManager();

        $this->postJson('/api/chatbot/ask', [
            'prompt' => 'what is the license number of green sedan',
        ])
            ->assertOk()
            ->assertJsonPath('intent', 'lookup')
            ->assertJsonPath('grounded', true)
            ->assertJsonPath('table.rows.0.license_number', 'ABC-1234')
            ->assertJsonPath('table.rows.0.resource', 'vehicles');
    }

    public function test_chatbot_can_lookup_license_number_using_joined_vehicle_context(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection('SP Database');
        $this->mockVehicleKnowledgeIndex([$database]);
        $this->mockConnectorManager();

        $this->postJson('/api/chatbot/ask', [
            'prompt' => 'what is the license number of green sedan in vehicle movements',
        ])
            ->assertOk()
            ->assertJsonPath('intent', 'lookup')
            ->assertJsonPath('grounded', true)
            ->assertJsonPath('table.rows.0.license_number', 'ABC-1234')
            ->assertJsonPath('table.rows.0.resource', 'vehicle_movements');
    }

    public function test_chatbot_can_list_license_plate_numbers_for_registered_jeepney(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection('Vehicle Registration Database (simulation)');
        $this->mockVehicleKnowledgeIndex([$database]);
        $this->mockConnectorManager();

        $this->postJson('/api/chatbot/ask', [
            'prompt' => 'ilista mo ang license plate number ng mga registered jeepney',
        ])
            ->assertOk()
            ->assertJsonPath('intent', 'lookup')
            ->assertJsonPath('grounded', true)
            ->assertJsonPath('table.rows.0.resource', 'vehicles')
            ->assertJsonPath('table.rows.0.license_number', 'JEP-1001')
            ->assertJsonPath('table.rows.1.license_number', 'JEP-1002')
            ->assertJsonPath('answer', 'May 2 grounded matches ako para sa jeep, at narito ang na-verify na listahan ng mga license number.')
            ->assertJsonCount(2, 'table.rows');
    }

    public function test_chatbot_can_list_license_plate_numbers_for_recorded_sedan(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection('Vehicle Registration Database (simulation)');
        $this->mockVehicleKnowledgeIndex([$database]);
        $this->mockConnectorManager();

        $this->postJson('/api/chatbot/ask', [
            'prompt' => 'ilista ang mga license plate number ng mga recorded sedan',
        ])
            ->assertOk()
            ->assertJsonPath('intent', 'lookup')
            ->assertJsonPath('grounded', true)
            ->assertJsonPath('table.rows.0.resource', 'vehicles')
            ->assertJsonPath('table.rows.0.license_number', 'ABC-1234')
            ->assertJsonPath('answer', 'May 1 grounded matches ako para sa sedan, at narito ang na-verify na listahan ng mga license number.')
            ->assertJsonCount(1, 'table.rows');
    }

    public function test_chatbot_can_handle_mixed_count_and_lookup_prompt_for_recorded_jeepney(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection('Vehicle Registration Database (simulation)');
        $this->mockVehicleKnowledgeIndex([$database]);
        $this->mockConnectorManager();

        $this->postJson('/api/chatbot/ask', [
            'prompt' => 'ilan ang recorded jeepney? ilista ang mga plate number',
        ])
            ->assertOk()
            ->assertJsonPath('intent', 'lookup')
            ->assertJsonPath('grounded', true)
            ->assertJsonPath('table.rows.0.resource', 'vehicles')
            ->assertJsonPath('table.rows.0.license_number', 'JEP-1001')
            ->assertJsonPath('table.rows.1.license_number', 'JEP-1002')
            ->assertJsonPath('answer', 'May 2 grounded matches ako para sa jeep, at narito ang na-verify na listahan ng mga license number.')
            ->assertJsonCount(2, 'table.rows');
    }

    public function test_chatbot_answers_tagalog_filtered_vehicle_count_in_tagalog(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection('SP Database');
        $this->mockVehicleKnowledgeIndex([$database]);
        $this->mockConnectorManager();

        $this->postJson('/api/chatbot/ask', [
            'prompt' => 'ilan ang sedan na sasakyan?',
        ])
            ->assertOk()
            ->assertJsonPath('intent', 'count')
            ->assertJsonPath('language_style', 'tagalog')
            ->assertJsonPath('table.rows.0.resource', 'vehicles')
            ->assertJsonPath('table.rows.0.record_count', 1)
            ->assertJsonPath('answer', 'Nabilang ko ang 1 na tumutugmang records para sa sedan mula sa 1 grounded data source.');
    }

    public function test_chatbot_answers_short_tagalog_count_prompt_in_tagalog(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection('SP Database');
        $this->mockVehicleKnowledgeIndex([$database]);
        $this->mockConnectorManager();

        $this->postJson('/api/chatbot/ask', [
            'prompt' => 'ilan ang jeep?',
        ])
            ->assertOk()
            ->assertJsonPath('intent', 'count')
            ->assertJsonPath('language_style', 'tagalog')
            ->assertJsonPath('answer', 'Nabilang ko ang 93 na tumutugmang records para sa jeep mula sa 1 grounded data source.');
    }

    public function test_chatbot_can_count_recorded_ebike_from_vehicle_sources(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection('SP Database');
        $this->mockVehicleKnowledgeIndex([$database]);
        $this->mockConnectorManager();

        $this->postJson('/api/chatbot/ask', [
            'prompt' => 'ilan ang recorded EBike?',
        ])
            ->assertOk()
            ->assertJsonPath('intent', 'count')
            ->assertJsonPath('grounded', true)
            ->assertJsonPath('table.rows.0.resource', 'vehicles')
            ->assertJsonPath('table.rows.0.record_count', 12)
            ->assertJsonPath('answer', 'Nabilang ko ang 12 na tumutugmang records para sa ebike mula sa 1 grounded data source.')
            ->assertJsonCount(1, 'table.rows');
    }

    public function test_chatbot_can_count_recorded_ebike_from_vehicle_sources_in_english(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection('SP Database');
        $this->mockVehicleKnowledgeIndex([$database]);
        $this->mockConnectorManager();

        $this->postJson('/api/chatbot/ask', [
            'prompt' => 'how many recorded EBike?',
        ])
            ->assertOk()
            ->assertJsonPath('intent', 'count')
            ->assertJsonPath('grounded', true)
            ->assertJsonPath('table.rows.0.resource', 'vehicles')
            ->assertJsonPath('table.rows.0.record_count', 12)
            ->assertJsonCount(1, 'table.rows');
    }

    public function test_chatbot_can_map_gate_name_count_question_with_typo_tolerant_nlp(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection('Vehicle Registration Database (simulation)');
        $this->mockVehicleKnowledgeIndex([$database]);
        $this->mockConnectorManager();

        $this->postJson('/api/chatbot/ask', [
            'prompt' => 'how may entries logged in Agapita?',
        ])
            ->assertOk()
            ->assertJsonPath('intent', 'count')
            ->assertJsonPath('grounded', true)
            ->assertJsonPath('table.rows.0.resource', 'vehicle_entries')
            ->assertJsonPath('table.rows.0.record_count', 37)
            ->assertJsonCount(1, 'table.rows');
    }

    public function test_chatbot_can_continue_count_conversation_with_follow_up_location_prompt(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection('Vehicle Registration Database (simulation)');
        $this->mockVehicleKnowledgeIndex([$database]);
        $this->mockConnectorManager();

        $first = $this->postJson('/api/chatbot/ask', [
            'prompt' => 'how many entries in Agapita?',
        ]);

        $first->assertOk()
            ->assertJsonPath('intent', 'count')
            ->assertJsonPath('table.rows.0.resource', 'vehicle_entries')
            ->assertJsonPath('table.rows.0.record_count', 37);

        $this->postJson('/api/chatbot/ask', [
            'context_id' => $first->json('context_id'),
            'prompt' => 'in Raymundo?',
        ])
            ->assertOk()
            ->assertJsonPath('intent', 'count')
            ->assertJsonPath('grounded', true)
            ->assertJsonPath('table.rows.0.resource', 'vehicle_entries')
            ->assertJsonPath('table.rows.0.record_count', 19)
            ->assertJsonCount(1, 'table.rows')
            ->assertJsonCount(4, 'history');
    }

    public function test_chatbot_prefers_enrollment_status_source_for_student_status_count(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection('Student Registry System (simulation)');
        $this->mockStudentKnowledgeIndex([$database]);
        $this->mockConnectorManager();

        $this->postJson('/api/chatbot/ask', [
            'prompt' => 'ilan ang enrolled students?',
        ])
            ->assertOk()
            ->assertJsonPath('intent', 'count')
            ->assertJsonPath('grounded', true)
            ->assertJsonPath('language_style', 'tagalog')
            ->assertJsonPath('table.rows.0.resource', 'student_enrollments')
            ->assertJsonPath('table.rows.0.record_count', 184)
            ->assertJsonPath('answer', 'Nabilang ko ang 184 na tumutugmang records para sa enrolled mula sa 1 grounded data source.')
            ->assertJsonCount(1, 'table.rows');
    }

    public function test_chatbot_can_continue_student_status_count_with_follow_up_prompt(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection('Student Registry System (simulation)');
        $this->mockStudentKnowledgeIndex([$database]);
        $this->mockConnectorManager();

        $first = $this->postJson('/api/chatbot/ask', [
            'prompt' => 'ilan ang enrolled students?',
        ]);

        $first->assertOk()
            ->assertJsonPath('table.rows.0.resource', 'student_enrollments')
            ->assertJsonPath('table.rows.0.record_count', 184);

        $this->postJson('/api/chatbot/ask', [
            'context_id' => $first->json('context_id'),
            'prompt' => 'ilan ang pending?',
        ])
            ->assertOk()
            ->assertJsonPath('intent', 'count')
            ->assertJsonPath('grounded', true)
            ->assertJsonPath('table.rows.0.resource', 'student_enrollments')
            ->assertJsonPath('table.rows.0.record_count', 11)
            ->assertJsonCount(1, 'table.rows')
            ->assertJsonCount(4, 'history');
    }

    public function test_chatbot_can_interpret_active_enrollees_as_enrolled_students(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection('Student Registry System (simulation)');
        $this->mockStudentKnowledgeIndex([$database]);
        $this->mockConnectorManager();

        $this->postJson('/api/chatbot/ask', [
            'prompt' => 'gaano karami ang active enrollees?',
        ])
            ->assertOk()
            ->assertJsonPath('intent', 'count')
            ->assertJsonPath('grounded', true)
            ->assertJsonPath('table.rows.0.resource', 'student_enrollments')
            ->assertJsonPath('table.rows.0.record_count', 184)
            ->assertJsonCount(1, 'table.rows');
    }

    public function test_chatbot_can_interpret_active_students_as_enrolled_students(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection('Student Registry System (simulation)');
        $this->mockStudentKnowledgeIndex([$database]);
        $this->mockConnectorManager();

        $this->postJson('/api/chatbot/ask', [
            'prompt' => 'how many active students?',
        ])
            ->assertOk()
            ->assertJsonPath('intent', 'count')
            ->assertJsonPath('grounded', true)
            ->assertJsonPath('table.rows.0.resource', 'student_enrollments')
            ->assertJsonPath('table.rows.0.record_count', 184)
            ->assertJsonCount(1, 'table.rows');
    }

    public function test_chatbot_does_not_inherit_vehicle_context_for_self_contained_student_query(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $vehicleDatabase = $this->makeDatabaseConnection('Vehicle Registration Database (simulation)');
        $studentDatabase = $this->makeDatabaseConnection('Student Registry System (simulation)');
        $this->mockCombinedKnowledgeIndex([$vehicleDatabase, $studentDatabase]);
        $this->mockConnectorManager();

        $first = $this->postJson('/api/chatbot/ask', [
            'prompt' => 'how many recorded EBike?',
        ]);

        $first->assertOk()
            ->assertJsonPath('intent', 'count')
            ->assertJsonPath('table.rows.0.resource', 'vehicles')
            ->assertJsonPath('table.rows.0.record_count', 12);

        $this->postJson('/api/chatbot/ask', [
            'context_id' => $first->json('context_id'),
            'prompt' => 'how many enrolled students?',
        ])
            ->assertOk()
            ->assertJsonPath('intent', 'count')
            ->assertJsonPath('grounded', true)
            ->assertJsonPath('table.rows.0.resource', 'student_enrollments')
            ->assertJsonPath('table.rows.0.record_count', 184)
            ->assertJsonCount(1, 'table.rows');
    }

    public function test_chatbot_does_not_inherit_student_context_for_self_contained_vehicle_query(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $studentDatabase = $this->makeDatabaseConnection('Student Registry System (simulation)');
        $vehicleDatabase = $this->makeDatabaseConnection('Vehicle Registration Database (simulation)');
        $this->mockCombinedKnowledgeIndex([$studentDatabase, $vehicleDatabase]);
        $this->mockConnectorManager();

        $first = $this->postJson('/api/chatbot/ask', [
            'prompt' => 'ilan ang enrolled students?',
        ]);

        $first->assertOk()
            ->assertJsonPath('intent', 'count')
            ->assertJsonPath('table.rows.0.resource', 'student_enrollments')
            ->assertJsonPath('table.rows.0.record_count', 184);

        $this->postJson('/api/chatbot/ask', [
            'context_id' => $first->json('context_id'),
            'prompt' => 'how many recorded EBike?',
        ])
            ->assertOk()
            ->assertJsonPath('intent', 'count')
            ->assertJsonPath('grounded', true)
            ->assertJsonPath('table.rows.0.resource', 'vehicles')
            ->assertJsonPath('table.rows.0.record_count', 12)
            ->assertJsonCount(1, 'table.rows');
    }

    public function test_chatbot_can_answer_approved_sticker_application_reviewed_by_specific_staff(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection('Vehicle Registration Database (simulation)');
        $this->mockStickerKnowledgeIndex([$database]);
        $this->mockConnectorManager();

        $this->postJson('/api/chatbot/ask', [
            'prompt' => 'ilan ang approved sticker application reviewed by Ms. Villanueva?',
        ])
            ->assertOk()
            ->assertJsonPath('intent', 'count')
            ->assertJsonPath('grounded', true)
            ->assertJsonPath('table.rows.0.resource', 'sticker_applications')
            ->assertJsonPath('table.rows.0.record_count', 41)
            ->assertJsonPath('chart', null)
            ->assertJsonPath('facts.0', 'Total records: 41')
            ->assertJsonPath('answer', 'Nabilang ko ang 41 na tumutugmang sticker applications.')
            ->assertJsonCount(1, 'table.rows');
    }

    public function test_chatbot_explains_when_no_sticker_application_matches_reviewer_filter(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection('Vehicle Registration Database (simulation)');
        $this->mockStickerKnowledgeIndex([$database]);
        $this->mockConnectorManager();

        $this->postJson('/api/chatbot/ask', [
            'prompt' => 'ilan ang approved sticker application reviewed by Engr. Navarro?',
        ])
            ->assertOk()
            ->assertJsonPath('intent', 'count')
            ->assertJsonPath('grounded', true)
            ->assertJsonPath('table.rows.0.resource', 'sticker_applications')
            ->assertJsonPath('table.rows.0.record_count', 0)
            ->assertJsonPath('answer', 'Wala akong nahanap na tumutugmang sticker applications para sa approved reviewed by engr navarro sa grounded data.')
            ->assertJsonCount(1, 'table.rows');
    }

    public function test_chatbot_can_match_reviewer_titles_with_punctuation_insensitive_search(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection('Vehicle Registration Database (simulation)');
        $this->mockStickerKnowledgeIndex([$database]);
        $this->mockConnectorManager();

        $this->postJson('/api/chatbot/ask', [
            'prompt' => 'ilan ang approved sticker application reviewed by Engr. Santos?',
        ])
            ->assertOk()
            ->assertJsonPath('intent', 'count')
            ->assertJsonPath('grounded', true)
            ->assertJsonPath('table.rows.0.resource', 'sticker_applications')
            ->assertJsonPath('table.rows.0.record_count', 2)
            ->assertJsonPath('answer', 'Nabilang ko ang 2 na tumutugmang sticker applications.')
            ->assertJsonCount(1, 'table.rows');
    }

    public function test_tagalog_prompt_keeps_localized_answer_even_when_llm_formatter_is_bound(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $database = $this->makeDatabaseConnection('SP Database');
        $this->mockVehicleKnowledgeIndex([$database]);
        $this->mockConnectorManager();

        $formatter = Mockery::mock(ChatbotLanguageModel::class);
        $formatter->shouldReceive('formatGroundedResponse')->never();
        $this->app->instance(ChatbotLanguageModel::class, $formatter);

        $this->postJson('/api/chatbot/ask', [
            'prompt' => 'ilan ang jeep?',
        ])
            ->assertOk()
            ->assertJsonPath('language_style', 'tagalog')
            ->assertJsonPath('answer', 'Nabilang ko ang 93 na tumutugmang records para sa jeep mula sa 1 grounded data source.');
    }

    public function test_chatbot_exposes_knowledge_status_and_supports_history_reset_without_db_selection(): void
    {
        Sanctum::actingAs($this->makeApprovedEndUser());
        $first = $this->makeDatabaseConnection('Operations DB');
        $second = $this->makeDatabaseConnection('Reports DB');
        $this->mockKnowledgeIndex([$first, $second]);
        $this->mockConnectorManager();

        $this->getJson('/api/chatbot/knowledge/status')
            ->assertOk()
            ->assertJsonPath('summary.accessible_database_count', 2);

        $this->postJson('/api/chatbot/ask', [
            'prompt' => 'Show me summary of the available data.',
        ])->assertOk();

        $this->getJson('/api/chatbot/history')
            ->assertOk()
            ->assertJsonCount(2, 'messages');

        $this->postJson('/api/chatbot/reset', [])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->getJson('/api/chatbot/history')
            ->assertOk()
            ->assertJsonCount(0, 'messages');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeApprovedEndUser(): User
    {
        return User::factory()->create([
            'approval_status' => User::APPROVAL_APPROVED,
            'role' => User::ROLE_END_USER,
        ]);
    }

    private function makeDatabaseConnection(string $name): ConnectedDatabase
    {
        $database = new ConnectedDatabase();
        $database->name = $name;
        $database->type = ConnectedDatabase::TYPE_POSTGRESQL;
        $database->host = '127.0.0.1';
        $database->port = 5432;
        $database->database_name = 'smart_acsess';
        $database->username = 'postgres';
        $database->password = 'secret';
        $database->connection_string = 'postgresql://postgres:secret@127.0.0.1:5432/smart_acsess';
        $database->save();

        return $database;
    }

    private function mockKnowledgeIndex(array $databases): void
    {
        $service = Mockery::mock(ChatbotKnowledgeIndexService::class);
        $service->shouldReceive('loadKnowledgeForUser')->andReturn([
            'databases' => array_map(fn(ConnectedDatabase $database) => $database->publicMetadata(), $databases),
            'snapshots' => [
                [
                    'database' => $databases[0]->publicMetadata(),
                    'resource_type' => 'table',
                    'resource_profiles' => [
                        [
                            'resource' => 'incidents',
                            'record_count' => 100,
                            'description' => 'Incidents dataset',
                            'semantic_terms' => ['incidents', 'reports'],
                            'detected' => ['date_column' => 'created_at', 'group_column' => 'status'],
                        ],
                    ],
                ],
                [
                    'database' => $databases[1]->publicMetadata(),
                    'resource_type' => 'table',
                    'resource_profiles' => [
                        [
                            'resource' => 'reports',
                            'record_count' => 55,
                            'description' => 'Reports dataset',
                            'semantic_terms' => ['reports', 'status'],
                            'detected' => ['date_column' => 'created_at', 'group_column' => 'status'],
                        ],
                    ],
                ],
            ],
            'statuses' => array_map(fn(ConnectedDatabase $database) => [
                'database' => $database->publicMetadata(),
                'status' => 'ready',
                'overview' => ['known_record_total' => $database->name === 'Operations DB' ? 100 : 55],
            ], $databases),
        ]);
        $service->shouldReceive('statusForUser')->andReturn([
            'databases' => array_map(fn(ConnectedDatabase $database) => ['database' => $database->publicMetadata(), 'status' => 'ready'], $databases),
            'summary' => ['accessible_database_count' => count($databases)],
        ]);

        $this->app->instance(ChatbotKnowledgeIndexService::class, $service);
    }

    private function mockVehicleKnowledgeIndex(array $databases): void
    {
        $service = Mockery::mock(ChatbotKnowledgeIndexService::class);
        $service->shouldReceive('loadKnowledgeForUser')->andReturn([
            'databases' => array_map(fn(ConnectedDatabase $database) => $database->publicMetadata(), $databases),
            'snapshots' => [
                [
                    'database' => $databases[0]->publicMetadata(),
                    'resource_type' => 'table',
                    'resource_profiles' => [
                        [
                            'resource' => 'vehicles',
                            'record_count' => 501,
                            'description' => 'Vehicle registry with registered vehicle records, vehicle color, vehicle class, and license plate information.',
                            'columns' => [
                                ['name' => 'license_plate_number', 'type' => 'varchar'],
                                ['name' => 'vehicle_color', 'type' => 'varchar'],
                                ['name' => 'vehicle_class', 'type' => 'USER-DEFINED'],
                                ['name' => 'owner_name', 'type' => 'varchar'],
                            ],
                            'column_names' => ['license_plate_number', 'vehicle_color', 'vehicle_class', 'owner_name'],
                            'sample_rows' => [
                                [
                                    'license_plate_number' => 'ABC-1234',
                                    'vehicle_color' => 'Green',
                                    'vehicle_class' => 'Sedan',
                                    'owner_name' => 'Juan Dela Cruz',
                                ],
                                [
                                    'license_plate_number' => 'JEP-1001',
                                    'vehicle_color' => 'Yellow',
                                    'vehicle_class' => 'Jeep',
                                    'owner_name' => 'Ramon Cruz',
                                ],
                            ],
                            'semantic_terms' => ['vehicles', 'vehicle registry', 'registered vehicle', 'license plate', 'plate number', 'vehicle color', 'vehicle class', 'sedan', 'jeep', 'jeepney', 'ebike', 'e-bike', 'electric bike'],
                            'detected' => ['date_column' => null, 'group_column' => 'vehicle_class'],
                        ],
                        [
                            'resource' => 'vehicle_movements',
                            'record_count' => 1010,
                            'description' => 'Vehicle movement records keyed by license plate number.',
                            'columns' => [
                                ['name' => 'license_plate_number', 'type' => 'varchar'],
                                ['name' => 'timestamp', 'type' => 'timestamp'],
                            ],
                            'column_names' => ['license_plate_number', 'timestamp'],
                            'sample_rows' => [
                                [
                                    'license_plate_number' => 'ABC-1234',
                                    'timestamp' => '2026-04-11T18:58:31Z',
                                ],
                            ],
                            'semantic_terms' => ['vehicle movements', 'vehicle movement', 'license plate number', 'traffic'],
                            'detected' => ['date_column' => 'timestamp', 'group_column' => 'license_plate_number'],
                        ],
                        [
                            'resource' => 'vehicle_entries',
                            'record_count' => 400,
                            'description' => 'Vehicle entry logs with gate name and entry time.',
                            'columns' => [
                                ['name' => 'gate_name', 'type' => 'varchar'],
                                ['name' => 'entry_time', 'type' => 'timestamp'],
                                ['name' => 'captured_by', 'type' => 'varchar'],
                            ],
                            'column_names' => ['gate_name', 'entry_time', 'captured_by'],
                            'sample_rows' => [
                                [
                                    'gate_name' => 'Agapita',
                                    'entry_time' => '2026-04-11T18:58:31Z',
                                    'captured_by' => 'Scanner 1',
                                ],
                            ],
                             'top_groups' => [
                                 ['label' => 'Agapita', 'value' => 37],
                                 ['label' => 'Raymundo', 'value' => 19],
                                 ['label' => 'Lopez', 'value' => 22],
                             ],
                             'semantic_terms' => ['vehicle entries', 'entry logs', 'gate name', 'agapita', 'raymundo', 'lopez'],
                             'detected' => ['date_column' => 'entry_time', 'group_column' => 'gate_name'],
                         ],
                     ],
                 ],
            ],
            'statuses' => array_map(fn(ConnectedDatabase $database) => [
                'database' => $database->publicMetadata(),
                'status' => 'ready',
                'overview' => ['known_record_total' => 501],
            ], $databases),
        ]);
        $service->shouldReceive('statusForUser')->andReturn([
            'databases' => array_map(fn(ConnectedDatabase $database) => ['database' => $database->publicMetadata(), 'status' => 'ready'], $databases),
            'summary' => ['accessible_database_count' => count($databases)],
        ]);

        $this->app->instance(ChatbotKnowledgeIndexService::class, $service);
    }

    private function mockStudentKnowledgeIndex(array $databases): void
    {
        $service = Mockery::mock(ChatbotKnowledgeIndexService::class);
        $service->shouldReceive('loadKnowledgeForUser')->andReturn([
            'databases' => array_map(fn(ConnectedDatabase $database) => $database->publicMetadata(), $databases),
            'snapshots' => [
                [
                    'database' => $databases[0]->publicMetadata(),
                    'resource_type' => 'table',
                    'resource_profiles' => [
                        [
                            'resource' => 'student_enrollments',
                            'record_count' => 200,
                            'description' => 'Student enrollment records with enrollment_status and date_enrolled.',
                            'columns' => [
                                ['name' => 'student_id', 'type' => 'bigint'],
                                ['name' => 'academic_year', 'type' => 'varchar'],
                                ['name' => 'semester', 'type' => 'varchar'],
                                ['name' => 'date_enrolled', 'type' => 'date'],
                                ['name' => 'enrollment_status', 'type' => 'enum'],
                            ],
                            'column_names' => ['student_id', 'academic_year', 'semester', 'date_enrolled', 'enrollment_status'],
                            'sample_rows' => [
                                [
                                    'student_id' => 1,
                                    'academic_year' => '2025-2026',
                                    'semester' => '1st Semester',
                                    'date_enrolled' => '2025-08-13',
                                    'enrollment_status' => 'Enrolled',
                                ],
                                [
                                    'student_id' => 199,
                                    'academic_year' => '2025-2026',
                                    'semester' => '2nd Semester',
                                    'date_enrolled' => '2025-09-14',
                                    'enrollment_status' => 'Pending',
                                ],
                            ],
                            'semantic_terms' => ['student enrollments', 'student enrollment', 'students', 'enrollment status', 'enrolled', 'pending', 'cancelled'],
                            'detected' => ['date_column' => 'date_enrolled', 'group_column' => 'enrollment_status'],
                        ],
                        [
                            'resource' => 'students',
                            'record_count' => 200,
                            'description' => 'Student master list with a boolean enrolled flag.',
                            'columns' => [
                                ['name' => 'student_no', 'type' => 'varchar'],
                                ['name' => 'full_name', 'type' => 'varchar'],
                                ['name' => 'enrolled', 'type' => 'boolean'],
                            ],
                            'column_names' => ['student_no', 'full_name', 'enrolled'],
                            'sample_rows' => [
                                [
                                    'student_no' => '2024-0001',
                                    'full_name' => 'Ana Santos',
                                    'enrolled' => true,
                                ],
                            ],
                            'semantic_terms' => ['students', 'student list', 'enrolled'],
                            'detected' => ['date_column' => null, 'group_column' => 'enrolled'],
                        ],
                    ],
                ],
            ],
            'statuses' => array_map(fn(ConnectedDatabase $database) => [
                'database' => $database->publicMetadata(),
                'status' => 'ready',
                'overview' => ['known_record_total' => 400],
            ], $databases),
        ]);
        $service->shouldReceive('statusForUser')->andReturn([
            'databases' => array_map(fn(ConnectedDatabase $database) => ['database' => $database->publicMetadata(), 'status' => 'ready'], $databases),
            'summary' => ['accessible_database_count' => count($databases)],
        ]);

        $this->app->instance(ChatbotKnowledgeIndexService::class, $service);
    }

    private function mockStickerKnowledgeIndex(array $databases): void
    {
        $service = Mockery::mock(ChatbotKnowledgeIndexService::class);
        $service->shouldReceive('loadKnowledgeForUser')->andReturn([
            'databases' => array_map(fn(ConnectedDatabase $database) => $database->publicMetadata(), $databases),
            'snapshots' => [
                [
                    'database' => $databases[0]->publicMetadata(),
                    'resource_type' => 'table',
                    'resource_profiles' => [
                        [
                            'resource' => 'sticker_applications',
                            'record_count' => 200,
                            'description' => 'Sticker application records with approval status and reviewed_by staff metadata.',
                            'columns' => [
                                ['name' => 'application_no', 'type' => 'varchar'],
                                ['name' => 'application_date', 'type' => 'date'],
                                ['name' => 'sticker_type', 'type' => 'varchar'],
                                ['name' => 'status', 'type' => 'varchar'],
                                ['name' => 'reviewed_by', 'type' => 'varchar'],
                            ],
                            'column_names' => ['application_no', 'application_date', 'sticker_type', 'status', 'reviewed_by'],
                            'sample_rows' => [
                                [
                                    'application_no' => 'UPS-2026-00001',
                                    'application_date' => '2026-04-11',
                                    'sticker_type' => 'annual',
                                    'status' => 'approved',
                                    'reviewed_by' => 'Ms. Villanueva',
                                ],
                                [
                                    'application_no' => 'UPS-2026-00006',
                                    'application_date' => '2026-03-27',
                                    'sticker_type' => 'annual',
                                    'status' => 'approved',
                                    'reviewed_by' => 'Engr. Santos',
                                ],
                            ],
                            'semantic_terms' => ['sticker applications', 'sticker application', 'sticker', 'approved', 'reviewed by', 'reviewed_by', 'ms villanueva', 'engr santos'],
                            'detected' => ['date_column' => 'application_date', 'group_column' => 'status'],
                        ],
                        [
                            'resource' => 'stickers',
                            'record_count' => 120,
                            'description' => 'Issued sticker records with sticker status and release metadata.',
                            'columns' => [
                                ['name' => 'sticker_no', 'type' => 'varchar'],
                                ['name' => 'sticker_type', 'type' => 'varchar'],
                                ['name' => 'status', 'type' => 'varchar'],
                            ],
                            'column_names' => ['sticker_no', 'sticker_type', 'status'],
                            'sample_rows' => [
                                [
                                    'sticker_no' => 'ST-0001',
                                    'sticker_type' => 'annual',
                                    'status' => 'released',
                                ],
                            ],
                            'semantic_terms' => ['stickers', 'issued stickers', 'sticker status'],
                            'detected' => ['date_column' => null, 'group_column' => 'status'],
                        ],
                        [
                            'resource' => 'vehicle_documents',
                            'record_count' => 600,
                            'description' => 'Uploaded vehicle documents for verification.',
                            'columns' => [
                                ['name' => 'document_no', 'type' => 'varchar'],
                                ['name' => 'status', 'type' => 'varchar'],
                            ],
                            'column_names' => ['document_no', 'status'],
                            'sample_rows' => [
                                [
                                    'document_no' => 'DOC-0001',
                                    'status' => 'approved',
                                ],
                            ],
                            'semantic_terms' => ['vehicle documents', 'document verification'],
                            'detected' => ['date_column' => null, 'group_column' => 'status'],
                        ],
                    ],
                ],
            ],
            'statuses' => array_map(fn(ConnectedDatabase $database) => [
                'database' => $database->publicMetadata(),
                'status' => 'ready',
                'overview' => ['known_record_total' => 920],
            ], $databases),
        ]);
        $service->shouldReceive('statusForUser')->andReturn([
            'databases' => array_map(fn(ConnectedDatabase $database) => ['database' => $database->publicMetadata(), 'status' => 'ready'], $databases),
            'summary' => ['accessible_database_count' => count($databases)],
        ]);

        $this->app->instance(ChatbotKnowledgeIndexService::class, $service);
    }

    private function mockCombinedKnowledgeIndex(array $databases): void
    {
        $snapshots = array_map(function (ConnectedDatabase $database) {
            $profiles = str_contains(strtolower($database->name), 'student')
                ? $this->studentResourceProfiles()
                : $this->vehicleResourceProfiles();

            return [
                'database' => $database->publicMetadata(),
                'resource_type' => 'table',
                'resource_profiles' => $profiles,
            ];
        }, $databases);

        $service = Mockery::mock(ChatbotKnowledgeIndexService::class);
        $service->shouldReceive('loadKnowledgeForUser')->andReturn([
            'databases' => array_map(fn(ConnectedDatabase $database) => $database->publicMetadata(), $databases),
            'snapshots' => $snapshots,
            'statuses' => array_map(fn(ConnectedDatabase $database) => [
                'database' => $database->publicMetadata(),
                'status' => 'ready',
                'overview' => ['known_record_total' => 400],
            ], $databases),
        ]);
        $service->shouldReceive('statusForUser')->andReturn([
            'databases' => array_map(fn(ConnectedDatabase $database) => ['database' => $database->publicMetadata(), 'status' => 'ready'], $databases),
            'summary' => ['accessible_database_count' => count($databases)],
        ]);

        $this->app->instance(ChatbotKnowledgeIndexService::class, $service);
    }

    private function vehicleResourceProfiles(): array
    {
        return [
            [
                'resource' => 'vehicles',
                'record_count' => 501,
                'description' => 'Vehicle registry with registered vehicle records, vehicle color, vehicle class, and license plate information.',
                'columns' => [
                    ['name' => 'license_plate_number', 'type' => 'varchar'],
                    ['name' => 'vehicle_color', 'type' => 'varchar'],
                    ['name' => 'vehicle_class', 'type' => 'USER-DEFINED'],
                    ['name' => 'owner_name', 'type' => 'varchar'],
                ],
                'column_names' => ['license_plate_number', 'vehicle_color', 'vehicle_class', 'owner_name'],
                'sample_rows' => [
                    [
                        'license_plate_number' => 'ABC-1234',
                        'vehicle_color' => 'Green',
                        'vehicle_class' => 'Sedan',
                        'owner_name' => 'Juan Dela Cruz',
                    ],
                    [
                        'license_plate_number' => 'JEP-1001',
                        'vehicle_color' => 'Yellow',
                        'vehicle_class' => 'Jeep',
                        'owner_name' => 'Ramon Cruz',
                    ],
                ],
                'semantic_terms' => ['vehicles', 'vehicle registry', 'registered vehicle', 'license plate', 'plate number', 'vehicle color', 'vehicle class', 'sedan', 'jeep', 'jeepney', 'ebike', 'e-bike', 'electric bike'],
                'detected' => ['date_column' => null, 'group_column' => 'vehicle_class'],
            ],
            [
                'resource' => 'vehicle_movements',
                'record_count' => 1010,
                'description' => 'Vehicle movement records keyed by license plate number.',
                'columns' => [
                    ['name' => 'license_plate_number', 'type' => 'varchar'],
                    ['name' => 'timestamp', 'type' => 'timestamp'],
                ],
                'column_names' => ['license_plate_number', 'timestamp'],
                'sample_rows' => [
                    [
                        'license_plate_number' => 'ABC-1234',
                        'timestamp' => '2026-04-11T18:58:31Z',
                    ],
                ],
                'semantic_terms' => ['vehicle movements', 'vehicle movement', 'license plate number', 'traffic'],
                'detected' => ['date_column' => 'timestamp', 'group_column' => 'license_plate_number'],
            ],
            [
                'resource' => 'vehicle_entries',
                'record_count' => 400,
                'description' => 'Vehicle entry logs with gate name and entry time.',
                'columns' => [
                    ['name' => 'gate_name', 'type' => 'varchar'],
                    ['name' => 'entry_time', 'type' => 'timestamp'],
                    ['name' => 'captured_by', 'type' => 'varchar'],
                ],
                'column_names' => ['gate_name', 'entry_time', 'captured_by'],
                'sample_rows' => [
                    [
                        'gate_name' => 'Agapita',
                        'entry_time' => '2026-04-11T18:58:31Z',
                        'captured_by' => 'Scanner 1',
                    ],
                ],
                'top_groups' => [
                    ['label' => 'Agapita', 'value' => 37],
                    ['label' => 'Raymundo', 'value' => 19],
                    ['label' => 'Lopez', 'value' => 22],
                ],
                'semantic_terms' => ['vehicle entries', 'entry logs', 'gate name', 'agapita', 'raymundo', 'lopez'],
                'detected' => ['date_column' => 'entry_time', 'group_column' => 'gate_name'],
            ],
        ];
    }

    private function studentResourceProfiles(): array
    {
        return [
            [
                'resource' => 'student_enrollments',
                'record_count' => 200,
                'description' => 'Student enrollment records with enrollment_status and date_enrolled.',
                'columns' => [
                    ['name' => 'student_id', 'type' => 'bigint'],
                    ['name' => 'academic_year', 'type' => 'varchar'],
                    ['name' => 'semester', 'type' => 'varchar'],
                    ['name' => 'date_enrolled', 'type' => 'date'],
                    ['name' => 'enrollment_status', 'type' => 'enum'],
                ],
                'column_names' => ['student_id', 'academic_year', 'semester', 'date_enrolled', 'enrollment_status'],
                'sample_rows' => [
                    [
                        'student_id' => 1,
                        'academic_year' => '2025-2026',
                        'semester' => '1st Semester',
                        'date_enrolled' => '2025-08-13',
                        'enrollment_status' => 'Enrolled',
                    ],
                    [
                        'student_id' => 199,
                        'academic_year' => '2025-2026',
                        'semester' => '2nd Semester',
                        'date_enrolled' => '2025-09-14',
                        'enrollment_status' => 'Pending',
                    ],
                ],
                'semantic_terms' => ['student enrollments', 'student enrollment', 'students', 'enrollment status', 'enrolled', 'pending', 'cancelled'],
                'detected' => ['date_column' => 'date_enrolled', 'group_column' => 'enrollment_status'],
            ],
            [
                'resource' => 'students',
                'record_count' => 200,
                'description' => 'Student master list with a boolean enrolled flag.',
                'columns' => [
                    ['name' => 'student_no', 'type' => 'varchar'],
                    ['name' => 'full_name', 'type' => 'varchar'],
                    ['name' => 'enrolled', 'type' => 'boolean'],
                ],
                'column_names' => ['student_no', 'full_name', 'enrolled'],
                'sample_rows' => [
                    [
                        'student_no' => '2024-0001',
                        'full_name' => 'Ana Santos',
                        'enrolled' => true,
                    ],
                ],
                'semantic_terms' => ['students', 'student list', 'enrolled'],
                'detected' => ['date_column' => null, 'group_column' => 'enrolled'],
            ],
        ];
    }

    private function mockConnectorManager(): void
    {
        $manager = Mockery::mock(DatabaseConnectorManager::class);
        $manager->shouldReceive('for')->andReturn(new class implements DatabaseConnector {
            public function resourceType(): string
            {
                return 'table';
            }

            public function testConnection(): array
            {
                return ['message' => 'ok'];
            }

            public function listResources(): array
            {
                return ['incidents', 'reports', 'vehicles', 'vehicle_movements', 'vehicle_entries', 'student_enrollments', 'students', 'sticker_applications', 'stickers', 'vehicle_documents'];
            }

            public function getSchema(?string $resource = null): array
            {
                if ($resource === 'vehicles') {
                    return [[
                        'table' => 'vehicles',
                        'resource' => 'vehicles',
                        'columns' => [
                            ['name' => 'license_plate_number', 'type' => 'varchar'],
                            ['name' => 'vehicle_color', 'type' => 'varchar'],
                            ['name' => 'vehicle_class', 'type' => 'USER-DEFINED'],
                            ['name' => 'owner_name', 'type' => 'varchar'],
                        ],
                    ]];
                }

                if ($resource === 'vehicle_movements') {
                    return [[
                        'table' => 'vehicle_movements',
                        'resource' => 'vehicle_movements',
                        'columns' => [
                            ['name' => 'license_plate_number', 'type' => 'varchar'],
                            ['name' => 'timestamp', 'type' => 'timestamp'],
                        ],
                    ]];
                }

                if ($resource === 'vehicle_entries') {
                    return [[
                        'table' => 'vehicle_entries',
                        'resource' => 'vehicle_entries',
                        'columns' => [
                            ['name' => 'gate_name', 'type' => 'varchar'],
                            ['name' => 'entry_time', 'type' => 'timestamp'],
                            ['name' => 'captured_by', 'type' => 'varchar'],
                        ],
                    ]];
                }

                if ($resource === 'student_enrollments') {
                    return [[
                        'table' => 'student_enrollments',
                        'resource' => 'student_enrollments',
                        'columns' => [
                            ['name' => 'student_id', 'type' => 'bigint'],
                            ['name' => 'academic_year', 'type' => 'varchar'],
                            ['name' => 'semester', 'type' => 'varchar'],
                            ['name' => 'date_enrolled', 'type' => 'date'],
                            ['name' => 'enrollment_status', 'type' => 'enum'],
                        ],
                    ]];
                }

                if ($resource === 'students') {
                    return [[
                        'table' => 'students',
                        'resource' => 'students',
                        'columns' => [
                            ['name' => 'student_no', 'type' => 'varchar'],
                            ['name' => 'full_name', 'type' => 'varchar'],
                            ['name' => 'enrolled', 'type' => 'boolean'],
                        ],
                    ]];
                }

                if ($resource === 'sticker_applications') {
                    return [[
                        'table' => 'sticker_applications',
                        'resource' => 'sticker_applications',
                        'columns' => [
                            ['name' => 'application_no', 'type' => 'varchar'],
                            ['name' => 'application_date', 'type' => 'date'],
                            ['name' => 'sticker_type', 'type' => 'varchar'],
                            ['name' => 'status', 'type' => 'varchar'],
                            ['name' => 'reviewed_by', 'type' => 'varchar'],
                        ],
                    ]];
                }

                if ($resource === 'stickers') {
                    return [[
                        'table' => 'stickers',
                        'resource' => 'stickers',
                        'columns' => [
                            ['name' => 'sticker_no', 'type' => 'varchar'],
                            ['name' => 'sticker_type', 'type' => 'varchar'],
                            ['name' => 'status', 'type' => 'varchar'],
                        ],
                    ]];
                }

                if ($resource === 'vehicle_documents') {
                    return [[
                        'table' => 'vehicle_documents',
                        'resource' => 'vehicle_documents',
                        'columns' => [
                            ['name' => 'document_no', 'type' => 'varchar'],
                            ['name' => 'status', 'type' => 'varchar'],
                        ],
                    ]];
                }

                return [[
                    'table' => $resource ?? 'incidents',
                    'resource' => $resource ?? 'incidents',
                    'columns' => [
                        ['name' => 'status', 'type' => 'varchar'],
                        ['name' => 'created_at', 'type' => 'timestamp'],
                    ],
                ]];
            }

            public function previewRows(string $resource, array $filters = [], int $limit = 50): array
            {
                if ($resource === 'vehicles') {
                    $contains = collect((array) ($filters['contains'] ?? []))->keyBy('column');
                    $color = strtolower((string) data_get($contains, 'vehicle_color.value', ''));
                    $class = strtolower((string) data_get($contains, 'vehicle_class.value', ''));

                    $rows = [
                        [
                            'license_plate_number' => 'ABC-1234',
                            'vehicle_color' => 'Green',
                            'vehicle_class' => 'Sedan',
                            'owner_name' => 'Juan Dela Cruz',
                        ],
                        [
                            'license_plate_number' => 'XYZ-9876',
                            'vehicle_color' => 'Green',
                            'vehicle_class' => 'SUV',
                            'owner_name' => 'Maria Santos',
                        ],
                        [
                            'license_plate_number' => 'JEP-1001',
                            'vehicle_color' => 'Yellow',
                            'vehicle_class' => 'Jeep',
                            'owner_name' => 'Ramon Cruz',
                        ],
                        [
                            'license_plate_number' => 'JEP-1002',
                            'vehicle_color' => 'Green',
                            'vehicle_class' => 'Jeep',
                            'owner_name' => 'Liza Mendoza',
                        ],
                        [
                            'license_plate_number' => 'EBK-1201',
                            'vehicle_color' => 'Black',
                            'vehicle_class' => 'EBike',
                            'owner_name' => 'Paolo Rivera',
                        ],
                    ];

                    return array_values(array_filter($rows, function (array $row) use ($color, $class) {
                        if ($color !== '' && strtolower($row['vehicle_color']) !== $color) {
                            return false;
                        }

                        if ($class !== '' && strtolower($row['vehicle_class']) !== $class) {
                            return false;
                        }

                        return true;
                    }));
                }

                if ($resource === 'vehicle_movements') {
                    $in = collect((array) ($filters['in'] ?? []))->keyBy('column');
                    $plates = (array) data_get($in, 'license_plate_number.values', []);
                    $rows = [
                        [
                            'license_plate_number' => 'ABC-1234',
                            'timestamp' => '2026-04-11T18:58:31Z',
                        ],
                        [
                            'license_plate_number' => 'XYZ-9876',
                            'timestamp' => '2026-04-11T18:55:00Z',
                        ],
                    ];

                    if ($plates === []) {
                        return $rows;
                    }

                    return array_values(array_filter($rows, fn(array $row) => in_array($row['license_plate_number'], $plates, true)));
                }

                if ($resource === 'student_enrollments') {
                    $contains = collect((array) ($filters['contains'] ?? []))->keyBy('column');
                    $status = strtolower((string) data_get($contains, 'enrollment_status.value', ''));
                    $rows = [
                        ['student_id' => 1, 'enrollment_status' => 'Enrolled', 'date_enrolled' => '2025-08-13'],
                        ['student_id' => 2, 'enrollment_status' => 'Pending', 'date_enrolled' => '2025-09-02'],
                        ['student_id' => 3, 'enrollment_status' => 'Cancelled', 'date_enrolled' => '2025-09-04'],
                    ];

                    if ($status === '') {
                        return $rows;
                    }

                    return array_values(array_filter($rows, fn(array $row) => strtolower($row['enrollment_status']) === $status));
                }

                if ($resource === 'students') {
                    $equals = collect((array) ($filters['equals'] ?? []))->keyBy('column');
                    $enrolled = data_get($equals, 'enrolled.value', null);
                    $rows = [
                        ['student_no' => '2024-0001', 'full_name' => 'Ana Santos', 'enrolled' => true],
                        ['student_no' => '2024-0002', 'full_name' => 'Luis Reyes', 'enrolled' => false],
                    ];

                    if ($enrolled === null) {
                        return $rows;
                    }

                    return array_values(array_filter($rows, fn(array $row) => $row['enrolled'] === $enrolled));
                }

                if ($resource === 'sticker_applications') {
                    $contains = collect((array) ($filters['contains'] ?? []))->keyBy('column');
                    $status = strtolower((string) data_get($contains, 'status.value', ''));
                    $reviewedBy = strtolower((string) data_get($contains, 'reviewed_by.value', ''));
                    $rows = [
                        ['application_no' => 'UPS-2026-00001', 'status' => 'approved', 'reviewed_by' => 'Ms. Villanueva'],
                        ['application_no' => 'UPS-2026-00002', 'status' => 'approved', 'reviewed_by' => 'Mr. Dela Cruz'],
                        ['application_no' => 'UPS-2026-00006', 'status' => 'approved', 'reviewed_by' => 'Engr. Santos'],
                        ['application_no' => 'UPS-2026-00012', 'status' => 'approved', 'reviewed_by' => 'Engr. Santos'],
                    ];

                    return array_values(array_filter($rows, function (array $row) use ($status, $reviewedBy) {
                        if ($status !== '' && strtolower($row['status']) !== $status) {
                            return false;
                        }

                        if (
                            $reviewedBy !== ''
                            && !str_contains(str_replace('.', '', strtolower($row['reviewed_by'])), str_replace('.', '', $reviewedBy))
                        ) {
                            return false;
                        }

                        return true;
                    }));
                }

                 if ($resource === 'vehicle_entries') {
                     $contains = collect((array) ($filters['contains'] ?? []))->keyBy('column');
                     $gate = strtolower((string) data_get($contains, 'gate_name.value', ''));
                     $rows = [
                         ['gate_name' => 'Agapita', 'entry_time' => '2026-04-11T18:58:31Z', 'captured_by' => 'Scanner 1'],
                         ['gate_name' => 'Raymundo', 'entry_time' => '2026-04-11T18:56:00Z', 'captured_by' => 'Scanner 3'],
                         ['gate_name' => 'Lopez', 'entry_time' => '2026-04-11T18:55:00Z', 'captured_by' => 'Scanner 2'],
                     ];

                    if ($gate === '') {
                        return $rows;
                    }

                    return array_values(array_filter($rows, fn(array $row) => strtolower($row['gate_name']) === $gate));
                }

                return [['status' => 'Open', 'created_at' => '2026-04-01T00:00:00Z']];
            }

            public function paginateRows(string $resource, array $filters = [], int $page = 1, int $perPage = 25): array
            {
                return ['rows' => [], 'pagination' => ['page' => 1, 'per_page' => 25, 'total' => 0, 'last_page' => 1, 'from' => null, 'to' => null]];
            }

            public function countRecords(string $resource, array $filters = []): int|float
            {
                if ($resource === 'vehicles') {
                    $contains = collect((array) ($filters['contains'] ?? []))->keyBy('column');
                    $color = strtolower((string) data_get($contains, 'vehicle_color.value', ''));
                    $class = strtolower((string) data_get($contains, 'vehicle_class.value', ''));

                    if ($color === 'green' && $class === 'sedan') {
                        return 1;
                    }

                    if ($class === 'sedan') {
                        return 1;
                    }

                    if ($class === 'jeep') {
                        return 93;
                    }

                    if ($class === 'ebike') {
                        return 12;
                    }

                    return 501;
                }

                if ($resource === 'vehicle_movements') {
                    $in = collect((array) ($filters['in'] ?? []))->keyBy('column');
                    $plates = (array) data_get($in, 'license_plate_number.values', []);

                    if ($plates !== []) {
                        return count($plates);
                    }

                    return 1010;
                }

                if ($resource === 'student_enrollments') {
                    $contains = collect((array) ($filters['contains'] ?? []))->keyBy('column');
                    $status = strtolower((string) data_get($contains, 'enrollment_status.value', ''));

                    return match ($status) {
                        'enrolled' => 184,
                        'pending' => 11,
                        'cancelled' => 5,
                        'canceled' => 5,
                        default => 200,
                    };
                }

                if ($resource === 'students') {
                    $equals = collect((array) ($filters['equals'] ?? []))->keyBy('column');
                    $enrolled = data_get($equals, 'enrolled.value', null);

                    if ($enrolled === true) {
                        return 52;
                    }

                    if ($enrolled === false) {
                        return 148;
                    }

                    return 200;
                }

                if ($resource === 'sticker_applications') {
                    $contains = collect((array) ($filters['contains'] ?? []))->keyBy('column');
                    $status = strtolower((string) data_get($contains, 'status.value', ''));
                    $reviewedBy = strtolower((string) data_get($contains, 'reviewed_by.value', ''));

                    if ($status === 'approved' && str_contains(str_replace('.', '', $reviewedBy), 'villanueva')) {
                        return 41;
                    }

                    if ($status === 'approved' && str_contains(str_replace('.', '', $reviewedBy), 'engr santos')) {
                        return 2;
                    }

                    if ($status === 'approved' && $reviewedBy !== '') {
                        return 0;
                    }

                    if ($status === 'approved') {
                        return 158;
                    }

                    return 200;
                }

                if ($resource === 'stickers') {
                    return 120;
                }

                if ($resource === 'vehicle_documents') {
                    return 600;
                }

                 if ($resource === 'vehicle_entries') {
                      $contains = collect((array) ($filters['contains'] ?? []))->keyBy('column');
                     $gate = strtolower((string) data_get($contains, 'gate_name.value', ''));

                     if ($gate === 'agapita') {
                         return 37;
                     }

                     if ($gate === 'raymundo') {
                         return 19;
                     }

                     if ($gate === 'lopez') {
                         return 22;
                     }

                    return 400;
                }

                if (($filters['from'] ?? null) === now()->startOfMonth()->toDateString()) {
                    return $resource === 'incidents' ? 42 : 30;
                }

                if (($filters['from'] ?? null) === now()->firstOfQuarter()->toDateString()) {
                    return $resource === 'incidents' ? 70 : 50;
                }

                if (($filters['from'] ?? null) === now()->subQuarter()->firstOfQuarter()->toDateString()) {
                    return $resource === 'incidents' ? 55 : 40;
                }

                return $resource === 'incidents' ? 100 : 55;
            }

            public function aggregateByGroup(string $resource, string $groupColumn, string $metric = 'count', ?string $valueColumn = null, array $filters = [], int $limit = 10): array
            {
                if ($resource === 'vehicles') {
                    return [
                        ['label' => 'Sedan', 'value' => 120],
                        ['label' => 'SUV', 'value' => 95],
                    ];
                }

                if ($resource === 'vehicle_movements') {
                    return [
                        ['label' => 'ABC-1234', 'value' => 12],
                        ['label' => 'XYZ-9876', 'value' => 8],
                    ];
                }

                 if ($resource === 'vehicle_entries') {
                     return [
                         ['label' => 'Agapita', 'value' => 37],
                         ['label' => 'Raymundo', 'value' => 19],
                         ['label' => 'Lopez', 'value' => 22],
                     ];
                 }

                return [
                    ['label' => 'Open', 'value' => 20],
                    ['label' => 'Closed', 'value' => 12],
                    ['label' => 'Escalated', 'value' => 5],
                ];
            }

            public function aggregateByDate(string $resource, string $dateColumn, string $metric = 'count', ?string $valueColumn = null, array $filters = [], string $period = 'daily', int $limit = 100): array
            {
                return [
                    ['label' => '2026-01-01', 'value' => 20],
                    ['label' => '2026-02-01', 'value' => 30],
                    ['label' => '2026-03-01', 'value' => 40],
                ];
            }

            public function getAggregateData(string $resource, string $metric, ?string $valueColumn = null, ?string $dateColumn = null, array $filters = [], string $period = 'none', int $limit = 50): array
            {
                return ['summary' => 0, 'series' => [], 'rows' => []];
            }
        });

        $this->app->instance(DatabaseConnectorManager::class, $manager);
    }
}
