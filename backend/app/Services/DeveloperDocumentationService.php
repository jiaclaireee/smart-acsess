<?php

namespace App\Services;

class DeveloperDocumentationService
{
    public function build(): array
    {
        return [
            'title' => 'SMART-ACSESS for Developers',
            'subtitle' => 'Internal developer portal for authenticated integrations that consume the SMART-ACSESS dashboard and chatbot capabilities.',
            'generated_at' => now()->toIso8601String(),
            'audience' => 'Admin only',
            'scope' => [
                'Dashboard integrations',
                'Chatbot integrations',
                'Authentication and request standards',
                'PDF export workflow',
            ],
            'overview' => [
                'description' => 'SMART-ACSESS is a campus security and safety platform that centralizes reporting, analytics, and conversational data access across connected data sources.',
                'purpose' => 'This module gives internal developers a single, secured reference for authenticating, calling, and integrating SMART-ACSESS APIs from external dashboards and assistant-style applications.',
                'plain_language' => 'In simple terms, this is the main developer handbook for teams that want to connect another system to SMART-ACSESS and reuse its dashboard and chatbot features.',
                'coverage' => [
                    'Dashboard data retrieval, aggregation, chart payloads, and drill-down records.',
                    'Chatbot context preparation, prompt submission, saved conversations, and structured results.',
                    'Integration guardrails for Sanctum-protected requests and admin-only documentation access.',
                ],
            ],
            'database_columns' => [
                'summary' => 'These column groups give external systems a practical target schema. The required set covers the minimum fields SMART-ACSESS can reliably consume, while the recommended and optional sets improve filtering, dashboard breakdowns, and chatbot answers.',
                'required' => [
                    [
                        'column' => 'id',
                        'aliases' => ['record_id', 'uuid', 'reference_no'],
                        'purpose' => 'Unique identifier for every row.',
                        'why_it_matters' => 'Needed for stable drill-down results, row references, deduplication, and tracing a chatbot answer back to a source record.',
                    ],
                    [
                        'column' => 'created_at',
                        'aliases' => ['reported_at', 'incident_date', 'occurred_at', 'timestamp'],
                        'purpose' => 'Main date or datetime field for the record.',
                        'why_it_matters' => 'Required for date filtering, trend charts, period grouping, and time-based chatbot questions such as monthly or yearly summaries.',
                    ],
                    [
                        'column' => 'category',
                        'aliases' => ['classification', 'type', 'incident_type'],
                        'purpose' => 'Main grouping label that describes what kind of record this is.',
                        'why_it_matters' => 'Used for category breakdowns, pie charts, top-category questions, and clearer chatbot summaries.',
                    ],
                    [
                        'column' => 'status',
                        'aliases' => ['state', 'record_status', 'case_status'],
                        'purpose' => 'Current lifecycle state of the record.',
                        'why_it_matters' => 'Supports monitoring views such as open versus closed items and lets the chatbot answer follow-up questions about current status.',
                    ],
                ],
                'recommended' => [
                    [
                        'column' => 'location',
                        'aliases' => ['area', 'building', 'zone', 'site'],
                        'purpose' => 'Human-readable place information for the event or record.',
                        'why_it_matters' => 'Improves map-like breakdowns, area-based dashboards, and chatbot questions such as which building or zone has the most activity.',
                    ],
                    [
                        'column' => 'department',
                        'aliases' => ['office_department', 'unit', 'college', 'office'],
                        'purpose' => 'Owning office, department, or organizational unit.',
                        'why_it_matters' => 'Helpful for administrative reporting, grouped filters, and chatbot comparisons by office or department.',
                    ],
                    [
                        'column' => 'priority',
                        'aliases' => ['severity', 'risk_level', 'urgency'],
                        'purpose' => 'Business importance or urgency of the record.',
                        'why_it_matters' => 'Enables risk-based dashboards and chatbot queries about high-priority or high-severity cases.',
                    ],
                    [
                        'column' => 'description',
                        'aliases' => ['details', 'summary', 'narrative', 'remarks'],
                        'purpose' => 'Short text that explains what happened.',
                        'why_it_matters' => 'Gives the chatbot richer context for grounded answers and helps users understand records after drill-down.',
                    ],
                    [
                        'column' => 'updated_at',
                        'aliases' => ['modified_at', 'last_updated_at'],
                        'purpose' => 'Timestamp of the latest change.',
                        'why_it_matters' => 'Useful for freshness checks, monitoring recent changes, and answering chatbot questions about the latest updates.',
                    ],
                ],
                'optional' => [
                    [
                        'column' => 'assigned_to',
                        'aliases' => ['handler', 'owner', 'responder'],
                        'purpose' => 'Person or team currently handling the record.',
                        'why_it_matters' => 'Helpful for workload views and chatbot questions about assignments, but not required for base integration.',
                    ],
                    [
                        'column' => 'resolved_at',
                        'aliases' => ['closed_at', 'completed_at'],
                        'purpose' => 'Date/time when the record was completed or resolved.',
                        'why_it_matters' => 'Supports turnaround-time analysis and closure-related chatbot questions.',
                    ],
                    [
                        'column' => 'source_system',
                        'aliases' => ['origin', 'channel', 'submitted_via'],
                        'purpose' => 'Where the record came from.',
                        'why_it_matters' => 'Useful when integrating multiple external systems and comparing source quality or volume.',
                    ],
                    [
                        'column' => 'tags',
                        'aliases' => ['labels', 'keywords'],
                        'purpose' => 'Extra descriptors attached to a record.',
                        'why_it_matters' => 'Can improve chatbot matching and ad hoc grouping, especially when records span different categories.',
                    ],
                    [
                        'column' => 'metadata',
                        'aliases' => ['extra_data', 'payload', 'attributes'],
                        'purpose' => 'Flexible JSON or structured extra fields.',
                        'why_it_matters' => 'Useful for future expansion and special-case integrations, but should not replace the core required fields.',
                    ],
                ],
            ],
            'authentication' => [
                'summary' => 'SMART-ACSESS uses Laravel Sanctum personal access tokens. Authenticate with a valid account, store the returned token securely, and send it as a Bearer token on subsequent requests.',
                'obtain_token' => [
                    'method' => 'POST',
                    'endpoint' => '/api/auth/login',
                    'headers' => [
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                    'request_body' => [
                        'email' => 'admin@up.edu.ph',
                        'password' => 'StrongPassword!23',
                    ],
                    'response_body' => [
                        'message' => 'Login successful.',
                        'user' => [
                            'id' => 1,
                            'first_name' => 'Campus',
                            'last_name' => 'Administrator',
                            'email' => 'admin@up.edu.ph',
                            'role' => 'admin',
                            'approval_status' => 'approved',
                        ],
                        'token' => '1|smart-acsess-sanctum-token',
                    ],
                ],
                'authenticated_request' => [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Authorization' => 'Bearer <token>',
                    ],
                    'notes' => [
                        'Tokens are returned by `/api/auth/login` and can also be issued after `/api/auth/register` for newly created users.',
                        'Requests without a valid token receive `401 Unauthorized`.',
                        'Requests from unapproved or unauthorized users receive `403 Forbidden`.',
                    ],
                ],
            ],
            'standard_methods' => [
                [
                    'method' => 'GET',
                    'description' => 'Retrieve records, metadata, schema details, or saved resources without mutating data.',
                    'endpoint_format' => '/api/{resource}/{id?}',
                    'headers' => [
                        'Accept' => 'application/json',
                        'Authorization' => 'Bearer <token>',
                    ],
                    'request_body' => null,
                    'sample_response' => [
                        'conversations' => [
                            [
                                'id' => '42',
                                'title' => 'Incident trends for March',
                                'message_count' => 6,
                            ],
                        ],
                    ],
                    'error_handling' => [
                        '400/422' => 'Malformed query parameters or validation errors. SMART-ACSESS typically returns 422 for validation failures.',
                        '401' => 'Missing or invalid Bearer token.',
                        '403' => 'Authenticated user lacks approval or required role.',
                        '500' => 'Unexpected server-side processing failure.',
                    ],
                ],
                [
                    'method' => 'POST',
                    'description' => 'Create resources or trigger server-side processing such as authentication, analytics generation, chatbot prompting, and PDF exports.',
                    'endpoint_format' => '/api/{resource}',
                    'headers' => [
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer <token>',
                    ],
                    'request_body' => [
                        'prompt' => 'Show me the top incident categories this month.',
                    ],
                    'sample_response' => [
                        'answer' => 'I found the top categories for the current month.',
                        'suggestions' => [
                            'Show monthly trend for incidents.',
                            'Preview incident records.',
                        ],
                    ],
                    'error_handling' => [
                        '400/422' => 'Payload is incomplete, invalid JSON was sent, or validation rules failed.',
                        '401' => 'Token is missing, expired, or revoked.',
                        '403' => 'User is authenticated but blocked by approval or role middleware.',
                        '500' => 'The request reached the application but downstream processing failed unexpectedly.',
                    ],
                ],
                [
                    'method' => 'PUT / PATCH',
                    'description' => 'Update existing resources such as users, approvals, or saved database connections.',
                    'endpoint_format' => '/api/{resource}/{id}',
                    'headers' => [
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer <token>',
                    ],
                    'request_body' => [
                        'approval_status' => 'approved',
                    ],
                    'sample_response' => [
                        'id' => 7,
                        'role' => 'end_user',
                        'approval_status' => 'approved',
                    ],
                    'error_handling' => [
                        '400/422' => 'Invalid identifiers or update payload.',
                        '401' => 'Token is missing or invalid.',
                        '403' => 'Caller does not have permission to update the target resource.',
                        '500' => 'Unexpected persistence or application error.',
                    ],
                ],
                [
                    'method' => 'DELETE',
                    'description' => 'Remove an existing resource when the caller has explicit permission.',
                    'endpoint_format' => '/api/{resource}/{id}',
                    'headers' => [
                        'Accept' => 'application/json',
                        'Authorization' => 'Bearer <token>',
                    ],
                    'request_body' => null,
                    'sample_response' => [
                        'ok' => true,
                    ],
                    'error_handling' => [
                        '400/422' => 'Invalid resource identifier or delete precondition.',
                        '401' => 'Missing or invalid token.',
                        '403' => 'Authenticated user is not allowed to delete the resource.',
                        '500' => 'Unexpected delete failure.',
                    ],
                ],
            ],
            'dashboard' => [
                'summary' => 'Dashboard integrations rely on connected database metadata, resource/schema discovery, aggregated analytics payloads, and raw-row drill-down responses.',
                'chart_consumption_notes' => [
                    'Chart components consume `chart.labels` and `chart.series` arrays returned by `/api/analytics/report`.',
                    'Chart grouping metadata is available at `chart.meta.mode` and `chart.meta.group_by` to decide whether the frontend is rendering date buckets, group buckets, or a resource overview.',
                    'Drill-down tables consume `table.columns`, `table.rows`, and `table.pagination` returned by `/api/analytics/drilldown`.',
                ],
                'endpoints' => [
                    [
                        'name' => 'List Accessible Databases',
                        'method' => 'GET',
                        'endpoint' => '/api/databases',
                        'parameters' => [],
                        'request' => null,
                        'response' => [
                            [
                                'id' => 3,
                                'name' => 'Analytics PG',
                                'type' => 'pgsql',
                                'resource_label' => 'table',
                            ],
                        ],
                        'notes' => [
                            'Returns database connections visible to the authenticated and approved user.',
                            'Used by the dashboard filter dropdown before analytics requests are made.',
                        ],
                    ],
                    [
                        'name' => 'List Tables or Collections',
                        'method' => 'GET',
                        'endpoint' => '/api/databases/{database}/tables',
                        'parameters' => [
                            'database' => 'Connected database ID.',
                        ],
                        'request' => null,
                        'response' => [
                            'resource_type' => 'table',
                            'items' => ['incidents', 'responders'],
                            'tables' => ['incidents', 'responders'],
                            'collections' => [],
                        ],
                        'notes' => [
                            'Use this after selecting a database so external dashboards can populate resource filters.',
                        ],
                    ],
                    [
                        'name' => 'Inspect Resource Schema',
                        'method' => 'GET',
                        'endpoint' => '/api/databases/{database}/schema?resource=incidents',
                        'parameters' => [
                            'database' => 'Connected database ID.',
                            'resource' => 'Target table or collection name.',
                        ],
                        'request' => null,
                        'response' => [
                            'resource_type' => 'table',
                            'schema' => [
                                [
                                    'resource' => 'incidents',
                                    'columns' => [
                                        ['name' => 'id', 'type' => 'integer'],
                                        ['name' => 'created_at', 'type' => 'timestamp'],
                                    ],
                                ],
                            ],
                        ],
                        'notes' => [
                            'The dashboard uses schema inspection to suggest visualization and date columns.',
                        ],
                    ],
                    [
                        'name' => 'Build Dashboard Report',
                        'method' => 'POST',
                        'endpoint' => '/api/analytics/report',
                        'parameters' => [
                            'db_id' => 'Required connected database ID.',
                            'resource' => 'Optional table or collection to scope the report.',
                            'from / to' => 'Optional date range filters.',
                            'period' => 'Grouping period such as `daily`, `weekly`, `monthly`, `semiannual`, or `annual`.',
                            'graph_type' => 'One of `table`, `bar`, `pie`, or `line`.',
                            'group_column' => 'Optional chart grouping column.',
                            'date_column' => 'Optional explicit date column.',
                            'page / per_page' => 'Pagination controls for the tabular response.',
                            'sort_by / sort_direction' => 'Optional table sorting settings.',
                        ],
                        'request' => [
                            'db_id' => 3,
                            'resource' => 'incidents',
                            'from' => '2026-01-01',
                            'to' => '2026-01-31',
                            'period' => 'monthly',
                            'graph_type' => 'line',
                            'group_column' => 'classification',
                            'date_column' => 'created_at',
                            'page' => 1,
                            'per_page' => 25,
                            'sort_by' => 'created_at',
                            'sort_direction' => 'desc',
                        ],
                        'response' => [
                            'resource_type' => 'table',
                            'selected_resource' => 'incidents',
                            'warnings' => [],
                            'chart' => [
                                'type' => 'line',
                                'title' => 'Incidents by month',
                                'labels' => ['2026-01-01'],
                                'series' => [5],
                                'meta' => [
                                    'mode' => 'date',
                                    'group_by' => 'created_at',
                                ],
                            ],
                            'kpis' => [
                                ['label' => 'Total Records', 'value' => 5],
                            ],
                            'table' => [
                                'columns' => ['id', 'classification', 'status', 'created_at'],
                                'rows' => [
                                    ['id' => 5, 'classification' => 'Maintenance', 'status' => 'Closed', 'created_at' => '2026-01-25T10:00:00Z'],
                                ],
                                'pagination' => [
                                    'page' => 1,
                                    'per_page' => 25,
                                    'total' => 5,
                                    'last_page' => 1,
                                    'from' => 1,
                                    'to' => 5,
                                ],
                            ],
                        ],
                        'notes' => [
                            'If no `resource` is supplied, the API can return a database-level resource overview.',
                            'Validation errors are returned as 422 responses with field-specific error messages.',
                        ],
                    ],
                    [
                        'name' => 'Load Drill-down Rows',
                        'method' => 'POST',
                        'endpoint' => '/api/analytics/drilldown',
                        'parameters' => [
                            'db_id' => 'Required connected database ID.',
                            'resource' => 'Required target resource for drill-down.',
                            'chart_mode' => 'Required. One of `date`, `group`, or `resource_overview`.',
                            'bucket_label' => 'Required chart bucket label selected by the user.',
                            'chart_group_by' => 'Optional grouping column used by the chart.',
                            'page / per_page' => 'Pagination controls for matching rows.',
                        ],
                        'request' => [
                            'db_id' => 3,
                            'resource' => 'incidents',
                            'chart_mode' => 'group',
                            'chart_group_by' => 'status',
                            'bucket_label' => 'Open',
                            'page' => 1,
                            'per_page' => 25,
                        ],
                        'response' => [
                            'title' => 'Matching rows for Open',
                            'description' => 'Rows backing the selected chart bucket.',
                            'selection' => [
                                'label' => 'Open',
                                'mode' => 'group',
                                'resource' => 'incidents',
                                'resource_type' => 'table',
                                'group_by' => 'status',
                            ],
                            'table' => [
                                'columns' => ['id', 'classification', 'status', 'created_at'],
                                'rows' => [
                                    ['id' => 1, 'classification' => 'Security', 'status' => 'Open', 'created_at' => '2026-01-01T10:00:00Z'],
                                    ['id' => 3, 'classification' => 'Security', 'status' => 'Open', 'created_at' => '2026-01-15T10:00:00Z'],
                                ],
                                'pagination' => [
                                    'page' => 1,
                                    'per_page' => 25,
                                    'total' => 2,
                                    'last_page' => 1,
                                    'from' => 1,
                                    'to' => 2,
                                ],
                            ],
                        ],
                        'notes' => [
                            'Use this to power click-through inspection from charts into the exact backing rows.',
                        ],
                    ],
                ],
            ],
            'chatbot' => [
                'summary' => 'Chatbot integrations are designed around context preparation, grounded question answering, saved conversation retrieval, and optional table exports.',
                'multilingual_support' => [
                    'Queries may be submitted in English, Tagalog, or Taglish.',
                    'The backend interprets language style automatically and returns `language_style` metadata in chatbot answers.',
                    'Suggested prompts can be used as quick-start prompts for external assistants and embedded chat UIs.',
                ],
                'endpoints' => [
                    [
                        'name' => 'Prepare Chatbot Context',
                        'method' => 'POST',
                        'endpoint' => '/api/chatbot/context',
                        'parameters' => [
                            'db_id' => 'Optional database scope.',
                            'resource' => 'Optional resource scope.',
                            'conversation_id' => 'Optional saved conversation to reopen.',
                        ],
                        'request' => [
                            'conversation_id' => 42,
                        ],
                        'response' => [
                            'context_id' => 'ctx_01hxyz',
                            'summary' => 'The chatbot can currently use 3 accessible databases with 12 profiled data sources and 485 known records.',
                            'suggested_prompts' => [
                                'Show me summary of the available data.',
                                'Ano ang trend ng reports this year?',
                            ],
                            'overview' => [
                                'accessible_database_count' => 3,
                                'known_record_total' => 485,
                            ],
                            'history' => [],
                            'conversation' => null,
                        ],
                        'notes' => [
                            'Call this before the first prompt to obtain a reusable `context_id`.',
                        ],
                    ],
                    [
                        'name' => 'Send a Chatbot Query',
                        'method' => 'POST',
                        'endpoint' => '/api/chatbot/ask',
                        'parameters' => [
                            'context_id' => 'Recommended context ID from `/api/chatbot/context`.',
                            'conversation_id' => 'Optional saved conversation ID.',
                            'new_conversation' => 'Boolean flag for starting a new saved thread.',
                            'prompt' => 'Required user input text.',
                            'db_id / resource' => 'Optional explicit scope overrides.',
                        ],
                        'request' => [
                            'context_id' => 'ctx_01hxyz',
                            'conversation_id' => 42,
                            'new_conversation' => false,
                            'prompt' => 'Ano ang top incident categories this month?',
                        ],
                        'response' => [
                            'context_id' => 'ctx_01hxyz',
                            'conversation' => [
                                'id' => '42',
                                'title' => 'Incident trends for March',
                            ],
                            'answer' => 'Nakita ko ang top categories para sa current month.',
                            'language_style' => 'taglish',
                            'facts' => [
                                'Top category: Security',
                            ],
                            'suggestions' => [
                                'Show monthly trend for incidents.',
                                'Preview incidents.',
                            ],
                            'table' => [
                                'columns' => ['category', 'count'],
                                'rows' => [
                                    ['category' => 'Security', 'count' => 12],
                                ],
                            ],
                            'chart' => [
                                'title' => 'Top categories',
                                'labels' => ['Security', 'Maintenance'],
                                'series' => [12, 8],
                            ],
                            'history' => [],
                        ],
                        'notes' => [
                            'Primary plain-text answer is returned in `answer` and again inside the latest assistant message within `history`.',
                            'Structured table data is returned in `table`, while summarized chart data is returned in `chart` when available.',
                        ],
                    ],
                    [
                        'name' => 'List Saved Conversations',
                        'method' => 'GET',
                        'endpoint' => '/api/chatbot/conversations?search=incident',
                        'parameters' => [
                            'search' => 'Optional free-text search over titles and message keywords.',
                        ],
                        'request' => null,
                        'response' => [
                            'conversations' => [
                                [
                                    'id' => '42',
                                    'title' => 'Incident trends for March',
                                    'preview' => 'Show me the top categories...',
                                    'message_count' => 6,
                                ],
                            ],
                        ],
                        'notes' => [
                            'Useful for external chat UIs that need to reopen prior grounded conversations.',
                        ],
                    ],
                    [
                        'name' => 'Load One Saved Conversation',
                        'method' => 'GET',
                        'endpoint' => '/api/chatbot/conversations/{conversation}',
                        'parameters' => [
                            'conversation' => 'Saved conversation ID.',
                        ],
                        'request' => null,
                        'response' => [
                            'conversation' => [
                                'id' => '42',
                                'title' => 'Incident trends for March',
                            ],
                            'messages' => [
                                [
                                    'id' => 'msg_01',
                                    'role' => 'assistant',
                                    'content' => 'Here is the March incident summary.',
                                ],
                            ],
                        ],
                        'notes' => [
                            'Returns `404` if the conversation is not available to the authenticated user.',
                        ],
                    ],
                    [
                        'name' => 'Reset a Conversation',
                        'method' => 'POST',
                        'endpoint' => '/api/chatbot/reset',
                        'parameters' => [
                            'conversation_id' => 'Optional saved conversation ID to clear.',
                        ],
                        'request' => [
                            'conversation_id' => 42,
                        ],
                        'response' => [
                            'ok' => true,
                            'conversation' => [
                                'id' => '42',
                                'title' => 'Incident trends for March',
                            ],
                        ],
                        'notes' => [
                            'Use this to clear chat history while keeping the saved conversation shell.',
                        ],
                    ],
                    [
                        'name' => 'Export Chatbot Table Result as PDF',
                        'method' => 'POST',
                        'endpoint' => '/api/chatbot/export/table-pdf',
                        'parameters' => [
                            'title' => 'Required export title.',
                            'subtitle' => 'Optional subtitle.',
                            'summary' => 'Optional response summary.',
                            'table.columns' => 'Required list of column labels.',
                            'table.rows' => 'Required list of row objects.',
                        ],
                        'request' => [
                            'title' => 'Chatbot Table Result',
                            'subtitle' => 'SMART-ACSESS chatbot table export',
                            'summary' => 'Rows returned by the last grounded answer.',
                            'table' => [
                                'columns' => ['category', 'count'],
                                'rows' => [
                                    ['category' => 'Security', 'count' => 12],
                                ],
                            ],
                        ],
                        'response' => [
                            'content_type' => 'application/pdf',
                            'download' => 'chatbot-table-result.pdf',
                        ],
                        'notes' => [
                            'This endpoint streams a PDF file instead of JSON when the request succeeds.',
                        ],
                    ],
                ],
            ],
            'integration_guide' => [
                'steps' => [
                    'Authenticate with `/api/auth/login` and securely store the returned Sanctum token.',
                    'Send `Authorization: Bearer <token>` and `Accept: application/json` on every secured API request.',
                    'For dashboard clients, load databases first, then resources/schema, then call `/api/analytics/report` and `/api/analytics/drilldown` as users refine filters.',
                    'For chatbot clients, call `/api/chatbot/context`, submit prompts through `/api/chatbot/ask`, and optionally reopen or reset saved conversations as needed.',
                    'Handle 401, 403, validation errors, and 500-class failures gracefully in the consuming system.',
                ],
                'use_cases' => [
                    [
                        'title' => 'External dashboard consuming SMART-ACSESS data',
                        'flow' => [
                            'Load accessible databases and available resources.',
                            'Request an analytics payload based on selected filters and date range.',
                            'Render KPIs from `kpis`, charts from `chart.labels` and `chart.series`, and detail tables from `table.rows`.',
                            'When users click a chart element, call `/api/analytics/drilldown` to retrieve the exact backing rows.',
                        ],
                    ],
                    [
                        'title' => 'External system querying the chatbot API',
                        'flow' => [
                            'Create or refresh a chatbot context to obtain `context_id` and suggested prompts.',
                            'Post user text to `/api/chatbot/ask` with optional conversation state.',
                            'Render `answer` as the primary assistant message, then optionally display `table`, `chart`, `facts`, `warnings`, and `suggestions`.',
                            'Persist `conversation.id` so the external client can reopen the same conversation later.',
                        ],
                    ],
                ],
            ],
        ];
    }

