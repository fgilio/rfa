<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a stored comment still maps to a real position in the file
 * the user is currently looking at.
 *
 * Both anchor resolvers (review-side and context-side) produce one of
 * these states. Renderers and exporters branch on it to decide whether
 * to treat the anchor as authoritative ('placed') or to render the
 * comment with a "this anchor moved or vanished" treatment ('unplaced').
 */
enum AnchorStatus: string
{
    case Placed = 'placed';
    case Unplaced = 'unplaced';
}
