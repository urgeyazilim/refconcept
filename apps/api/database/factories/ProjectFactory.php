<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domains\Catalog\Enums\RoomType;
use App\Domains\Identity\Models\User;
use App\Domains\Projects\Enums\MeasurementQuality;
use App\Domains\Projects\Enums\ProjectType;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
final class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => ucfirst($this->faker->words(2, true)).' Dairesi',
            'project_type' => ProjectType::Home,
            'currency' => 'TRY',
            'budget_minor' => $this->faker->numberBetween(50, 500) * 100_000,
        ];
    }

    /** A project owned by somebody in particular. */
    public function ownedBy(User $user): self
    {
        return $this->state(fn (): array => ['user_id' => $user->getKey()]);
    }

    /**
     * A project with one measured room — the fixture most tests actually want.
     *
     * The room has no photograph: a test that needs one uploads it, because the
     * upload path is what the privacy rules live in and faking it would test nothing.
     */
    public function withRoom(RoomType $type = RoomType::LivingRoom): self
    {
        return $this->afterCreating(function (Project $project) use ($type): void {
            Room::query()->create([
                'project_id' => $project->getKey(),
                'name' => $type->label(),
                'room_type' => $type,
                'measurement_quality' => MeasurementQuality::Manual,
                'width_mm' => 4200,
                'length_mm' => 5600,
                'height_mm' => 2700,
            ]);
        });
    }
}
