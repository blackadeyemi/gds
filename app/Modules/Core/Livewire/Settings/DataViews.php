<?php

namespace Modules\Core\Livewire\Settings;

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Modules\Core\Models\DataPage;
use Modules\Core\Models\DataView;

/**
 * Admin control for the DataGrid framework: per page, checklist which
 * code-declared views are available, choose the default view, and set the
 * default page size. Grids read this on load.
 */
#[Layout('core::layouts.admin')]
#[Title('Data Views')]
class DataViews extends Component
{
    /** @var array<int,int> pageId => per_page */
    public array $perPage = [];
    /** @var array<int,bool> viewId => enabled */
    public array $enabled = [];
    /** @var array<int,int> pageId => default viewId */
    public array $defaultView = [];

    public function mount(): void
    {
        foreach (DataPage::with('views')->get() as $page) {
            $this->perPage[$page->id] = $page->per_page;
            $default = $page->views->firstWhere('is_default', true);
            $this->defaultView[$page->id] = $default?->id ?? $page->views->first()?->id;
            foreach ($page->views as $view) {
                $this->enabled[$view->id] = $view->is_enabled;
            }
        }
    }

    public function save(): void
    {
        foreach (DataPage::with('views')->get() as $page) {
            $page->update(['per_page' => max(1, (int) ($this->perPage[$page->id] ?? 10))]);

            $defaultId = $this->defaultView[$page->id] ?? null;
            // Default must be an enabled view; else fall back to first enabled.
            $enabledIds = $page->views->filter(fn ($v) => $this->enabled[$v->id] ?? false)->pluck('id');
            if (! $enabledIds->contains($defaultId)) {
                $defaultId = $enabledIds->first();
            }

            foreach ($page->views as $view) {
                DataView::whereKey($view->id)->update([
                    'is_enabled' => (bool) ($this->enabled[$view->id] ?? false),
                    'is_default' => $view->id === $defaultId,
                ]);
            }
        }

        session()->flash('ok', 'Data view settings saved.');
    }

    public function render()
    {
        return view('core::livewire.settings.data-views', [
            'pages' => DataPage::with('views')->orderBy('label')->get(),
        ]);
    }
}
