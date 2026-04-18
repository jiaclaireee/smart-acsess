<?php

namespace App\Services;

use App\Models\AuditTrail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditTrailService
{
    public function record(
        Request $request,
        string $module,
        string $action,
        ?string $description = null,
        array $metadata = [],
        ?string $subjectType = null,
        string|int|null $subjectId = null,
    ): AuditTrail {
        /** @var User|null $user */
        $user = $request->user();

        return AuditTrail::create([
            'user_id' => $user?->id,
            'user_name' => $this->resolveUserName($user),
            'user_email' => $user?->email,
            'module' => $module,
            'action' => $action,
            'description' => $description ?: $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId === null ? null : (string) $subjectId,
            'metadata' => $this->sanitizeMetadata($metadata),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) ($request->userAgent() ?? ''), 500, ''),
        ]);
    }

    private function resolveUserName(?User $user): string
    {
        if (!$user) {
            return 'Unknown User';
        }

        $name = trim(implode(' ', array_filter([
            $user->first_name,
            $user->middle_name,
            $user->last_name,
        ])));

        return $name !== '' ? preg_replace('/\s+/', ' ', $name) ?? $name : $user->email;
    }

    private function sanitizeMetadata(array $metadata): array
    {
        return $this->sanitizeValue($metadata);
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $key => $item) {
                $sanitized[$key] = $this->sanitizeValue($item);
            }

            return $sanitized;
        }

        if (is_object($value)) {
            return $this->sanitizeValue((array) $value);
        }

        if (is_string($value)) {
            return Str::limit($value, 500);
        }

        return $value;
    }
}
