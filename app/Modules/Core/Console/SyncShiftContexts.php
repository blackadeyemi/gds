<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Modules\Core\Models\ShiftContext;

/**
 * Upserts shift_contexts from the code registry (config/shifts.php). Label and
 * module are refreshed each run; a context's admin-managed state (is_active and
 * its windows' times/enabled flags) is PRESERVED once the context exists — the
 * registry's default windows are only seeded when the context is first created.
 * Contexts no longer declared in code are removed (their windows cascade).
 */
class SyncShiftContexts extends Command
{
    protected $signature = 'gds:sync-shift-contexts';
    protected $description = 'Sync shift contexts from config/shifts.php into the DB (preserving admin edits)';

    public function handle(): int
    {
        $declared = config('shifts.contexts', []);
        $keys = [];

        foreach ($declared as $def) {
            $keys[] = $def['key'];
            $context = ShiftContext::firstOrNew(['key' => $def['key']]);
            $isNew = ! $context->exists;

            $context->label = $def['label'];
            $context->module = $def['module'] ?? null;
            $context->save();

            // Seed default windows only for brand-new contexts, so admin edits
            // to times/enabled on existing contexts are never clobbered.
            if ($isNew) {
                foreach (($def['windows'] ?? []) as $i => $w) {
                    $context->windows()->create([
                        'name' => $w['name'],
                        'start_time' => $w['start'],
                        'end_time' => $w['end'],
                        'is_enabled' => $w['enabled'] ?? true,
                        'sort_order' => $i,
                    ]);
                }
            }

            $this->line('  synced ' . ($isNew ? '<info>+</info>' : ' ') . " {$def['key']}");
        }

        $removed = ShiftContext::whereNotIn('key', $keys)->get();
        foreach ($removed as $ctx) {
            $ctx->windows()->delete();
            $ctx->delete();
            $this->line("  removed <comment>{$ctx->key}</comment>");
        }

        $this->info('Shift contexts synced (' . count($keys) . ' declared).');
        return self::SUCCESS;
    }
}
