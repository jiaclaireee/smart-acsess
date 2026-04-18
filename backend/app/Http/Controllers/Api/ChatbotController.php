<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditTrailService;
use App\Services\Chatbot\UnifiedChatbotService;
use App\Services\Database\DatabaseConnectorException;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ChatbotController extends Controller
{
    public function context(Request $request, UnifiedChatbotService $service)
    {
        $data = $request->validate([
            'db_id' => ['nullable', 'integer', 'exists:connected_databases,id'],
            'resource' => ['nullable', 'string', 'max:255'],
            'conversation_id' => ['nullable', 'integer'],
        ]);

        try {
            return response()->json(
                $service->prepareContext(
                    $request->user(),
                    isset($data['db_id']) ? (int) $data['db_id'] : null,
                    $data['resource'] ?? null,
                    $data['conversation_id'] ?? null,
                )
            );
        } catch (DatabaseConnectorException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            Log::error('Failed to prepare chatbot context.', [
                'user_id' => $request->user()?->id,
                'db_id' => $data['db_id'] ?? null,
                'resource' => $data['resource'] ?? null,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to prepare grounded chatbot context for the selected database.',
            ], 500);
        }
    }

    public function ask(Request $request, UnifiedChatbotService $service)
    {
        $data = $request->validate([
            'db_id' => ['nullable', 'integer', 'exists:connected_databases,id'],
            'resource' => ['nullable', 'string', 'max:255'],
            'context_id' => ['nullable', 'string', 'max:100'],
            'conversation_id' => ['nullable', 'integer'],
            'new_conversation' => ['nullable', 'boolean'],
            'prompt' => ['required', 'string', 'max:5000'],
        ]);

        try {
            $response = $service->ask($request->user(), $data);

            app(AuditTrailService::class)->record(
                $request,
                'Chatbot',
                'Chatbot Conversation',
                'Submitted a chatbot prompt and received a grounded response.',
                [
                    'conversation_id' => $response['conversation']['id'] ?? ($data['conversation_id'] ?? null),
                    'new_conversation' => (bool) ($data['new_conversation'] ?? false),
                    'prompt' => Str::limit((string) ($data['prompt'] ?? ''), 160),
                    'intent' => $response['intent'] ?? null,
                    'language_style' => $response['language_style'] ?? null,
                    'has_table' => !empty($response['table']),
                    'has_chart' => !empty($response['chart']),
                    'table_rows' => count((array) (($response['table']['rows'] ?? []))),
                ],
                'chat_conversation',
                $response['conversation']['id'] ?? null,
            );

            return response()->json($response);
        } catch (DatabaseConnectorException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            Log::error('Failed to answer grounded chatbot prompt.', [
                'user_id' => $request->user()?->id,
                'db_id' => $data['db_id'] ?? null,
                'resource' => $data['resource'] ?? null,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to answer the chatbot request for the selected database.',
            ], 500);
        }
    }

    public function history(Request $request, UnifiedChatbotService $service)
    {
        $data = $request->validate([
            'db_id' => ['nullable', 'integer', 'exists:connected_databases,id'],
            'resource' => ['nullable', 'string', 'max:255'],
            'conversation_id' => ['nullable', 'integer'],
        ]);

        return response()->json(
            $service->history(
                $request->user(),
                isset($data['db_id']) ? (int) $data['db_id'] : null,
                $data['resource'] ?? null,
                $data['conversation_id'] ?? null,
            )
        );
    }

    public function reset(Request $request, UnifiedChatbotService $service)
    {
        $data = $request->validate([
            'db_id' => ['nullable', 'integer', 'exists:connected_databases,id'],
            'resource' => ['nullable', 'string', 'max:255'],
            'conversation_id' => ['nullable', 'integer'],
        ]);

        return response()->json(
            $service->reset(
                $request->user(),
                isset($data['db_id']) ? (int) $data['db_id'] : null,
                $data['resource'] ?? null,
                $data['conversation_id'] ?? null,
            )
        );
    }

    public function conversations(Request $request, UnifiedChatbotService $service)
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(
            $service->conversations($request->user(), $data['search'] ?? null)
        );
    }

    public function conversation(Request $request, UnifiedChatbotService $service, string $conversationId)
    {
        $payload = $service->conversation($request->user(), $conversationId);

        if (!$payload['conversation']) {
            return response()->json(['message' => 'Conversation not found.'], 404);
        }

        return response()->json($payload);
    }

    public function knowledgeStatus(Request $request, UnifiedChatbotService $service)
    {
        return response()->json($service->knowledgeStatus($request->user()));
    }

    public function syncKnowledge(Request $request, UnifiedChatbotService $service)
    {
        $data = $request->validate([
            'db_ids' => ['nullable', 'array'],
            'db_ids.*' => ['integer', 'exists:connected_databases,id'],
            'force' => ['nullable', 'boolean'],
        ]);

        try {
            return response()->json($service->syncKnowledge($request->user(), $data));
        } catch (DatabaseConnectorException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            Log::error('Failed to sync chatbot knowledge.', [
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Unable to sync chatbot knowledge right now.',
            ], 500);
        }
    }

    public function exportTablePdf(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:4000'],
            'table' => ['required', 'array'],
            'table.columns' => ['required', 'array'],
            'table.rows' => ['required', 'array'],
        ]);

        $payload = [
            'title' => $data['title'],
            'subtitle' => $data['subtitle'] ?? null,
            'summary' => $data['summary'] ?? null,
            'table' => $data['table'],
            'generatedAt' => now()->toDateTimeString(),
        ];

        $pdf = Pdf::loadHTML($this->buildTablePdfHtml($payload))
            ->setPaper('a4', $this->resolveTableOrientation((array) ($data['table']['columns'] ?? [])));

        app(AuditTrailService::class)->record(
            $request,
            'Chatbot',
            'Export Chatbot Report',
            'Downloaded a chatbot table result as PDF.',
            [
                'title' => $data['title'],
                'subtitle' => $data['subtitle'] ?? null,
                'row_count' => count((array) ($data['table']['rows'] ?? [])),
                'column_count' => count((array) ($data['table']['columns'] ?? [])),
                'paper_size' => 'a4',
                'paper_orientation' => $this->resolveTableOrientation((array) ($data['table']['columns'] ?? [])),
            ],
            'chatbot_export',
            Str::slug($data['title']) ?: null,
        );

        return $pdf->download(sprintf(
            'chatbot-table-%s.pdf',
            Str::slug($data['title']) ?: 'report'
        ));
    }

    private function buildTablePdfHtml(array $payload): string
    {
        $table = $payload['table'] ?? ['columns' => [], 'rows' => []];
        $headHtml = '';
        foreach ((array) ($table['columns'] ?? []) as $column) {
            $headHtml .= '<th>' . $this->escape((string) $column) . '</th>';
        }

        $bodyHtml = '';
        foreach ((array) ($table['rows'] ?? []) as $row) {
            $bodyHtml .= '<tr>';
            foreach ((array) ($table['columns'] ?? []) as $column) {
                $bodyHtml .= '<td>' . $this->escape($this->formatCell($row[$column] ?? null)) . '</td>';
            }
            $bodyHtml .= '</tr>';
        }

        return '<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>' . $this->escape((string) ($payload['title'] ?? 'Chatbot Table Report')) . '</title>
  <style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #17212b; margin: 0; }
    .page { padding: 26px 28px; }
    .header { border-bottom: 2px solid #0f5b3b; padding-bottom: 12px; margin-bottom: 18px; }
    .brand { display: inline-block; padding: 5px 10px; background: #0f5b3b; color: #fff; border-radius: 12px; font-size: 10px; letter-spacing: .08em; }
    .title { font-size: 22px; font-weight: bold; margin: 10px 0 4px 0; }
    .subtitle { color: #5a6570; margin-top: 6px; line-height: 1.5; }
    .summary { margin-top: 16px; border: 1px solid #d7e0e8; border-radius: 12px; padding: 12px 14px; background: #f8fafc; line-height: 1.6; }
    .section-title { font-size: 14px; font-weight: bold; margin: 18px 0 10px 0; color: #0f5b3b; }
    .data-table { width: 100%; border-collapse: collapse; }
    .data-table th { background: #0f5b3b; color: #fff; padding: 8px 10px; text-align: left; font-size: 10px; }
    .data-table td { border: 1px solid #d9e1e8; padding: 7px 9px; font-size: 10px; vertical-align: top; word-break: break-word; }
    .muted { color: #5a6570; }
  </style>
</head>
<body>
  <div class="page">
    <div class="header">
      <div class="brand">SMART-ACSESS</div>
      <div class="title">' . $this->escape((string) ($payload['title'] ?? 'Chatbot Table Report')) . '</div>
      <div class="muted">Generated: ' . $this->escape((string) ($payload['generatedAt'] ?? '')) . '</div>'
      . (!empty($payload['subtitle']) ? '<div class="subtitle">' . $this->escape((string) $payload['subtitle']) . '</div>' : '') . '
    </div>'
    . (!empty($payload['summary']) ? '<div class="summary">' . nl2br($this->escape((string) $payload['summary'])) . '</div>' : '') . '
    <div class="section-title">Tabular Result</div>'
    . ($headHtml !== '' && $bodyHtml !== ''
        ? '<table class="data-table"><thead><tr>' . $headHtml . '</tr></thead><tbody>' . $bodyHtml . '</tbody></table>'
        : '<div class="muted">No table rows were available for export.</div>') . '
  </div>
</body>
</html>';
    }

    private function resolveTableOrientation(array $columns): string
    {
        return count($columns) >= 6 ? 'landscape' : 'portrait';
    }

    private function formatCell(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        if (is_array($value) || is_object($value)) {
            $encoded = json_encode($value);

            return $encoded === false ? '-' : $encoded;
        }

        return (string) $value;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
