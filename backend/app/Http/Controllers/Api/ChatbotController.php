<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Chatbot\UnifiedChatbotService;
use App\Services\Database\DatabaseConnectorException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChatbotController extends Controller
{
    public function context(Request $request, UnifiedChatbotService $service)
    {
        $data = $request->validate([
            'db_id' => ['nullable', 'integer', 'exists:connected_databases,id'],
            'resource' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            return response()->json(
                $service->prepareContext($request->user(), isset($data['db_id']) ? (int) $data['db_id'] : null, $data['resource'] ?? null)
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
            'prompt' => ['required', 'string', 'max:5000'],
        ]);

        try {
            return response()->json($service->ask($request->user(), $data));
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
        ]);

        return response()->json(
            $service->history($request->user(), isset($data['db_id']) ? (int) $data['db_id'] : null, $data['resource'] ?? null)
        );
    }

    public function reset(Request $request, UnifiedChatbotService $service)
    {
        $data = $request->validate([
            'db_id' => ['nullable', 'integer', 'exists:connected_databases,id'],
            'resource' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(
            $service->reset($request->user(), isset($data['db_id']) ? (int) $data['db_id'] : null, $data['resource'] ?? null)
        );
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
}
