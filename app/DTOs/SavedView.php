<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\LastViewKind;
use App\Enums\LastViewMode;
use InvalidArgumentException;

/**
 * "The page the user was last looking at" for one project, as a single value.
 *
 * The `review_sessions` table stores this as four independent nullable columns
 * (`last_view_mode`, `last_view_kind`, `last_view_from`, `last_view_to`), which
 * can hold tuples no view actually produces (a Commit row with no `to`, a
 * Context row carrying refs). This value is the one place those columns are
 * interpreted: {@see PersistProjectViewAction} writes what it says, and
 * {@see ResolveProjectEntryUrlAction} reads what it says.
 *
 * Construction is factory-only, one factory per view kind, so an invalid tuple
 * cannot become a `SavedView`. Reading goes through {@see self::fromColumns()},
 * which is total: anything malformed degrades to the working tree rather than
 * throwing, because re-entry must never fail closed.
 *
 * `SinceBase` deliberately carries no refs. It is stored as semantic intent and
 * the merge-base is re-resolved at restore time so the view follows base-branch
 * advancement instead of pinning the SHA that was current at save time.
 */
final readonly class SavedView
{
    private function __construct(
        public LastViewMode $mode,
        public ?LastViewKind $kind,
        public ?string $from,
        public ?string $to,
    ) {}

    public static function context(): self
    {
        return new self(LastViewMode::Context, null, null, null);
    }

    public static function workingTree(): self
    {
        return new self(LastViewMode::Review, LastViewKind::WorkingTree, null, null);
    }

    public static function sinceBase(): self
    {
        return new self(LastViewMode::Review, LastViewKind::SinceBase, null, null);
    }

    public static function commit(string $to): self
    {
        return new self(LastViewMode::Review, LastViewKind::Commit, null, self::ref($to, 'to'));
    }

    public static function range(string $from, string $to): self
    {
        return new self(LastViewMode::Review, LastViewKind::Range, self::ref($from, 'from'), self::ref($to, 'to'));
    }

    public static function rangeToWorking(string $from): self
    {
        return new self(LastViewMode::Review, LastViewKind::RangeToWorking, self::ref($from, 'from'), null);
    }

    /**
     * Interpret persisted columns. Any tuple that no factory could have
     * produced (a Commit without `to`, a Range missing an end, a blank ref)
     * degrades to the working tree, which is also what an empty tuple gives a
     * project with nothing saved yet.
     */
    public static function fromColumns(LastViewMode $mode, ?LastViewKind $kind, ?string $from, ?string $to): self
    {
        if ($mode === LastViewMode::Context) {
            return self::context();
        }

        $from = self::refOrNull($from);
        $to = self::refOrNull($to);

        return match (true) {
            $kind === LastViewKind::SinceBase => self::sinceBase(),
            $kind === LastViewKind::Commit && $to !== null => self::commit($to),
            $kind === LastViewKind::Range && $from !== null && $to !== null => self::range($from, $to),
            $kind === LastViewKind::RangeToWorking && $from !== null => self::rangeToWorking($from),
            default => self::workingTree(),
        };
    }

    /** @return array{last_view_mode: LastViewMode, last_view_kind: ?LastViewKind, last_view_from: ?string, last_view_to: ?string} */
    public function toArray(): array
    {
        return [
            'last_view_mode' => $this->mode,
            'last_view_kind' => $this->kind,
            'last_view_from' => $this->from,
            'last_view_to' => $this->to,
        ];
    }

    private static function ref(string $ref, string $field): string
    {
        return self::refOrNull($ref)
            ?? throw new InvalidArgumentException("The {$field} ref must not be blank.");
    }

    private static function refOrNull(?string $ref): ?string
    {
        $trimmed = trim((string) $ref);

        return $trimmed === '' ? null : $trimmed;
    }
}
