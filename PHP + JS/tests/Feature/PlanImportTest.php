<?php

namespace Tests\Feature;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanImportTest extends TestCase
{
    use RefreshDatabase;

    private PlanImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(PlanImportService::class);
    }

    public function testImportFromMarkdownCreatesFullHierarchy(): void
    {
        $assignee = User::factory()->create();
        $creator = User::factory()->admin()->create();

        $plan = $this->service->importFromMarkdown($this->loadFixture('sample_plan.md'), $assignee->id, $creator->id);

        $this->assertInstanceOf(Plan::class, $plan);
        $this->assertSame('Spring Break Adventure', $plan->title);
        $this->assertSame($assignee->id, $plan->assignee_user_id);
        $this->assertSame($creator->id, $plan->created_by_user_id);
        $this->assertTrue($plan->status === PlanStatus::IN_PROGRESS);
        $this->assertSame(75, $plan->total_xp);

        $this->assertCount(3, $plan->days);
        $this->assertSame([0, 1, 2], $plan->days->pluck('day_index')->all());
        $this->assertSame([false, true, true], $plan->days->pluck('locked')->all());
        $this->assertSame(['Arrival', 'Exploration', 'Farewell'], $plan->days->pluck('title')->all());

        $firstDayTasks = $plan->days[0]->subtasks;
        $this->assertSame([0, 1], $firstDayTasks->pluck('order_index')->all());
        $this->assertSame(['Check in at hotel', 'Walk the boardwalk'], $firstDayTasks->pluck('text')->all());
        $this->assertSame([20, 15], $firstDayTasks->pluck('xp_value')->all());

        $secondDayTasks = $plan->days[1]->subtasks;
        $this->assertSame([0, 1], $secondDayTasks->pluck('order_index')->all());
        $this->assertSame([
            'Visit the science museum',
            'Try the local cafe',
        ], $secondDayTasks->pluck('text')->all());
        $this->assertSame([10, 5], $secondDayTasks->pluck('xp_value')->all());

        $thirdDayTasks = $plan->days[2]->subtasks;
        $this->assertSame([0], $thirdDayTasks->pluck('order_index')->all());
        $this->assertSame(['Pack souvenirs'], $thirdDayTasks->pluck('text')->all());
        $this->assertSame([25], $thirdDayTasks->pluck('xp_value')->all());
    }

    private function loadFixture(string $filename): string
    {
        $path = __DIR__.'/../Fixtures/'.$filename;
        $contents = @file_get_contents($path);
        $this->assertNotFalse($contents, sprintf('Unable to load fixture file: %s', $filename));

        return $contents;
    }
}
