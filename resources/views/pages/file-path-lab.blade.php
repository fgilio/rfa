{{-- Visual lab for path-rendering treatments. Temporary — delete with the route in routes/web.php once a treatment is picked. --}}
<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component {};

?>

@php
    $samples = [
        'app/Domains/Metadata/Traits/HasTaxonomies.php',
        'app/Console/Commands/Pla/BackfillTermSlugs.php',
        'database/seeders/BookshopSeeder.php',
        '.claude/plans/i-want-us-to-mutable-lake.md',
        'tests/Feature/Console/Commands/Pla/BackfillTermSlugsTest.php',
        'Facebook provider_user_id issue.md',
        'README.md',
    ];

    $widths = [
        'narrow (~sidebar, 280px)' => 'w-[280px]',
        'medium (file list, 540px)' => 'w-[540px]',
        'wide (no constraint)' => 'w-full',
    ];

    $splitPath = static function (string $value): array {
        $pos = strrpos($value, '/');
        return $pos === false ? ['', $value] : [substr($value, 0, $pos + 1), substr($value, $pos + 1)];
    };

    /**
     * Each variant: name, blurb, dirClass (applied to directory span),
     * baseClass (applied to basename span), and an optional `single` flag
     * that controls whether the basename emphasis still applies on
     * single-token (no-slash) rows. Default: emphasis stays on.
     */
    $variants = [
        [
            'name'  => '0 — Current (control)',
            'blurb' => 'opacity-60 dir + font-semibold base. What\'s on main now.',
            'dir'   => 'opacity-60',
            'base'  => 'font-semibold',
        ],
        [
            'name'  => 'A — Color only, no weight',
            'blurb' => 'opacity-60 dir, basename inherits weight. GitHub PR tree pattern.',
            'dir'   => 'opacity-60',
            'base'  => '',
        ],
        [
            'name'  => 'B — Color + light weight',
            'blurb' => 'opacity-60 dir + font-medium base. Half-step from current.',
            'dir'   => 'opacity-60',
            'base'  => 'font-medium',
        ],
        [
            'name'  => 'A2 — More dim, no weight',
            'blurb' => 'opacity-50 dir, basename inherits weight.',
            'dir'   => 'opacity-50',
            'base'  => '',
        ],
        [
            'name'  => 'A3 — Most dim, no weight',
            'blurb' => 'opacity-40 dir, basename inherits weight. Strong color hierarchy.',
            'dir'   => 'opacity-40',
            'base'  => '',
        ],
        [
            'name'  => 'C — Real muted color, no opacity',
            'blurb' => 'text-gh-muted/70 dir, basename inherits color & weight. Composes with already-muted parents.',
            'dir'   => 'text-gh-muted/70',
            'base'  => '',
            'baseColor' => 'text-gh-text',
        ],
        [
            'name'  => 'C+B — Muted color + light weight',
            'blurb' => 'text-gh-muted/70 dir + font-medium base. My recommendation.',
            'dir'   => 'text-gh-muted/70',
            'base'  => 'font-medium',
            'baseColor' => 'text-gh-text',
        ],
        [
            'name'  => 'C+B (no-emphasis on single-token)',
            'blurb' => 'Same as C+B, but rows without a directory render at default weight (no false emphasis on bare names).',
            'dir'   => 'text-gh-muted/70',
            'base'  => 'font-medium',
            'baseColor' => 'text-gh-text',
            'single' => 'plain',
        ],
        [
            'name'  => 'D — Tracking trick (no weight)',
            'blurb' => 'Same weight, basename gets -tracking-tight (-0.025em). Subtle rhythm cue without ink.',
            'dir'   => 'opacity-60',
            'base'  => 'tracking-tight',
        ],
    ];
@endphp

<div>
    <div class="min-h-screen bg-gh-bg text-gh-text">
        <div class="max-w-[1400px] mx-auto px-8 py-10 space-y-12">

            <header class="space-y-3">
                <h1 class="font-display text-3xl tracking-brutal-tight">File path treatments</h1>
                <p class="text-sm text-gh-muted max-w-3xl">Each variant rendered at three widths. Same sample list every time so you can scan vertically (variant comparison) or horizontally (width sensitivity). Toggle dark mode from the header to see both themes.</p>
                <div class="flex gap-4 text-xs font-mono text-gh-muted">
                    <span><strong class="text-gh-text">font-mono</strong>: JetBrains Mono</span>
                    <span><strong class="text-gh-text">opacity-60</strong>: 60% of parent's color</span>
                    <span><strong class="text-gh-text">text-gh-muted/70</strong>: real muted color at 70% alpha</span>
                </div>
            </header>

            @foreach($variants as $variant)
                @php
                    $dirClass  = $variant['dir'];
                    $baseClass = $variant['base'];
                    $baseColor = $variant['baseColor'] ?? '';
                    $singleMode = $variant['single'] ?? 'emphasized';
                @endphp
                <section class="border-t border-gh-border pt-8 space-y-4">
                    <div>
                        <h2 class="font-display text-lg tracking-brutal">{{ $variant['name'] }}</h2>
                        <p class="text-xs text-gh-muted mt-1">{{ $variant['blurb'] }}</p>
                        <code class="text-[11px] font-mono text-gh-muted block mt-1">dir: <span class="text-gh-text">{{ $dirClass ?: '—' }}</span> &nbsp; base: <span class="text-gh-text">{{ trim($baseClass.' '.$baseColor) ?: '—' }}</span></code>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        @foreach($widths as $widthLabel => $widthClass)
                            <div>
                                <p class="text-[10px] uppercase tracking-wider text-gh-muted mb-2">{{ $widthLabel }}</p>
                                <div class="{{ $widthClass }} max-w-full bg-gh-surface/40 border border-gh-border rounded p-3 space-y-1.5">
                                    @foreach($samples as $sample)
                                        @php
                                            [$dir, $base] = $splitPath($sample);
                                            $isSingleToken = $dir === '';
                                            $applyBaseClass = $isSingleToken && $singleMode === 'plain' ? '' : $baseClass;
                                        @endphp
                                        <div class="font-mono text-xs inline-flex items-baseline min-w-0 max-w-full w-full" title="{{ $sample }}">
                                            <span class="min-w-0 truncate {{ $dirClass }}">{{ $dir }}</span><span class="shrink-0 {{ $applyBaseClass }} {{ $baseColor }}">{{ $base }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach

            <footer class="border-t border-gh-border pt-8 pb-16 text-xs text-gh-muted space-y-2">
                <p>Once you pick, tell me which variant (e.g. "go with C+B with single-token plain") and I'll wire it into <code class="font-mono text-gh-text">resources/views/components/file-path.blade.php</code> and the Alpine helpers, then delete this lab.</p>
            </footer>
        </div>
    </div>
</div>
