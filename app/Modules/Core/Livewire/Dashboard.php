<?php

namespace Modules\Core\Livewire;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

class Dashboard extends Component
{
    #[Layout('core::layouts.admin')]
    #[Title('Dashboard')]
    public function render()
    {
        return view('core::livewire.dashboard');
    }
}
