<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Category> */
class CategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'color' => fake()->randomElement(['#2c7a7b', '#d4a017', '#6f42c1', '#e83e8c', '#17a2b8', '#fd7e14']),
            'description' => fake()->sentence(),
        ];
    }
}
