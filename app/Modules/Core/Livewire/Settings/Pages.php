<?php

namespace Modules\Core\Livewire\Settings;

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Models\Page;
use Modules\Core\Support\PageSyncer;

/**
 * Admin view of the page registry: every gated page with its abilities as
 * checkboxes. New pages (declared in config/pages.php) are discovered on load.
 * Saving updates each page's abilities and regenerates the "{key}:{ability}"
 * permissions the Role matrix grants. Gated by settings.pages access.
 */
#[Layout('core::layouts.admin')]
#[Title('Pages')]
class Pages extends Component
{
    /** @var array<int,array<int,string>> pageId => enabled abilities */
    public array $abilities = [];

    public function mount(PageSyncer $syncer): void
    {
        // New code-declared pages appear automatically.
        $added = $syncer->discoverNew();
        if ($added > 0) {
            session()->flash('ok', "Discovered {$added} new page(s) from code.");
        }
        $this->load();
    }

    protected function load(): void
    {
        $this->abilities = [];
        foreach (Page::all() as $page) {
            $this->abilities[$page->id] = array_values($page->abilities ?? []);
        }
    }

    public function resetPage(int $id, PageSyncer $syncer): void
    {
        $page = Page::find($id);
        if ($page) {
            $this->abilities[$page->id] = $syncer->defaultAbilities($page->key);
        }
    }

    public function save(PageSyncer $syncer): void
    {
        $columns = array_keys(config('pages.abilities', []));

        foreach (Page::all() as $page) {
            $chosen = array_values(array_intersect($columns, $this->abilities[$page->id] ?? []));
            // A page must always be viewable to be reachable at all.
            if (! in_array('view', $chosen, true)) {
                array_unshift($chosen, 'view');
            }
            $page->update(['abilities' => $chosen]);
        }

        $syncer->regeneratePermissions();
        $this->load();

        session()->flash('ok', 'Page abilities saved.');
    }

    public function render()
    {
        return view('core::livewire.settings.pages', [
            'columns' => config('pages.abilities', []),
            'groups' => Page::orderBy('sort_order')->get()->groupBy(fn ($p) => $p->module ?? 'Other'),
        ]);
    }
}
