<?php

namespace Modules\Core\Livewire\Settings;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

/**
 * Appearance settings. Theme (light/dark/system) and text size (small/
 * medium/large) are applied client-side and persisted in localStorage
 * (gds_theme / gds_font); the controls here are wired by settings.js.
 */
#[Layout('core::layouts.admin')]
#[Title('Appearance')]
class Appearance extends Component
{
    public function render()
    {
        return view('core::livewire.settings.appearance');
    }
}
