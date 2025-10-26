<?php

namespace Database\Seeders;

use App\Enums\ApprovalMood;
use App\Enums\PlanStatus;
use App\Enums\SubtaskStatus;
use App\Models\ActivityLog;
use App\Models\Approval;
use App\Models\Attachment;
use App\Models\Device;
use App\Models\Plan;
use App\Models\PlanDay;
use App\Models\Subtask;
use App\Models\SubtaskSubmission;
use App\Models\User;
use App\Models\XPEvent;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::factory()->admin()->create([
            'display_name' => 'Alex Admin',
            'avatar' => 'avatars/alex-admin.png',
        ]);

        $caregiver = User::factory()->create([
            'display_name' => 'Pat Caregiver',
        ]);

        $teen = User::factory()->create([
            'display_name' => 'Taylor Teen',
        ]);

        $adminDevice = Device::factory()->create([
            'friendly_name' => 'Admin Phone',
            'linked_user_id' => $admin->id,
        ]);

        $caregiverDevice = Device::factory()->create([
            'friendly_name' => 'Caregiver Tablet',
            'linked_user_id' => $caregiver->id,
        ]);

        $teenDevice = Device::factory()->create([
            'friendly_name' => 'Taylor Phone',
            'linked_user_id' => $teen->id,
        ]);

        $plan = Plan::factory()
            ->for($teen, 'assignee')
            ->for($admin, 'creator')
            ->state([
                'title' => 'Morning Independence Plan',
                'status' => PlanStatus::IN_PROGRESS,
                'total_xp' => 0,
            ])
            ->create();

        $monday = PlanDay::factory()->for($plan)->create([
            'day_index' => 0,
            'title' => 'Monday Kickoff',
            'locked' => false,
        ]);

        $tuesday = PlanDay::factory()->for($plan)->create([
            'day_index' => 1,
            'title' => 'Tuesday Momentum',
            'locked' => false,
        ]);

        $wednesday = PlanDay::factory()->for($plan)->create([
            'day_index' => 2,
            'title' => 'Wednesday Wrap-Up',
            'locked' => true,
        ]);

        $makeBed = Subtask::factory()
            ->for($monday, 'planDay')
            ->state([
                'order_index' => 0,
                'text' => 'Make your bed neatly.',
                'xp_value' => 15,
                'status' => SubtaskStatus::PENDING,
            ])
            ->create();

        $brushTeeth = Subtask::factory()
            ->for($monday, 'planDay')
            ->state([
                'order_index' => 1,
                'text' => 'Brush your teeth for two minutes.',
                'xp_value' => 20,
                'status' => SubtaskStatus::SUBMITTED,
            ])
            ->create();

        $packLunch = Subtask::factory()
            ->for($tuesday, 'planDay')
            ->state([
                'order_index' => 0,
                'text' => 'Pack a healthy lunch.',
                'xp_value' => 25,
                'status' => SubtaskStatus::APPROVED,
            ])
            ->create();

        $feedPets = Subtask::factory()
            ->for($tuesday, 'planDay')
            ->state([
                'order_index' => 1,
                'text' => 'Feed the pets and refresh water.',
                'xp_value' => 20,
                'status' => SubtaskStatus::DENIED,
            ])
            ->create();

        $homeworkPrep = Subtask::factory()
            ->for($wednesday, 'planDay')
            ->state([
                'order_index' => 0,
                'text' => 'Organize backpack for homework time.',
                'xp_value' => 30,
                'status' => SubtaskStatus::APPROVED,
            ])
            ->create();

        SubtaskSubmission::factory()
            ->for($brushTeeth, 'subtask')
            ->for($teenDevice, 'submittedByDevice')
            ->for($teen, 'submittedByUser')
            ->state([
                'photo_path' => 'uploads/submissions/brush-teeth.jpg',
                'comment' => 'Ready for review!'
            ])
            ->create();

        SubtaskSubmission::factory()
            ->for($packLunch, 'subtask')
            ->for($teenDevice, 'submittedByDevice')
            ->for($teen, 'submittedByUser')
            ->state([
                'photo_path' => 'uploads/submissions/packed-lunch.jpg',
                'comment' => 'Lunch packed with fruit and veggies.',
            ])
            ->create();

        SubtaskSubmission::factory()
            ->for($feedPets, 'subtask')
            ->for($caregiverDevice, 'submittedByDevice')
            ->state([
                'comment' => 'Water bowl spilled, please retry.',
            ])
            ->create();

        Approval::factory()
            ->for($packLunch, 'subtask')
            ->for($caregiverDevice, 'actedByDevice')
            ->actedByUser($caregiver)
            ->mood(ApprovalMood::HAPPY)
            ->state([
                'reason' => 'Excellent choices in the lunchbox!',
            ])
            ->create();

        Approval::factory()
            ->denied()
            ->for($feedPets, 'subtask')
            ->for($caregiverDevice, 'actedByDevice')
            ->actedByUser($caregiver)
            ->mood(ApprovalMood::SAD)
            ->state([
                'reason' => 'Water still on the floor, please clean up.',
            ])
            ->create();

        Approval::factory()
            ->for($homeworkPrep, 'subtask')
            ->for($adminDevice, 'actedByDevice')
            ->actedByUser($admin)
            ->mood(ApprovalMood::NEUTRAL)
            ->state([
                'reason' => 'Looks good, keep it up!',
            ])
            ->create();

        Attachment::factory()
            ->for($plan, 'plan')
            ->for($adminDevice, 'uploadedByDevice')
            ->uploadedBy($admin)
            ->state([
                'file_path' => 'uploads/resources/morning-checklist.pdf',
                'thumb_path' => 'uploads/thumbs/morning-checklist.png',
            ])
            ->create();

        Attachment::factory()
            ->for($brushTeeth, 'subtask')
            ->for($teenDevice, 'uploadedByDevice')
            ->uploadedBy($teen)
            ->state([
                'file_path' => 'uploads/resources/toothbrushing-chart.png',
                'thumb_path' => 'uploads/thumbs/toothbrushing-chart.png',
            ])
            ->create();

        ActivityLog::factory()->create([
            'timestamp' => now()->subHours(6),
            'device_id' => $adminDevice->id,
            'user_id' => $admin->id,
            'action' => 'created',
            'entity_type' => 'Plan',
            'entity_id' => $plan->id,
            'metadata' => ['title' => $plan->title],
        ]);

        ActivityLog::factory()->create([
            'timestamp' => now()->subHours(4),
            'device_id' => $teenDevice->id,
            'user_id' => $teen->id,
            'action' => 'submitted',
            'entity_type' => 'Subtask',
            'entity_id' => $brushTeeth->id,
            'metadata' => ['comment' => 'Ready for review!'],
        ]);

        ActivityLog::factory()->create([
            'timestamp' => now()->subHours(2),
            'device_id' => $caregiverDevice->id,
            'user_id' => $caregiver->id,
            'action' => 'approved',
            'entity_type' => 'Subtask',
            'entity_id' => $packLunch->id,
            'metadata' => ['mood' => ApprovalMood::HAPPY->value],
        ]);

        XPEvent::factory()->for($teen, 'user')->for($packLunch, 'subtask')->create([
            'delta' => 25,
            'reason' => 'Lunch packed independently',
        ]);

        XPEvent::factory()->for($teen, 'user')->for($homeworkPrep, 'subtask')->create([
            'delta' => 30,
            'reason' => 'Backpack prepped without reminders',
        ]);

        XPEvent::factory()->for($caregiver, 'user')->create([
            'subtask_id' => null,
            'delta' => 10,
            'reason' => 'Coaching Taylor through routines',
        ]);

        $plan->update([
            'total_xp' => $plan->subtasks()->sum('xp_value'),
        ]);
    }
}
