<?php

/**
 * General code quality rules:
 * - No debug functions left in code
 * - Use strict types across all app code
 * - Laravel preset conventions
 * - No suspicious characters in source files
 */

arch('no debug functions in app code')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();

arch('app code uses strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('app code has no suspicious characters')
    ->expect('App')
    ->not->toHaveSuspiciousCharacters();

arch()->preset()->php();

arch()->preset()->security();
