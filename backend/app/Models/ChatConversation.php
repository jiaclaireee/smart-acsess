<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatConversation extends Model
{
    protected $fillable = [
        'user_id',
        'database_id',
        'scope_key',
        'resource',
        'title',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function database(): BelongsTo
    {
        return $this->belongsTo(ConnectedDatabase::class, 'database_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }
}
