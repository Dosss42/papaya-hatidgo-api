<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Sample users for local development and tests (Filipino names, @example.test emails,
 * +63 9XX phone numbers in E.164). Never used in production.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->randomElement(['Juan', 'Maria', 'Jose', 'Ana', 'Pedro', 'Liza', 'Ramon', 'Grace']),
            'last_name' => fake()->randomElement(['Dela Cruz', 'Santos', 'Reyes', 'Garcia', 'Mendoza', 'Bautista', 'Aquino']),
            'email' => fake()->unique()->userName().'@example.test',
            'phone' => '+639'.fake()->unique()->numerify('#########'),
            'role' => UserRole::Passenger,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function passenger(): static
    {
        return $this->state(fn () => ['role' => UserRole::Passenger]);
    }

    public function driver(): static
    {
        return $this->state(fn () => ['role' => UserRole::Driver]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => UserRole::Admin]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
