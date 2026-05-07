<?php
namespace App\Services;

use Illuminate\Database\Eloquent\Model;

class InfoDifferenceService
{
    protected array $relationCache = [];

    public function buildDiff(Model $model, array $old, array $new): array
    {
        // Get metadata configuration from model
        $fieldConfig = method_exists($model, 'approvableFields') ? $model->approvableFields() : [];
        $relations   = method_exists($model, 'approvableRelations') ? $model->approvableRelations() : [];

        // 1. Filter fields based on metadata 'show_in_diff'
        // If 'show_in_diff' isn't set, we'll default to true to be safe.
        $allowedFields = array_keys(array_filter($fieldConfig, function ($meta) {
            return $meta['show_in_diff'] ?? true;
        }));

        // 2. Identify which of those allowed fields actually exist in our data sets
        $allKeys = array_unique(array_merge(array_keys($old), array_keys($new)));
        $fields  = array_intersect($allKeys, $allowedFields);

        // 3. Pre-fetch relation values (N+1 protection)
        // We only prime cache for fields that survived the 'show_in_diff' filter
        $this->primeRelationCache($relations, $fields, $old, $new);

        $diff = [];

        foreach ($fields as $field) {
            $oldVal = $old[$field] ?? null;
            $newVal = $new[$field] ?? null;

            // Resolve values if field is a relation
            if (isset($relations[$field])) {
                $relatedModel = $relations[$field];
                $oldVal = $this->getCachedValue($relatedModel, $oldVal);
                $newVal = $this->getCachedValue($relatedModel, $newVal);
            }

            // Perform comparison
            if ($oldVal != $newVal) {
                $diff[] = [
                    'field' => $field,
                    'old'   => $oldVal,
                    'new'   => $newVal,
                ];
            }
        }

        return $diff;
    }

    /**
     * Bulk fetch relation names.
     * Added $fields param to ensure we only query what we intend to display.
     */
    protected function primeRelationCache(array $relations, array $fields, array $old, array $new): void
    {
        foreach ($relations as $field => $modelClass) {
            // Only process if this relation field is actually in our filtered list
            if (!in_array($field, $fields)) {
                continue;
            }

            $ids = array_filter(array_unique([$old[$field] ?? null, $new[$field] ?? null]));
            $missingIds = array_filter($ids, fn($id) => !isset($this->relationCache["$modelClass:$id"]));

            if (!empty($missingIds)) {
                // Fetching only id and name for efficiency
                $results = $modelClass::whereIn('id', $missingIds)->get(['id', 'name']);
                foreach ($results as $result) {
                    $this->relationCache["$modelClass:$result->id"] = $result->name;
                }
            }
        }
    }

    protected function getCachedValue(string $modelClass, $id): ?string
    {
        if (empty($id)) return null;
        return $this->relationCache["$modelClass:$id"] ?? (string) $id;
    }
}
