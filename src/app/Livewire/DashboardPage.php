<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Actions\ListProjectsAction;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class DashboardPage extends Component
{
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $projectGroups = [];

    public function mount(): void
    {
        $this->projectGroups = app(ListProjectsAction::class)->handle();
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.dashboard-page');
    }
}
