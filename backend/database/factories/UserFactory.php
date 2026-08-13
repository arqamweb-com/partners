<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SystemRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected $model = \App\Models\User::class;

    public function definition(): array
    {
        return [
            'id'          => (string) Str::uuid(),
            'email'       => $this->faker->unique()->safeEmail(),
            'password'    => 'password123',
            'full_name'   => $this->faker->name(),
            'system_role' => SystemRole::Client,
            'is_active'   => true,
        ];
    }

    public function admin(): static
    {
        return $this->state(['system_role' => SystemRole::Admin, 'full_name' => 'أدمن أرقام']);
    }

    public function manager(): static
    {
        return $this->state(['system_role' => SystemRole::Manager, 'full_name' => 'مدير المشاريع']);
    }

    public function supervisor(): static
    {
        return $this->state(['system_role' => SystemRole::Supervisor, 'full_name' => 'مشرف التنفيذ']);
    }

    public function partner(string $agency = 'وكالة الشريك'): static
    {
        return $this->state([
            'system_role'    => SystemRole::Partner,
            'partner_agency' => $agency,
            'full_name'      => 'مسؤول الوكالة الشريكة',
        ]);
    }
}
