<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'avatar',
        'is_active',
        'department_id',
        'last_login_at',
        'password_changed_at',
        'failed_login_attempts',
        'locked_until',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
        'password_changed_at' => 'datetime',
        'locked_until' => 'datetime',
    ];

    /**
     * Smart password mutator:
     * - If value is already a valid bcrypt/argon2 hash, store directly without re-hashing.
     * - If value is plain text, hash it with Hash::make().
     * Guarantees 100% immunity against double hashing across seeders, factories, and updates.
     */
    public function setPasswordAttribute($value): void
    {
        if (!is_string($value) || $value === '') {
            return;
        }

        // Check if string is already a valid bcrypt hash ($2y$, $2a$, $2b$) or argon2 hash
        if (preg_match('/^\$2[ayb]\$\d{2}\$[A-Za-z0-9\.\/]{53}$/', $value) || preg_match('/^\$argon2(id|i)\$/', $value)) {
            $this->attributes['password'] = $value;
        } else {
            $this->attributes['password'] = \Illuminate\Support\Facades\Hash::make($value);
        }
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }
}
