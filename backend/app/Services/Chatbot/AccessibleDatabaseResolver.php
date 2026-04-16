<?php

namespace App\Services\Chatbot;

use App\Models\ConnectedDatabase;
use App\Models\User;
use Illuminate\Support\Collection;

class AccessibleDatabaseResolver
{
    public function forUser(User $user): Collection
    {
        if (!$user->isApproved()) {
            return collect();
        }

        return ConnectedDatabase::query()
            ->orderBy('id')
            ->get();
    }

    public function findForUser(User $user, int $databaseId): ?ConnectedDatabase
    {
        return $this->forUser($user)
            ->first(fn(ConnectedDatabase $database) => $database->id === $databaseId);
    }
}
