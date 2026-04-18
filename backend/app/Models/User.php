<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_END_USER = 'end_user';

    public const APPROVAL_PENDING = 'pending';
    public const APPROVAL_APPROVED = 'approved';
    public const APPROVAL_REJECTED = 'rejected';

    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'contact_no',
        'office_department',
        'college_course',
        'email',
        'password',
        'google_id',
        'auth_provider',
        'avatar_url',
        'role',
        'approval_status',
    ];

    protected $attributes = [
        'role' => self::ROLE_END_USER,
        'approval_status' => self::APPROVAL_PENDING,
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isApproved(): bool
    {
        return $this->approval_status === self::APPROVAL_APPROVED;
    }

    public function chatConversations(): HasMany
    {
        return $this->hasMany(ChatConversation::class);
    }

    public function auditTrails(): HasMany
    {
        return $this->hasMany(AuditTrail::class);
    }
}
