<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Support\Activity\ActivityText;

/**
 * One record an activity changed. `changes` holds, by op:
 *
 *   created  {attributes}             what was entered
 *   updated  {before, after}          the changed fields only
 *   deleted  {attributes}             the row as it was, so a delete can be read
 *
 * Rows written through the query builder rather than a model (`source` =
 * query) carry what the statement said instead: {after, where} for an update,
 * {where} for a delete — the engine does not hand back the old values.
 */
class ActivityItem extends Model
{
    protected $connection = 'core';
    protected $table = 'activity_items';
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = ['changes' => 'array'];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function subjectLabel(): string
    {
        return ActivityText::subject($this->subject_type);
    }

    /**
     * Field-by-field rows for display: [field label, before, after].
     *
     * @return list<array{0:string,1:?string,2:?string}>
     */
    public function rows(): array
    {
        // getAttribute(), not ->changes: inside the model that is Eloquent's own
        // protected $changes property (the dirty set), not this column.
        $c = (array) $this->getAttribute('changes');
        $out = [];

        if (isset($c['before']) || isset($c['after'])) {
            $before = (array) ($c['before'] ?? []);
            foreach ((array) ($c['after'] ?? []) as $field => $value) {
                $out[] = [ActivityText::field($field),
                    array_key_exists($field, $before) ? ActivityText::value($before[$field]) : null,
                    ActivityText::value($value)];
            }
        } elseif (isset($c['attributes'])) {
            foreach ((array) $c['attributes'] as $field => $value) {
                $out[] = $this->op === 'deleted'
                    ? [ActivityText::field($field), ActivityText::value($value), null]
                    : [ActivityText::field($field), null, ActivityText::value($value)];
            }
        }

        return $out;
    }

    /** The WHERE of a query-builder update/delete, as "field = value" text. */
    public function whereText(): ?string
    {
        $where = (array) (((array) $this->getAttribute('changes'))['where'] ?? []);
        if ($where === []) {
            return null;
        }

        return collect($where)
            ->map(fn ($v, $k) => ActivityText::field($k) . ' = ' . ActivityText::value($v))
            ->implode(', ');
    }
}
