<?php

namespace Database\Factories;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<\App\Models\User> */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Member,
            'is_active' => true,
            'avatar_color' => fake()->randomElement(['#2c7a7b', '#d4a017', '#6f42c1', '#e83e8c', '#17a2b8', '#fd7e14']),
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(['role' => UserRole::Admin]);
    }

    public function manager(): static
    {
        return $this->state(['role' => UserRole::Manager]);
    }

    public function member(): static
    {
        return $this->state(['role' => UserRole::Member]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
