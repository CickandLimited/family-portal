<?php

namespace Tests\Feature;

use App\Enums\ApprovalMood;
use App\Enums\PlanStatus;
use App\Enums\SubtaskStatus;
use App\Http\Middleware\EnsureDeviceCookie;
use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\Plan;
use App\Models\PlanDay;
use App\Models\Subtask;
use App\Models\SubtaskSubmission;
use App\Models\User;
use App\Models\XPEvent;
use App\Services\XP\XPService;
use Illuminate\Http\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReviewControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_assignee_cannot_approve_own_submission(): void
    {
        $assignee = User::factory()->create();
        $plan = Plan::factory()->for($assignee, 'assignee')->create();
        $planDay = PlanDay::factory()->for($plan)->create(['locked' => false]);
        $subtask = Subtask::factory()
            ->for($planDay, 'planDay')
            ->status(SubtaskStatus::SUBMITTED)
            ->create();
        $submission = SubtaskSubmission::factory()->for($subtask, 'subtask')->create();

        $reviewDevice = Device::factory()->create();

        $response = $this
            ->withCookie(EnsureDeviceCookie::COOKIE_NAME, $reviewDevice->getKey())
            ->withHeaders(['HX-Request' => 'true'])
            ->actingAs($assignee)
            ->post(route('review.approve', ['subtask' => $subtask->getKey()]), [
                'mood' => ApprovalMood::HAPPY->value,
                'submission_id' => $submission->getKey(),
                'notes' => '',
            ]);

        $response->assertStatus(403);
        $this->assertSame(SubtaskStatus::SUBMITTED, $subtask->fresh()->status);
        $this->assertSame(0, XPEvent::count());
    }

    public function test_linked_device_cannot_approve_submission(): void
    {
        $assignee = User::factory()->create();
        $plan = Plan::factory()->for($assignee, 'assignee')->create();
        $planDay = PlanDay::factory()->for($plan)->create(['locked' => false]);
        $subtask = Subtask::factory()
            ->for($planDay, 'planDay')
            ->status(SubtaskStatus::SUBMITTED)
            ->create();
        $submission = SubtaskSubmission::factory()->for($subtask, 'subtask')->create();

        $linkedDevice = Device::factory()->create(['linked_user_id' => $assignee->getKey()]);

        $request = Request::create(route('review.partials.queue'), 'GET', [], [], [], ['HTTP_HX-Request' => 'true']);
        $request->attributes->set('device', $linkedDevice);

        $view = app(\App\Http\Controllers\ReviewController::class)->queuePartial($request);
        $data = $view->getData();
        $items = $data['items'] ?? [];

        $this->assertIsArray($items);
        $this->assertNotEmpty($items);
        $this->assertFalse($items[0]['approval_allowed']);
        $this->assertSame(
            'Devices linked to the assignee cannot approve this submission.',
            $items[0]['approval_message']
        );
    }

    public function test_approve_awards_xp_and_bonuses(): void
    {
        $assignee = User::factory()->create();
        $plan = Plan::factory()->for($assignee, 'assignee')->create([
            'status' => PlanStatus::IN_PROGRESS,
            'total_xp' => 0,
        ]);
        $planDay = PlanDay::factory()->for($plan)->create([
            'locked' => true,
            'day_index' => 0,
        ]);
        $subtask = Subtask::factory()
            ->for($planDay, 'planDay')
            ->status(SubtaskStatus::SUBMITTED)
            ->create([
                'xp_value' => 30,
            ]);
        $submission = SubtaskSubmission::factory()->for($subtask, 'subtask')->create();

        $reviewDevice = Device::factory()->create();

        $response = $this
            ->withCookie(EnsureDeviceCookie::COOKIE_NAME, $reviewDevice->getKey())
            ->withHeaders(['HX-Request' => 'true'])
            ->post(route('review.approve', ['subtask' => $subtask->getKey()]), [
                'mood' => ApprovalMood::NEUTRAL->value,
                'submission_id' => $submission->getKey(),
                'notes' => 'Great work!',
            ]);

        $response->assertNoContent();
        $this->assertNotNull($response->headers->get('HX-Trigger'));

        $approvedSubtask = $subtask->fresh();
        $this->assertSame(SubtaskStatus::APPROVED, $approvedSubtask->status);

        $planDay->refresh();
        $plan->refresh();

        $this->assertSame(PlanStatus::COMPLETE, $plan->status);
        $this->assertSame(100, $plan->total_xp);
        $this->assertFalse((bool) $planDay->locked);

        $events = XPEvent::query()->orderBy('created_at')->get();
        $reasons = $events->pluck('reason')->all();
        $this->assertCount(3, $events, 'Actual reasons: ' . json_encode($reasons));
        $this->assertContains(XPService::XP_APPROVAL_REASON, $reasons);
        $this->assertContains(XPService::XP_DAY_COMPLETION_REASON, $reasons);
        $this->assertContains(XPService::XP_PLAN_COMPLETION_REASON, $reasons);

        $approvalEvent = $events->firstWhere('reason', XPService::XP_APPROVAL_REASON);
        $this->assertNotNull($approvalEvent);
        $this->assertSame(30, (int) $approvalEvent->delta);
        $this->assertSame($subtask->getKey(), $approvalEvent->subtask_id);

        $dayBonusEvent = $events->firstWhere('reason', XPService::XP_DAY_COMPLETION_REASON);
        $this->assertNotNull($dayBonusEvent);
        $this->assertSame(XPService::DAY_COMPLETION_BONUS, (int) $dayBonusEvent->delta);
        $this->assertNull($dayBonusEvent->subtask_id);

        $planBonusEvent = $events->firstWhere('reason', XPService::XP_PLAN_COMPLETION_REASON);
        $this->assertNotNull($planBonusEvent);
        $this->assertSame(XPService::PLAN_COMPLETION_BONUS, (int) $planBonusEvent->delta);
        $this->assertNull($planBonusEvent->subtask_id);

        $this->assertDatabaseCount('activity_log', 1);
        $activity = ActivityLog::first();
        $this->assertSame('subtask.approved', $activity->action);
        $this->assertSame($plan->getKey(), $activity->metadata['plan_id']);
        $this->assertSame('Great work!', $activity->metadata['approval_notes']);
        $this->assertCount(3, $activity->metadata['xp_events']);
    }
}
