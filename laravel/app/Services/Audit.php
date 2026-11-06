<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/** Writes the immutable audit trail. */
final class Audit
{
    private const EXCLUDED = ['password', 'remember_token', 'updated_at', 'last_login_at', 'attendance_token'];

    private const RELATIONS = ['created_by' => 'creator', 'approved_by' => 'approver'];

    /** Readable array of a model's fields (foreign keys shown by name). */
    public static function snapshot(Model $model): array
    {
        $data = [];
        foreach (array_keys($model->getAttributes()) as $key) {
            if (in_array($key, self::EXCLUDED, true) || in_array($key, ['billed', 'received'], true)) {
                continue;
            }
            $relation = self::RELATIONS[$key] ?? (str_ends_with($key, '_id') ? Str::camel(substr($key, 0, -3)) : null);
            if ($relation && method_exists($model, $relation)) {
                $related = $model->getAttribute($key) ? $model->{$relation}()->first() : null;
                $name = str_ends_with($key, '_id') ? substr($key, 0, -3) : $key;
                $data[$name] = $related ? (string) $related : null;
                continue;
            }
            $data[$key] = self::serialise($model, $key);
        }

        return $data;
    }

    private static function serialise(Model $model, string $key): mixed
    {
        $value = $model->getAttribute($key);
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof CarbonInterface) {
            $cast = $model->getCasts()[$key] ?? '';

            return $cast === 'date' ? $value->toDateString() : $value->format('Y-m-d H:i:s');
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_array($value)) {
            return $value;
        }

        return (string) $value;
    }

    /** @return array<string, array{0:mixed,1:mixed}> */
    public static function diff(array $before, array $after): array
    {
        $out = [];
        $keys = array_unique([...array_keys($before), ...array_keys($after)]);
        sort($keys);
        foreach ($keys as $key) {
            $a = $before[$key] ?? null;
            $b = $after[$key] ?? null;
            if ($a !== $b) {
                $out[$key] = [$a, $b];
            }
        }

        return $out;
    }

    public static function log(
        string $action,
        ?Model $model = null,
        ?User $user = null,
        array $changes = [],
        ?string $objectRepr = null,
        ?string $modelName = null,
        ?string $username = null,
    ): AuditLog {
        $user ??= Auth::user();
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        return AuditLog::create([
            'user_id' => $user?->id,
            'username' => Str::limit($username ?? $user?->username ?? '', 150, ''),
            'action' => $action,
            'model_name' => $modelName ?? ($model ? self::modelName($model) : ''),
            'object_id' => $model?->getKey() ? (string) $model->getKey() : '',
            'object_repr' => Str::limit($objectRepr ?? ($model ? (string) $model : ''), 255, ''),
            'changes' => $changes,
            'ip_address' => $request?->ip(),
        ]);
    }

    public static function modelName(Model $model): string
    {
        return defined($model::class.'::AUDIT_NAME') ? $model::AUDIT_NAME : class_basename($model);
    }

    public static function created(Model $model): AuditLog
    {
        return self::log('CREATE', $model, changes: ['snapshot' => self::snapshot($model)]);
    }

    public static function updated(Model $model, array $before): ?AuditLog
    {
        $changes = self::diff($before, self::snapshot($model->fresh() ?? $model));

        return $changes ? self::log('UPDATE', $model, changes: ['fields' => $changes]) : null;
    }

    public static function deleted(Model $model): AuditLog
    {
        return self::log('DELETE', $model, changes: ['snapshot' => self::snapshot($model)]);
    }

    /** Recent audit entries for one record (change history panel). */
    public static function history(Model $model, int $limit = 20)
    {
        return AuditLog::where('model_name', self::modelName($model))
            ->where('object_id', (string) $model->getKey())
            ->orderByDesc('timestamp')->orderByDesc('id')->limit($limit)->get();
    }
}
