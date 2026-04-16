<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ConnectedDatabaseController;
use App\Http\Controllers\Api\SchemaController;
use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ChatbotController;
use App\Http\Controllers\Api\DashboardAnalyticsController;

Route::get('/health', fn() => response()->json(['ok' => true]));

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/register', [AuthController::class, 'register']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', fn(Request $req) => $req->user());

    Route::middleware('approved')->group(function () {
        Route::get('/databases', [ConnectedDatabaseController::class, 'index']);
        Route::get('/databases/{database}', [ConnectedDatabaseController::class, 'show'])->whereNumber('database');
        Route::get('/databases/{database}/tables', [ConnectedDatabaseController::class, 'tables'])->whereNumber('database');
        Route::get('/databases/{database}/schema', [SchemaController::class, 'inspect'])->whereNumber('database');
        Route::post('/analytics/report', [DashboardAnalyticsController::class, 'report']);

        Route::post('/metrics/run', [MetricsController::class, 'run']);
        Route::post('/reports/pdf', [ReportsController::class, 'pdf']);
        Route::post('/reports/dashboard-pdf', [ReportsController::class, 'dashboardPdf']);
        Route::post('/chat', [ChatController::class, 'chat']);
        Route::post('/chatbot/context', [ChatbotController::class, 'context']);
        Route::get('/chatbot/knowledge/status', [ChatbotController::class, 'knowledgeStatus']);
        Route::post('/chatbot/ask', [ChatbotController::class, 'ask']);
        Route::get('/chatbot/history', [ChatbotController::class, 'history']);
        Route::post('/chatbot/reset', [ChatbotController::class, 'reset']);

        Route::middleware('role:admin')->group(function () {
            Route::apiResource('/users', UserController::class)->except(['index']);
            Route::get('/users', [UserController::class, 'index']);
            Route::patch('/users/{user}/approval', [UserController::class, 'updateApproval']);

            Route::get('/databases/options', [ConnectedDatabaseController::class, 'options']);
            Route::post('/databases', [ConnectedDatabaseController::class, 'store']);
            Route::post('/databases/{database}/test', [ConnectedDatabaseController::class, 'testConnection'])->whereNumber('database');
            Route::put('/databases/{database}', [ConnectedDatabaseController::class, 'update'])->whereNumber('database');
            Route::delete('/databases/{database}', [ConnectedDatabaseController::class, 'destroy'])->whereNumber('database');
            Route::post('/chatbot/knowledge/sync', [ChatbotController::class, 'syncKnowledge']);
        });
    });
});
