<?php

use App\Database\Models\User;
use Neo\Information\Datetime\Chronos;
use Neo\Information\Faker\Fake;
use Neo\Information\Persistence\ORM\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => Fake::name(),
            'email' => Fake::unique()->safeEmail(),
            'email_verified_at' => Chronos::now(),
            'password' => Fake::hashedPassword(),
            'remember_token' => Fake::rememberToken(),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}