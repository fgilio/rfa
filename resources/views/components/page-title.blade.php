{{-- Display-font, brutal-tracked title used as the project / page name
     in the top-left of every page header. The class set here is the
     identifier-first emphasis pattern from resources/CLAUDE.md
     ("Typography"); a 6-month-later reader changing the title scale or
     tracking should only have to touch this one file. --}}
<span {{ $attributes->class(['font-display font-bold tracking-brutal-tight text-base']) }}>
    {{ $slot }}
</span>
