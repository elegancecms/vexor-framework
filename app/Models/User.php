<?php

declare(strict_types=1);

namespace App\Models;

use Vexor\Core\ORM\Model;

class User extends Model
{
    protected static string $table = 'users';

    protected array $fillable = [
        'name',
        'email',
        'password',
        'role',
        'api_key',
        'remember_token',
        'email_verified_at',
        'two_factor_secret',
        'two_factor_enabled',
    ];

    protected array $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'api_key',
    ];

    protected array $casts = [
        'two_factor_enabled' => 'boolean',
        'email_verified_at'  => 'datetime',
    ];

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function posts(): array
    {
        return $this->hasMany(Post::class, 'user_id');
    }
}