    public function buildPdfHtml(): string
    {
        $documentation = $this->build();
        $jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>'
            . $this->escape($documentation['title'])
            . '</title><style>'
            . 'body{font-family:DejaVu Sans,sans-serif;color:#17212b;font-size:11px;margin:0;}'
            . '.page{padding:28px 30px 34px;}'
            . '.eyebrow{display:inline-block;padding:5px 10px;border-radius:999px;background:#0f5b3b;color:#fff;font-size:9px;letter-spacing:.12em;text-transform:uppercase;}'
            . 'h1{margin:14px 0 8px;font-size:28px;color:#0f172a;}'
            . 'h2{margin:24px 0 10px;font-size:18px;color:#0f5b3b;page-break-after:avoid;}'
            . 'h3{margin:16px 0 8px;font-size:13px;color:#1e293b;page-break-after:avoid;}'
            . 'p{margin:0 0 10px;line-height:1.6;}'
            . '.meta{color:#5b6672;font-size:10px;}'
            . '.panel{margin-top:14px;padding:14px 16px;border:1px solid #d8e1e8;border-radius:14px;background:#f8fafc;}'
            . '.card{margin-top:12px;padding:14px 16px;border:1px solid #d8e1e8;border-radius:14px;background:#fff;page-break-inside:avoid;}'
            . 'ul{margin:8px 0 0 18px;padding:0;}'
            . 'li{margin:0 0 6px;line-height:1.5;}'
            . '.label{font-weight:bold;color:#0f172a;}'
            . '.code{margin-top:8px;padding:10px 12px;border-radius:10px;background:#0f172a;color:#e2e8f0;white-space:pre-wrap;font-family:DejaVu Sans Mono, monospace;font-size:9px;line-height:1.45;}'
            . 'table{width:100%;border-collapse:collapse;margin-top:10px;}'
            . 'th,td{border:1px solid #d7dee5;padding:8px 9px;vertical-align:top;text-align:left;}'
            . 'th{background:#0f5b3b;color:#fff;font-size:10px;}'
            . 'td{font-size:10px;}'
            . '.muted{color:#5b6672;}'
            . '.section-spacer{margin-top:22px;}'
            . '</style></head><body><div class="page">';

        $html .= '<div class="eyebrow">SMART-ACSESS</div>';
        $html .= '<h1>' . $this->escape($documentation['title']) . '</h1>';
        $html .= '<p>' . $this->escape($documentation['subtitle']) . '</p>';
        $html .= '<p class="meta">Access: ' . $this->escape($documentation['audience'])
            . ' | Generated: ' . $this->escape(now()->format('M d, Y h:i A')) . '</p>';

        $html .= '<div class="panel"><div class="label">Scope</div>' . $this->renderList($documentation['scope']) . '</div>';

        $html .= '<div class="section-spacer"><h2>Overview</h2><div class="card">';
        $html .= '<p><span class="label">SMART-ACSESS:</span> ' . $this->escape($documentation['overview']['description']) . '</p>';
        $html .= '<p><span class="label">Developer module purpose:</span> ' . $this->escape($documentation['overview']['purpose']) . '</p>';
        $html .= '<p><span class="label">Layman\'s terms:</span> ' . $this->escape($documentation['overview']['plain_language']) . '</p>';
        $html .= '<div class="label">Coverage</div>' . $this->renderList($documentation['overview']['coverage']) . '</div></div>';

        $html .= '<div class="section-spacer"><h2>Database Column Guide</h2><div class="card">';
        $html .= '<p>' . $this->escape($documentation['database_columns']['summary']) . '</p>';
        $html .= $this->renderColumnGroup('Required DB Columns', $documentation['database_columns']['required']);
        $html .= $this->renderColumnGroup('Recommended DB Columns', $documentation['database_columns']['recommended']);
        $html .= $this->renderColumnGroup('Other Optional DB Columns', $documentation['database_columns']['optional']);
        $html .= '</div></div>';

        $html .= '<div class="section-spacer"><h2>Authentication</h2><div class="card">';
        $html .= '<p>' . $this->escape($documentation['authentication']['summary']) . '</p>';
        $html .= '<p><span class="label">Obtain token:</span> '
            . $this->escape($documentation['authentication']['obtain_token']['method']) . ' '
            . $this->escape($documentation['authentication']['obtain_token']['endpoint']) . '</p>';
        $html .= '<div class="label">Headers</div>' . $this->renderCode($documentation['authentication']['obtain_token']['headers'], $jsonFlags);
        $html .= '<div class="label">Sample request</div>' . $this->renderCode($documentation['authentication']['obtain_token']['request_body'], $jsonFlags);
        $html .= '<div class="label">Sample response</div>' . $this->renderCode($documentation['authentication']['obtain_token']['response_body'], $jsonFlags);
        $html .= '<div class="label">Authenticated request headers</div>' . $this->renderCode($documentation['authentication']['authenticated_request']['headers'], $jsonFlags);
        $html .= $this->renderList($documentation['authentication']['authenticated_request']['notes']);
        $html .= '</div></div>';

        $html .= '<div class="section-spacer"><h2>Standard API Methods</h2>';
        foreach ($documentation['standard_methods'] as $method) {
            $html .= '<div class="card">';
            $html .= '<h3>' . $this->escape($method['method']) . '</h3>';
            $html .= '<p>' . $this->escape($method['description']) . '</p>';
            $html .= '<p><span class="label">Endpoint format:</span> ' . $this->escape($method['endpoint_format']) . '</p>';
            $html .= '<div class="label">Headers</div>' . $this->renderCode($method['headers'], $jsonFlags);
            $html .= '<div class="label">Request body</div>' . $this->renderCode($method['request_body'] ?? 'No request body required.', $jsonFlags, $method['request_body'] === null);
            $html .= '<div class="label">Sample response</div>' . $this->renderCode($method['sample_response'], $jsonFlags);
            $html .= $this->renderKeyValueTable('Status', 'Handling Guidance', $method['error_handling']);
            $html .= '</div>';
        }
        $html .= '</div>';

        $html .= '<div class="section-spacer"><h2>Dashboard API Endpoints</h2>';
        $html .= '<div class="card"><p>' . $this->escape($documentation['dashboard']['summary']) . '</p>';
        $html .= '<div class="label">Frontend chart consumption notes</div>' . $this->renderList($documentation['dashboard']['chart_consumption_notes']) . '</div>';
        foreach ($documentation['dashboard']['endpoints'] as $endpoint) {
            $html .= $this->renderEndpointCard($endpoint, $jsonFlags);
        }
        $html .= '</div>';

        $html .= '<div class="section-spacer"><h2>Chatbot API Endpoints</h2>';
        $html .= '<div class="card"><p>' . $this->escape($documentation['chatbot']['summary']) . '</p>';
        $html .= '<div class="label">Multilingual handling</div>' . $this->renderList($documentation['chatbot']['multilingual_support']) . '</div>';
        foreach ($documentation['chatbot']['endpoints'] as $endpoint) {
            $html .= $this->renderEndpointCard($endpoint, $jsonFlags);
        }
        $html .= '</div>';

        $html .= '<div class="section-spacer"><h2>Web Services / Integration Guide</h2>';
        $html .= '<div class="card"><div class="label">Integration flow</div>' . $this->renderList($documentation['integration_guide']['steps']) . '</div>';
        foreach ($documentation['integration_guide']['use_cases'] as $useCase) {
            $html .= '<div class="card"><h3>' . $this->escape($useCase['title']) . '</h3>' . $this->renderList($useCase['flow']) . '</div>';
        }
        $html .= '</div>';

        return $html . '</div></body></html>';
    }

