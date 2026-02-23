# RFA Project Conventions

## PHP / Laravel

- Use Laravel's `File` facade over raw `file_exists()`, `file_get_contents()`, etc.
- Use `Storage` facade for user-generated or external file operations

## Architecture

### Layers
- **Actions** (`app/Actions/`) - Business logic. Each action is a single use-case callable from any interface (Livewire, CLI, API). Convention: `final readonly class`, `handle()` method, `Action` suffix.
- **Services** (`app/Services/`) - Domain operations. Stateless classes that handle specific technical concerns (git ops, diff parsing, markdown formatting, file ignoring). Injected into Actions.
- **DTOs** (`app/DTOs/`) - Immutable data containers with `readonly` properties and `toArray()` serialization. Carry data between layers.
- **Livewire SFCs** (`resources/views/pages/`, `resources/views/livewire/`) - Thin UI adapters as single-file components. Handle events, manage component state, delegate to Actions. No business logic. Pages use `pages::` namespace; non-page components use default namespace.
- **Models** (`app/Models/`) - Eloquent persistence. Minimal - no business logic.

### Adding New Features
1. Create an Action in `app/Actions/` with `final readonly class` + `handle()` method
2. If it needs new domain logic, add a Service or extend an existing one
3. Wire the Action into whichever interface needs it (Livewire component, Artisan command, API controller)
4. Add unit test in `tests/Unit/Actions/`

### Key Patterns
- Actions that need DB state (e.g. `RestoreSessionAction`, `SaveSessionAction`) read/write internally. Stateless actions receive data via parameters for reuse across interfaces.
- Actions use constructor injection for service dependencies
- Caching is the caller's responsibility, not the Action's. Use `DiffCacheKey::for()` for diff cache keys.
- DTOs provide `toArray()` for serialization
