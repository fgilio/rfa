<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\LaunchTimelineService;

/**
 * Reads the most recent cold launches from a log directory and summarises
 * them: every mark per launch, the median per mark, and the phase spans.
 *
 * @phpstan-import-type Launch from LaunchTimelineService
 */
final readonly class BuildLaunchReportAction
{
    public function __construct(
        private LaunchTimelineService $timeline,
    ) {}

    /** @return array{launches: list<Launch>, medians: array<string, int>, phases: array<string, int>} */
    public function handle(string $logsDirectory, int $launches): array
    {
        $recent = $this->timeline->launches($logsDirectory, max(1, $launches));
        $medians = $this->timeline->medians($recent);

        return [
            'launches' => $recent,
            'medians' => $medians,
            'phases' => $this->timeline->phases($medians),
        ];
    }
}
