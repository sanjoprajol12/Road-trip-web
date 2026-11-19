<?php

namespace App\Models\Concerns;

use App\Models\Source;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\DB;

trait HasSources
{
    public function sources(): MorphToMany
    {
        return $this->morphToMany(Source::class, 'sourceable')->withPivot('position')->orderByPivot('position');
    }

    /**
     * Replaces this record's citations with $items (as validated by Source::rules()). Items with an id update a source
     * already attached here; others are created. Sources left with no record are deleted. Call inside a transaction.
     */
    public function syncSources(array $items): void
    {
        $old = $this->sources->map->toPublic()->all();
        $attached = $this->sources->keyBy('id');
        $keep = [];
        foreach (array_values($items) as $i => $item) {
            $fields = array_intersect_key($item, array_flip(['title', 'publisher', 'url', 'accessed_on']));
            $source = isset($item['id']) && $attached->has($item['id']) ? tap($attached[$item['id']])->update($fields) : Source::create($fields + ['created_by_staff_id' => auth('staff')->id()]);
            $keep[$source->id] = ['position' => $i];
        }
        $this->sources()->sync($keep);
        Source::whereKey($attached->keys()->diff(array_keys($keep)))->whereNotIn('id', DB::table('sourceables')->select('source_id'))->delete();
        $this->unsetRelation('sources');
        $new = $this->sources->map->toPublic()->all();
        if ($old !== $new) {
            Audit::record($this->getTable().'.sources_changed', $this, ['sources' => $old], ['sources' => $new]);
        }
    }
}
