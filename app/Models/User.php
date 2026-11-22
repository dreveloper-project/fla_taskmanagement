<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string|null $username
 * @property string|null $phone
 * @property string|null $profile_picture_path
 * @property string $role
 * @property string $status
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'username', 'email', 'phone', 'password', 'profile_picture_path'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @var array<string, mixed> */
    protected $attributes = ['role' => 'staff', 'status' => 'active'];

    public function isAdmin(): bool
    {
        return in_array($this->role, ['superadmin', 'admin'], true);
    }

    public function isSuperadmin(): bool
    {
        return $this->role === 'superadmin';
    }

    public function isFinance(): bool
    {
        return $this->role === 'finance';
    }

    public function roleLabel(): string
    {
        return match ($this->role) {
            'superadmin' => 'Superadmin',
            'admin' => 'Admin',
            'finance' => 'Finance',
            'staff' => 'Staff',
            default => $this->role,
        };
    }

    public function isStaff(): bool
    {
        return $this->role === 'staff';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @param Builder<User> $query
     * @return Builder<User>
     */
    public function scopeAssignable(Builder $query): Builder
    {
        return $query->where('role', 'staff')->where('status', 'active');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
