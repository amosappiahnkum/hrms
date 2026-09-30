<?php

namespace App\Traits;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Audit trail for a model: every create, update, delete and restore is written to activity_log
 * with the changed attributes (old and new values) and the user who made the change.
 *
 * Secrets are never written. A model can exclude further attributes by declaring
 * `protected array $auditExclude = [...]`.
 */
trait RecordsActivity
{
    use LogsActivity;

    /** Attributes that must never appear in the audit log. */
    public static function auditSecrets(): array
    {
        return ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        $exclude = array_merge(
            static::auditSecrets(),
            property_exists($this, 'auditExclude') ? $this->auditExclude : [],
        );

        return LogOptions::defaults()
            ->logAll()
            ->logExcept($exclude)
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at', 'last_login_at'])
            ->dontSubmitEmptyLogs();
    }
}
