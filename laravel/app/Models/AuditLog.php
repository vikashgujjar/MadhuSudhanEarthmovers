<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only record of important actions. Rows cannot be edited or deleted. */
class AuditLog extends Model
{
    public const ACTIONS = [
        'CREATE' => 'Created', 'UPDATE' => 'Updated', 'DELETE' => 'Deleted', 'APPROVE' => 'Approved',
        'REJECT' => 'Rejected', 'LOGIN' => 'Logged in', 'LOGOUT' => 'Logged out', 'LOGIN_FAILED' => 'Failed login',
        'EXPORT' => 'Exported', 'SETTINGS' => 'Settings changed',
    ];

    public $timestamps = false;

    protected $fillable = ['user_id', 'username', 'action', 'model_name', 'object_id', 'object_repr', 'changes', 'ip_address'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'timestamp' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (AuditLog $log) {
            $log->timestamp ??= now();
        });
        static::updating(fn () => throw new \LogicException('Audit log entries are immutable.'));
        static::deleting(fn () => throw new \LogicException('Audit log entries cannot be deleted.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? (string) $this->action;
    }

    public function changeSummary(): string
    {
        $c = $this->changes ?? [];
        if (! empty($c['fields'])) {
            $n = count($c['fields']);

            return $n.' field'.($n === 1 ? '' : 's').' changed';
        }
        if (! empty($c['note'])) {
            return (string) $c['note'];
        }
        if (! empty($c['reason'])) {
            return 'Reason: '.$c['reason'];
        }

        return '';
    }
}
