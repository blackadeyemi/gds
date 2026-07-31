<?php

namespace Modules\Core\Livewire\Settings;

use Illuminate\Support\Carbon;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Modules\Core\Support\Prefs;

/**
 * Appearance settings. Theme (light/dark/system) and text size (small/
 * medium/large) are applied client-side and persisted in localStorage
 * (gds_theme / gds_font); the date format rides in the gds_date_format
 * cookie so it also reaches server-rendered reports/exports. All controls
 * here are wired by settings.js.
 */
#[Layout('core::layouts.admin')]
#[Title('Appearance')]
class Appearance extends Component
{
    public function render()
    {
        // A day/month-distinct sample so each option reads unambiguously.
        $sample = Carbon::create(2026, 7, 3);

        return view('core::livewire.settings.appearance', [
            'dateFormats' => collect(Prefs::FORMATS)
                ->map(fn ($f) => ['value' => $f, 'example' => $sample->format($f)])
                ->all(),
            'defaultDateFormat' => Prefs::DEFAULT,
        ]);
    }
}
