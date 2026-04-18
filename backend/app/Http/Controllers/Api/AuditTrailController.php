<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use Illuminate\Http\Request;

class AuditTrailController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'module' => ['nullable', 'string', 'max:150'],
            'action' => ['nullable', 'string', 'max:150'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = AuditTrail::query()->latest();

        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('user_name', 'like', '%' . $search . '%')
                    ->orWhere('user_email', 'like', '%' . $search . '%')
                    ->orWhere('module', 'like', '%' . $search . '%')
                    ->orWhere('action', 'like', '%' . $search . '%')
                    ->orWhere('description', 'like', '%' . $search . '%');
            });
        }

        if (!empty($filters['module'])) {
            $query->where('module', $filters['module']);
        }

        if (!empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (!empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        return response()->json(
            $query->paginate(25)->through(fn (AuditTrail $trail) => [
                'id' => $trail->id,
                'user_name' => $trail->user_name,
                'user_email' => $trail->user_email,
                'module' => $trail->module,
                'action' => $trail->action,
                'description' => $trail->description,
                'subject_type' => $trail->subject_type,
                'subject_id' => $trail->subject_id,
                'metadata' => $trail->metadata ?? [],
                'ip_address' => $trail->ip_address,
                'user_agent' => $trail->user_agent,
                'created_at' => $trail->created_at?->toIso8601String(),
            ])
        );
    }
}
