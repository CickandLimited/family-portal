<?php

namespace App\Http\Controllers;

use App\Enums\PlanStatus;
use App\Models\Device;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class BoardController extends Controller
{
    public function index(Request $request): ViewContract
    {
        $board = $this->buildBoardContext();
        $device = $request->attributes->get('device');

        $partial = $request->query('partial');
        if ($this->isHtmx($request) && is_string($partial)) {
            $view = match ($partial) {
                'plan-summary' => 'components.board-plan-summary',
                'user-cards' => 'components.board-user-cards',
                default => null,
            };

            if ($view !== null) {
                return view($view, [
                    'board' => $board,
                ]);
            }
        }

        return view('board', [
            'title' => 'Family Board',
            'board' => $board,
            'device' => $device instanceof Device ? $device : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBoardContext(): array
    {
        /** @var Collection<int, User> $users */
        $users = User::query()
            ->where('is_active', true)
            ->with(['xpEvents' => fn ($query) => $query->orderByDesc('created_at')])
            ->orderBy('display_name')
            ->get();

        /** @var Collection<int, Plan> $plans */
        $plans = Plan::query()
            ->with(['assignee', 'days.subtasks'])
            ->orderByDesc('created_at')
            ->get();

        /** @var Collection<int, Device> $devices */
        $devices = Device::query()->get();

        $plansByUser = $plans->groupBy(static fn (Plan $plan) => $plan->assignee_user_id);

        $activeApproved = 0;
        $activeTotal = 0;
        $activeDaysComplete = 0;
        $activeDaysTotal = 0;

        $planProgressMap = [];

        foreach ($plans as $plan) {
            $progress = $this->progress()->calculatePlanProgress($plan);
            $planProgressMap[$plan->getKey()] = $progress;

            if ($plan->status === PlanStatus::IN_PROGRESS) {
                $activeApproved += $progress->approvedSubtasks;
                $activeTotal += $progress->totalSubtasks;
                $activeDaysComplete += $progress->completedDays;
                $activeDaysTotal += $progress->totalDays;
            }
        }

        $boardUsers = [];
        $familyTotalXp = 0;

        foreach ($users as $user) {
            /** @var Collection<int, Plan> $userPlans */
            $userPlans = $plansByUser->get($user->getKey(), collect());
            $activePlans = $userPlans->filter(fn (Plan $plan) => $plan->status === PlanStatus::IN_PROGRESS);
            $completedPlans = $userPlans->filter(fn (Plan $plan) => $plan->status === PlanStatus::COMPLETE);

            $xpEvents = $user->xpEvents->sortByDesc('created_at')->values();
            $totalXp = $this->xp()->calculateUserTotalXp($xpEvents);
            $familyTotalXp += $totalXp;

            $progress = $this->xp()->progressForTotalXp($totalXp);

            $mostRecentPlan = $this->resolveMostRecentPlan($userPlans, $activePlans);
            $currentPlan = null;

            if ($mostRecentPlan instanceof Plan) {
                $planProgress = $planProgressMap[$mostRecentPlan->getKey()] ?? null;
                $currentPlan = [
                    'id' => $mostRecentPlan->getKey(),
                    'title' => $mostRecentPlan->title,
                    'status' => Str::title(str_replace('_', ' ', $mostRecentPlan->status->value)),
                    'total_xp' => (int) $mostRecentPlan->total_xp,
                    'is_active' => $mostRecentPlan->status === PlanStatus::IN_PROGRESS,
                    'progress' => null,
                ];

                if ($planProgress !== null) {
                    $currentPlan['progress'] = [
                        'percent' => $planProgress->percentComplete(),
                        'approved_subtasks' => $planProgress->approvedSubtasks,
                        'total_subtasks' => $planProgress->totalSubtasks,
                        'completed_days' => $planProgress->completedDays,
                        'total_days' => $planProgress->totalDays,
                        'day_percent' => $planProgress->dayPercentComplete(),
                    ];
                }
            }

            $linkedDevices = $devices->filter(
                fn (Device $device) => $device->linked_user_id !== null
                    && (int) $device->linked_user_id === (int) $user->getKey()
            );

            $boardUsers[] = [
                'id' => $user->getKey(),
                'display_name' => $user->display_name,
                'avatar' => $user->avatar,
                'level' => $progress->level,
                'total_xp' => $totalXp,
                'xp_into_level' => $progress->xpIntoLevel,
                'xp_to_next_level' => $progress->xpToNextLevel,
                'progress_percent' => $progress->progressPercent,
                'active_plan' => $currentPlan,
                'plan_counts' => [
                    'total' => $userPlans->count(),
                    'active' => $activePlans->count(),
                    'completed' => $completedPlans->count(),
                ],
                'device_count' => $linkedDevices->count(),
                'xp_history' => $xpEvents
                    ->take(5)
                    ->map(function ($event) {
                        $timestamp = null;
                        if ($event->created_at !== null) {
                            $timestamp = $event->created_at->copy()->utc()->format('M d, H:i') . ' UTC';
                        }

                        return [
                            'reason' => $event->reason,
                            'label' => $this->xp()->reasonLabel($event->reason),
                            'delta' => (int) $event->delta,
                            'created_display' => $timestamp,
                        ];
                    })->all(),
            ];
        }

        $activePlanCount = $plans->filter(fn (Plan $plan) => $plan->status === PlanStatus::IN_PROGRESS)->count();
        $completedPlanCount = $plans->filter(fn (Plan $plan) => $plan->status === PlanStatus::COMPLETE)->count();

        $totals = [
            'user_count' => $users->count(),
            'plan_count' => $plans->count(),
            'active_plan_count' => $activePlanCount,
            'completed_plan_count' => $completedPlanCount,
            'device_count' => $devices->count(),
            'family_total_xp' => $familyTotalXp,
            'active_plan_progress' => [
                'approved_subtasks' => $activeApproved,
                'total_subtasks' => $activeTotal,
                'percent' => $activeTotal === 0 ? 0 : (int) round(($activeApproved / $activeTotal) * 100),
                'completed_days' => $activeDaysComplete,
                'total_days' => $activeDaysTotal,
                'day_percent' => $activeDaysTotal === 0 ? 0 : (int) round(($activeDaysComplete / $activeDaysTotal) * 100),
            ],
        ];

        return [
            'users' => $boardUsers,
            'totals' => $totals,
            'has_any_plans' => $totals['plan_count'] > 0,
            'has_any_users' => !empty($boardUsers),
        ];
    }

    /**
     * @param Collection<int, Plan> $userPlans
     * @param Collection<int, Plan> $activePlans
     */
    private function resolveMostRecentPlan(Collection $userPlans, Collection $activePlans): ?Plan
    {
        if ($activePlans->isNotEmpty()) {
            return $activePlans
                ->sortByDesc(fn (Plan $plan) => $plan->updated_at ?? $plan->created_at)
                ->first();
        }

        if ($userPlans->isNotEmpty()) {
            return $userPlans
                ->sortByDesc(fn (Plan $plan) => $plan->updated_at ?? $plan->created_at)
                ->first();
        }

        return null;
    }
}
