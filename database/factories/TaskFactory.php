<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Task> */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        $status = fake()->boolean(40) ? TaskStatus::Completed : TaskStatus::Pending;

        return [
            'user_id' => User::factory(),
            'title' => rtrim(fake()->sentence(fake()->numberBetween(3, 6)), '.'),
            'description' => fake()->boolean(70) ? fake()->paragraph() : null,
            'status' => $status,
            'priority' => fake()->randomElement(TaskPriority::cases()),
            'due_date' => fake()->boolean(70) ? fake()->dateTimeBetween('-5 days', '+20 days') : null,
            'completed_at' => $status === TaskStatus::Completed ? fake()->dateTimeBetween('-14 days', 'now') : null,
            'position' => 0,
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => TaskStatus::Pending, 'completed_at' => null]);
    }

    public function completed(): static
    {
        return $this->state(['status' => TaskStatus::Completed, 'completed_at' => now()]);
    }
}
