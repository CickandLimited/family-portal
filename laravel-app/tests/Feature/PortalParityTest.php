<?php

namespace Tests\Feature;

use App\Enums\ApprovalMood;
use App\Enums\PlanStatus;
use App\Enums\SubtaskStatus;
use App\Http\Middleware\EnsureDeviceCookie;
use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\Plan;
use App\Models\Subtask;
use App\Models\SubtaskSubmission;
use App\Models\User;
use App\Models\XPEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Tests\TestCase;

final class PortalParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_import_review_and_board_flow_matches_fastapi_expectations(): void
    {
        $admin = User::factory()->admin()->create([
            'display_name' => 'Coach Casey',
        ]);
        $assignee = User::factory()->create([
            'display_name' => 'Skyler',
        ]);
        $spectator = User::factory()->create([
            'display_name' => 'Parker',
        ]);

        $markdown = file_get_contents(base_path('tests/Fixtures/sample_plan.md'));
        $this->assertIsString($markdown, 'Sample markdown fixture should be readable.');

        $upload = UploadedFile::fake()->createWithContent('spring-break.md', $markdown);

        $importResponse = $this
            ->actingAs($admin)
            ->post(route('admin.plans.import'), [
                'assignee_user_id' => (string) $assignee->getKey(),
                'file' => $upload,
            ]);

        $importResponse->assertOk()->assertJsonStructure(['plan_id']);

        $planId = (int) $importResponse->json('plan_id');
        $this->assertGreaterThan(0, $planId);

        $plan = Plan::query()->with('days.subtasks')->findOrFail($planId);
        $this->assertSame('Spring Break Adventure', $plan->title);
        $this->assertSame($assignee->getKey(), $plan->assignee_user_id);
        $this->assertSame($admin->getKey(), $plan->created_by_user_id);
        $this->assertSame(PlanStatus::IN_PROGRESS, $plan->status);
        $this->assertSame(75, (int) $plan->total_xp);

        $days = $plan->days->sortBy('day_index')->values();
        $this->assertCount(3, $days);
        $this->assertSame([0, 1, 2], $days->pluck('day_index')->all());
        $this->assertSame(['Arrival', 'Exploration', 'Farewell'], $days->pluck('title')->all());
        $this->assertSame([false, true, true], $days->pluck('locked')->map(fn ($locked) => (bool) $locked)->all());

        $importLog = ActivityLog::query()->where('action', 'plan.imported')->first();
        $this->assertNotNull($importLog);
        $this->assertSame('plan', $importLog->entity_type);
        $this->assertSame($plan->getKey(), $importLog->entity_id);
        $this->assertSame([
            'filename' => 'spring-break.md',
            'assignee_user_id' => $assignee->getKey(),
        ], $importLog->metadata);

        $submissionDevice = Device::factory()->create([
            'id' => 'submission-device',
            'friendly_name' => 'Bedroom Camera',
        ]);
        $reviewDevice = Device::factory()->create([
            'id' => 'review-device',
            'friendly_name' => 'Family Tablet',
        ]);

        foreach ($days as $day) {
            foreach ($day->subtasks as $subtask) {
                $subtask->status = SubtaskStatus::SUBMITTED;
                $subtask->save();

                SubtaskSubmission::factory()->for($subtask, 'subtask')->create([
                    'submitted_by_device_id' => $submissionDevice->getKey(),
                    'submitted_by_user_id' => null,
                    'comment' => 'Proof for ' . $subtask->text,
                ]);
            }
        }

        $subtasks = Subtask::query()->orderBy('plan_day_id')->orderBy('order_index')->get();
        $this->assertCount(5, $subtasks);

        foreach ($subtasks as $subtask) {
            $submission = $subtask->submissions()->latest()->first();
            $this->assertNotNull($submission);

            $response = $this
                ->actingAs($admin)
                ->withCookie(EnsureDeviceCookie::COOKIE_NAME, $reviewDevice->getKey())
                ->withHeaders(['HX-Request' => 'true'])
                ->post(route('review.approve', ['subtask' => $subtask->getKey()]), [
                    'mood' => ApprovalMood::HAPPY->value,
                    'submission_id' => $submission->getKey(),
                    'notes' => 'Approved: ' . $subtask->text,
                ]);

            $response->assertNoContent();
            $this->assertTrue($response->headers->has('HX-Trigger'));
        }

        $plan->refresh()->load('days.subtasks');
        $this->assertSame(PlanStatus::COMPLETE, $plan->status);
        $this->assertSame(185, (int) $plan->total_xp);
        $this->assertSame([false, false, false], $plan->days->sortBy('day_index')->pluck('locked')->map(fn ($locked) => (bool) $locked)->all());
        $this->assertTrue($plan->days->flatMap->subtasks->every(fn ($task) => $task->status === SubtaskStatus::APPROVED));

        $this->assertSame(5, ActivityLog::query()->where('action', 'subtask.approved')->count());
        $this->assertSame(9, XPEvent::count());
        $this->assertSame(185, (int) XPEvent::query()->where('user_id', $assignee->getKey())->sum('delta'));

        $boardResponse = $this->get(route('board'));
        $boardResponse->assertOk()->assertViewIs('board');

        /** @var array<string, mixed> $board */
        $board = $boardResponse->viewData('board');
        $this->assertSame(1, Arr::get($board, 'totals.plan_count'));
        $this->assertSame(1, Arr::get($board, 'totals.completed_plan_count'));
        $this->assertSame(0, Arr::get($board, 'totals.active_plan_count'));
        $this->assertSame(185, Arr::get($board, 'totals.family_total_xp'));

        $boardUsers = collect(Arr::get($board, 'users', []));
        $this->assertCount(3, $boardUsers);

        $assigneeCard = $boardUsers->firstWhere('id', $assignee->getKey());
        $this->assertIsArray($assigneeCard);
        $this->assertSame('Skyler', $assigneeCard['display_name']);
        $this->assertSame(185, $assigneeCard['total_xp']);
        $this->assertSame(1, $assigneeCard['level']);
        $this->assertSame(85, $assigneeCard['xp_into_level']);
        $this->assertSame(15, $assigneeCard['xp_to_next_level']);

        $this->assertIsArray($assigneeCard['active_plan']);
        $this->assertSame($plan->getKey(), $assigneeCard['active_plan']['id']);
        $this->assertSame('Spring Break Adventure', $assigneeCard['active_plan']['title']);
        $this->assertSame('Complete', $assigneeCard['active_plan']['status']);
        $this->assertSame(185, $assigneeCard['active_plan']['total_xp']);
        $this->assertIsArray($assigneeCard['active_plan']['progress']);
        $this->assertSame(100, $assigneeCard['active_plan']['progress']['percent']);
        $this->assertSame(5, $assigneeCard['active_plan']['progress']['approved_subtasks']);
        $this->assertSame(5, $assigneeCard['active_plan']['progress']['total_subtasks']);
        $this->assertSame(3, $assigneeCard['active_plan']['progress']['completed_days']);
        $this->assertSame(3, $assigneeCard['active_plan']['progress']['total_days']);
        $this->assertSame(100, $assigneeCard['active_plan']['progress']['day_percent']);

        $spectatorCard = $boardUsers->firstWhere('id', $spectator->getKey());
        $this->assertIsArray($spectatorCard);
        $this->assertSame(0, $spectatorCard['total_xp']);
        $this->assertNull($spectatorCard['active_plan']);
    }

    public function test_admin_device_management_records_activity_and_updates_links(): void
    {
        $admin = User::factory()->admin()->create([
            'display_name' => 'Coach Casey',
        ]);
        $child = User::factory()->create([
            'display_name' => 'River',
        ]);

        $device = Device::factory()->create([
            'id' => 'kiosk-device',
            'friendly_name' => null,
        ]);

        $rename = $this
            ->actingAs($admin)
            ->post(route('admin.devices.rename', $device), [
                'friendly_name' => 'Kitchen Tablet',
            ]);

        $rename->assertStatus(303);
        $rename->assertRedirect(route('admin.devices.index'));

        $device->refresh();
        $this->assertSame('Kitchen Tablet', $device->friendly_name);

        $entityId = abs(crc32($device->getKey()));
        $renameLog = ActivityLog::query()
            ->where('action', 'device.renamed')
            ->where('entity_id', $entityId)
            ->first();
        $this->assertNotNull($renameLog);
        $this->assertSame([
            'previous_name' => null,
            'new_name' => 'Kitchen Tablet',
            'device_id' => $device->getKey(),
        ], $renameLog->metadata);

        $link = $this
            ->actingAs($admin)
            ->post(route('admin.devices.link-user', $device), [
                'user_id' => (string) $child->getKey(),
            ]);

        $link->assertStatus(303);
        $link->assertRedirect(route('admin.devices.index'));

        $device->refresh();
        $this->assertSame($child->getKey(), $device->linked_user_id);

        $linkLog = ActivityLog::query()
            ->where('action', 'device.user_linked')
            ->where('entity_id', $entityId)
            ->latest('timestamp')
            ->first();
        $this->assertNotNull($linkLog);
        $this->assertSame([
            'previous_user_id' => null,
            'new_user_id' => $child->getKey(),
            'device_id' => $device->getKey(),
            'new_user_name' => $child->display_name,
        ], $linkLog->metadata);

        $unlink = $this
            ->actingAs($admin)
            ->post(route('admin.devices.link-user', $device), [
                'user_id' => '',
            ]);

        $unlink->assertStatus(303);
        $unlink->assertRedirect(route('admin.devices.index'));

        $device->refresh();
        $this->assertNull($device->linked_user_id);

        $unlinkLog = ActivityLog::query()
            ->where('action', 'device.user_unlinked')
            ->where('entity_id', $entityId)
            ->latest('timestamp')
            ->first();
        $this->assertNotNull($unlinkLog);
        $this->assertSame([
            'previous_user_id' => $child->getKey(),
            'new_user_id' => null,
            'device_id' => $device->getKey(),
            'previous_user_name' => $child->display_name,
        ], $unlinkLog->metadata);

        $this->assertSame(3, ActivityLog::query()->where('entity_id', $entityId)->count());
    }
}
