<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
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
            'name' => fake()->name(),
            'email' => 'personne-'.Str::lower(Str::random(16)).'@example.test',
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make(Str::random(64)),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Compte provisionné sans adresse électronique, déjà activé par son titulaire.
     */
    public function provisioned(): static
    {
        return $this->state(fn (array $attributes) => [
            'email' => null,
            'email_verified_at' => null,
        ])->afterMaking(fn (User $user) => $user->forceFill([
            'login_identifier' => 'HK-'.Str::upper(Str::random(8)),
            'activated_at' => now(),
        ]));
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
