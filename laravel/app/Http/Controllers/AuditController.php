<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Reports\ReportTable;
use App\Support\Period;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    private function query(Request $request): Builder
    {
        $q = AuditLog::query();
        $this->search($q, $request, ['object_repr', 'username', 'model_name', 'object_id']);
        if (isset(AuditLog::ACTIONS[(string) $request->query('action')])) {
            $q->where('action', $request->query('action'));
        }
        if ($request->filled('model')) {
            $q->where('model_name', (string) $request->query('model'));
        }
        if ($user = $this->idParam($request, 'user')) {
            $q->where('user_id', $user);
        }
        if ($from = Period::parseDate($request->query('from'))) {
            $q->where('timestamp', '>=', $from->startOfDay());
        }
        if ($to = Period::parseDate($request->query('to'))) {
            $q->where('timestamp', '<=', $to->endOfDay());
        }

        return $q->orderByDesc('timestamp')->orderByDesc('id');
    }

    public function index(Request $request)
    {
        $export = $this->export($request, fn () => new ReportTable(
            title: 'Audit Log',
            columns: [['Time', 'timestamp', 'datetime'], ['User', 'user', 'text'], ['Action', 'action', 'text'],
                ['Record', 'record', 'text'], ['Changed fields', 'changes', 'text'], ['IP', 'ip', 'text']],
            rows: $this->query($request)->limit(5000)->get()->map(fn (AuditLog $log) => [
                'timestamp' => $log->timestamp, 'user' => $log->username, 'action' => $log->actionLabel(),
                'record' => trim("{$log->model_name} {$log->object_repr}"), 'ip' => $log->ip_address ?? '',
                'changes' => implode(', ', array_keys($log->changes['fields'] ?? [])),
            ])->all(),
        ));
        if ($export) {
            return $export;
        }

        return view('audit.index', [
            'logs' => $this->query($request)->paginate($this->perPage())->withQueryString(),
            'models' => AuditLog::query()->whereNotNull('model_name')->where('model_name', '!=', '')
                ->distinct()->orderBy('model_name')->pluck('model_name'),
            'users' => User::orderBy('username')->get(),
        ]);
    }

    public function show(AuditLog $log)
    {
        return view('audit.show', ['log' => $log]);
    }
}
