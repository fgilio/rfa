# Frontend Conventions

## Components
- Always use Flux UI components over raw Tailwind/Alpine when available
- Check fluxui.dev docs for component API before building custom markup

## Colors
- Use `gh-*` color tokens from CSS variables, never hardcode hex colors
- RGB-based tokens (bg, surface, border, text, muted, accent, green, red) support Tailwind opacity modifiers: `bg-gh-bg/50`
- Raw tokens (add-bg, del-bg, hunk-bg, etc.) are used directly: `bg-gh-add-bg`
- Theme colors defined in `config/theme.php`

## Dark Mode
- Managed by Flux's `@fluxAppearance` + `$flux.dark` - never hardcode `class="dark"` on `<html>`
- Toggle: `$flux.dark = ! $flux.dark`
- System preference detection is automatic via Flux
