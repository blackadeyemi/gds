<?php

/*
| BHN (Abuja) module routes.
|
| BHN reuses the BIL screens wholesale — the same Livewire components — served
| under the /bhn prefix (ModuleServiceProvider wraps this file in
| prefix('bhn')->name('bhn.')). So rather than duplicate the BIL route table,
| we require it: every BIL route is registered a second time under the /bhn
| prefix with bhn.* route names.
|
| The route definitions carry literal `page:bil.*` keys; ResolveCompanyModule
| sets the module to 'bhn' for these requests and ModuleContext::normalizeKey
| (in User::canDo) rewrites those keys to `bhn.*`, so access control and the
| rebound `bil`->`bhn` connection are all per-company. config/pages.php mirrors
| every BIL page as a BHN page so the permissions exist.
*/

require __DIR__.'/bil.php';
