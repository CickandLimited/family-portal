<?php

namespace App\Services;

use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\PlanDay;
use App\Models\Subtask;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class PlanImportService
{
    private const DAY_HEADING_PATTERN = '/^##\s+Day\s+(?P<day_number>\d+)\s+[\x{2013}\x{2014}-]\s+(?P<title>.+)$/u';
    private const TASK_PATTERN = '/^[*-]\s+\[\s?]\s*(?P<text>.+)$/';
    private const XP_SUFFIX_PATTERN = '/\((?P<xp>\d+)\s*XP\)$/i';

    /**
     * Parse a markdown document into a structured array representation.
     *
     * @return array{
     *     title: string,
     *     days: list<array{
     *         heading_number: int,
     *         title: string,
     *         subtasks: list<array{text: string, xp: int}>
     *     }>
     * }
     */
    public function parseMarkdown(string $markdown): array
    {
        $lines = preg_split("/(?:\r\n|\n|\r)/", $markdown);
        if ($lines === false || $lines === []) {
            throw new InvalidArgumentException('Markdown plan is empty.');
        }

        $title = null;
        $days = [];
        $currentDay = null;

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }

            if ($title === null) {
                if (str_starts_with($line, '# ')) {
                    $title = trim(substr($line, 2));
                    if ($title === '') {
                        throw new InvalidArgumentException("Markdown plan must start with a '# ' title heading.");
                    }
                    continue;
                }

                throw new InvalidArgumentException("Markdown plan must start with a '# ' title heading.");
            }

            if (str_starts_with($line, '# ')) {
                // Skip any other top-level headings.
                continue;
            }

            if (preg_match(self::DAY_HEADING_PATTERN, $line, $dayMatch) === 1) {
                $dayNumber = (int) $dayMatch['day_number'];

                if ($currentDay === null) {
                    $expectedNumber = 1;
                } else {
                    if ($currentDay['subtasks'] === []) {
                        throw new InvalidArgumentException(sprintf('Day %d has no checklist items.', $currentDay['heading_number']));
                    }
                    $expectedNumber = $currentDay['heading_number'] + 1;
                    $days[] = $currentDay;
                }

                if ($dayNumber !== $expectedNumber) {
                    throw new InvalidArgumentException(sprintf(
                        'Day headings must be sequential starting at 1; expected Day %d, found Day %d.',
                        $expectedNumber,
                        $dayNumber,
                    ));
                }

                $dayTitle = trim($dayMatch['title']);
                if ($dayTitle === '') {
                    throw new InvalidArgumentException('Day title cannot be empty.');
                }

                $currentDay = [
                    'title' => $dayTitle,
                    'heading_number' => $dayNumber,
                    'subtasks' => [],
                ];

                continue;
            }

            if (preg_match(self::TASK_PATTERN, $line, $taskMatch) === 1) {
                if ($currentDay === null) {
                    throw new InvalidArgumentException('Checklist items must appear under a day heading.');
                }

                $taskText = trim($taskMatch['text']);
                [$cleanedText, $xpValue] = $this->extractXpValue($taskText);

                $currentDay['subtasks'][] = [
                    'text' => $cleanedText,
                    'xp' => $xpValue,
                ];

                continue;
            }

            throw new InvalidArgumentException(sprintf("Unrecognized markdown content: '%s'.", $line));
        }

        if ($title === null) {
            throw new InvalidArgumentException('Markdown plan is empty; no title heading found.');
        }

        if ($currentDay === null) {
            throw new InvalidArgumentException('No day sections found in markdown plan.');
        }

        if ($currentDay['subtasks'] === []) {
            throw new InvalidArgumentException(sprintf('Day %d has no checklist items.', $currentDay['heading_number']));
        }

        $days[] = $currentDay;

        return [
            'title' => $title,
            'days' => $days,
        ];
    }

    /**
     * Parse markdown from a file path.
     */
    public function parseFile(string $path): array
    {
        $markdown = @file_get_contents($path);
        if ($markdown === false) {
            throw new RuntimeException(sprintf('Unable to read markdown file at path %s.', $path));
        }

        return $this->parseMarkdown($markdown);
    }

    public function importFromFile(string $path, int $assigneeUserId, ?int $creatorUserId = null): Plan
    {
        $markdown = @file_get_contents($path);
        if ($markdown === false) {
            throw new RuntimeException(sprintf('Unable to read markdown file at path %s.', $path));
        }

        return $this->importFromMarkdown($markdown, $assigneeUserId, $creatorUserId);
    }

    public function importFromMarkdown(string $markdown, int $assigneeUserId, ?int $creatorUserId = null): Plan
    {
        $planData = $this->parseMarkdown($markdown);

        return DB::transaction(function () use ($planData, $assigneeUserId, $creatorUserId): Plan {
            $plan = new Plan();
            $plan->title = $planData['title'];
            $plan->assignee_user_id = $assigneeUserId;
            $plan->status = PlanStatus::IN_PROGRESS;
            if ($creatorUserId !== null) {
                $plan->created_by_user_id = $creatorUserId;
            }
            $plan->total_xp = 0;
            $plan->save();

            $totalXp = 0;

            foreach ($planData['days'] as $index => $day) {
                $planDay = new PlanDay();
                $planDay->plan_id = $plan->getKey();
                $planDay->day_index = $index;
                $planDay->title = $day['title'];
                $planDay->locked = $index !== 0;
                $planDay->save();

                foreach ($day['subtasks'] as $orderIndex => $subtask) {
                    $task = new Subtask();
                    $task->plan_day_id = $planDay->getKey();
                    $task->order_index = $orderIndex;
                    $task->text = $subtask['text'];
                    $task->xp_value = $subtask['xp'];
                    $task->save();

                    $totalXp += $subtask['xp'];
                }
            }

            $plan->total_xp = $totalXp;
            $plan->save();

            $plan->load([
                'days' => static fn ($query) => $query->orderBy('day_index'),
                'days.subtasks' => static fn ($query) => $query->orderBy('order_index'),
            ]);

            return $plan;
        });
    }

    /**
     * @return array{string, int}
     */
    private function extractXpValue(string $text): array
    {
        if (preg_match(self::XP_SUFFIX_PATTERN, $text, $match) === 1) {
            $xp = (int) $match['xp'];
            $cleanedText = rtrim(substr($text, 0, -strlen($match[0])));
            if ($cleanedText === '') {
                throw new InvalidArgumentException('Task description cannot be empty.');
            }

            return [$cleanedText, $xp];
        }

        $cleanedText = trim($text);
        if ($cleanedText === '') {
            throw new InvalidArgumentException('Task description cannot be empty.');
        }

        return [$cleanedText, 10];
    }
}