    private function renderEndpointCard(array $endpoint, int $jsonFlags): string
    {
        $html = '<div class="card">';
        $html .= '<h3>' . $this->escape($endpoint['name']) . '</h3>';
        $html .= '<p><span class="label">Endpoint:</span> ' . $this->escape($endpoint['method']) . ' ' . $this->escape($endpoint['endpoint']) . '</p>';
        $html .= $this->renderKeyValueTable('Parameter', 'Description', $endpoint['parameters']);
        $html .= '<div class="label">Example request</div>' . $this->renderCode($endpoint['request'] ?? 'No request body required.', $jsonFlags, $endpoint['request'] === null);
        $html .= '<div class="label">Example response</div>' . $this->renderCode($endpoint['response'], $jsonFlags);
        $html .= $this->renderList($endpoint['notes']);

        return $html . '</div>';
    }

    private function renderColumnGroup(string $title, array $columns): string
    {
        $html = '<h3>' . $this->escape($title) . '</h3>';
        $html .= '<table><thead><tr><th>Column</th><th>Aliases / examples</th><th>Purpose</th><th>Why it matters</th></tr></thead><tbody>';

        foreach ($columns as $column) {
            $html .= '<tr>';
            $html .= '<td>' . $this->escape((string) ($column['column'] ?? '-')) . '</td>';
            $html .= '<td>' . $this->escape(implode(', ', (array) ($column['aliases'] ?? []))) . '</td>';
            $html .= '<td>' . $this->escape((string) ($column['purpose'] ?? '-')) . '</td>';
            $html .= '<td>' . $this->escape((string) ($column['why_it_matters'] ?? '-')) . '</td>';
            $html .= '</tr>';
        }

        return $html . '</tbody></table>';
    }

    private function renderKeyValueTable(string $leftHeader, string $rightHeader, array $rows): string
    {
        $html = '<table><thead><tr><th>' . $this->escape($leftHeader) . '</th><th>' . $this->escape($rightHeader) . '</th></tr></thead><tbody>';

        if ($rows === []) {
            return $html . '<tr><td colspan="2" class="muted">No required parameters.</td></tr></tbody></table>';
        }

        foreach ($rows as $key => $value) {
            $html .= '<tr><td>' . $this->escape((string) $key) . '</td><td>' . $this->escape((string) $value) . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    private function renderList(array $items): string
    {
        $html = '<ul>';

        foreach ($items as $item) {
            $html .= '<li>' . $this->escape((string) $item) . '</li>';
        }

        return $html . '</ul>';
    }

    private function renderCode(mixed $value, int $jsonFlags, bool $isPlainText = false): string
    {
        if ($isPlainText) {
            return '<div class="code">' . $this->escape((string) $value) . '</div>';
        }

        $encoded = is_string($value)
            ? $value
            : json_encode($value, $jsonFlags);

        return '<div class="code">' . $this->escape($encoded === false ? '-' : $encoded) . '</div>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
