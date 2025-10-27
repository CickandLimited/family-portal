<?php

namespace Tests\Unit\Services;

use App\Services\PlanImportService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PlanImportServiceTest extends TestCase
{
    private PlanImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new PlanImportService();
    }

    public function testParseMarkdownStructure(): void
    {
        $parsed = $this->service->parseMarkdown($this->loadFixture('sample_plan.md'));

        $this->assertSame('Spring Break Adventure', $parsed['title']);
        $this->assertCount(3, $parsed['days']);
        $this->assertSame([1, 2, 3], array_column($parsed['days'], 'heading_number'));
        $this->assertSame(['Arrival', 'Exploration', 'Farewell'], array_column($parsed['days'], 'title'));

        $firstDayXp = array_column($parsed['days'][0]['subtasks'], 'xp');
        $this->assertSame([20, 15], $firstDayXp);

        $secondDayTexts = array_column($parsed['days'][1]['subtasks'], 'text');
        $this->assertSame([
            'Visit the science museum',
            'Try the local cafe',
        ], $secondDayTexts);
        $this->assertSame([10, 5], array_column($parsed['days'][1]['subtasks'], 'xp'));
        $this->assertSame([25], array_column($parsed['days'][2]['subtasks'], 'xp'));
    }

    public function testParseMarkdownDefaultsMissingXpToTen(): void
    {
        $parsed = $this->service->parseMarkdown($this->loadFixture('missing_xp_annotations.md'));

        $this->assertSame('No XP Provided', $parsed['title']);
        $this->assertCount(1, $parsed['days']);
        $this->assertSame([10, 10], array_column($parsed['days'][0]['subtasks'], 'xp'));
    }

    /**
     * @dataProvider validationErrorProvider
     */
    public function testParseMarkdownValidationErrors(string $filename, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->service->parseMarkdown($this->loadFixture($filename));
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public static function validationErrorProvider(): array
    {
        return [
            ['duplicate_day_numbers.md', 'Day headings must be sequential starting at 1; expected Day 2, found Day 1.'],
            ['empty_day_tasks.md', 'Day 2 has no checklist items.'],
        ];
    }

    private function loadFixture(string $filename): string
    {
        $path = __DIR__.'/../../Fixtures/'.$filename;
        $contents = @file_get_contents($path);
        if ($contents === false) {
            $this->fail(sprintf('Unable to load fixture file: %s', $filename));
        }

        return $contents;
    }
}
