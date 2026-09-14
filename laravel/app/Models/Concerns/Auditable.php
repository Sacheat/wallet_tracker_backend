<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Records a basic "who changed what, when" trail for the model it's applied
 * to. Eloquent auto-calls boot{TraitName}() for every trait a model uses.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            $model->writeAuditLog('created', $model->getAttributes());
        });

        static::updated(function (Model $model) {
            $model->writeAuditLog('updated', $model->getChanges());
        });

        static::deleted(function (Model $model) {
            $model->writeAuditLog('deleted', $model->getOriginal());
        });
    }

    protected function writeAuditLog(string $action, array $attributes): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'auditable_type' => static::class,
            'auditable_id' => $this->getKey(),
            'action' => $action,
            'changes' => collect($attributes)->except(['created_at', 'updated_at'])->all(),
        ]);
    }
}
