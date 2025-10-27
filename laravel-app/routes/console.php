<?php

use App\Services\PlanImportService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('plan:import {assignee : The ID of the plan assignee} {path : Path to the markdown file} {--creator= : Optional creator user ID}', function (
    PlanImportService $planImportService
) {
    $path = (string) $this->argument('path');
    if (!is_file($path) || !is_readable($path)) {
        $this->error(sprintf('Markdown file not found or unreadable at path %s.', $path));

        return Command::FAILURE;
    }

    $contents = @file_get_contents($path);
    if ($contents === false) {
        $this->error(sprintf('Unable to read markdown file at path %s.', $path));

        return Command::FAILURE;
    }

    if (!mb_check_encoding($contents, 'UTF-8')) {
        $this->error('Markdown files must be valid UTF-8 text.');

        return Command::FAILURE;
    }

    $assigneeId = (int) $this->argument('assignee');
    $creatorOption = $this->option('creator');
    $creatorId = is_numeric($creatorOption) ? (int) $creatorOption : null;

    try {
        $plan = $planImportService->importFromMarkdown($contents, $assigneeId, $creatorId);
    } catch (\InvalidArgumentException $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }

    $this->info(sprintf('Plan imported successfully (ID %d).', $plan->getKey()));

    return Command::SUCCESS;
})->purpose('Import a plan from a structured markdown document');
