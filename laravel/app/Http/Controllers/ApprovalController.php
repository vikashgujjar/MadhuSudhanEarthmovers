<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Income;
use App\Models\Payment;
use App\Services\Workflow;
use App\Services\WorkflowException;
use Illuminate\Http\Request;

class ApprovalController extends Controller
{
    private const KINDS = ['income' => Income::class, 'expense' => Expense::class, 'payment' => Payment::class];

    public function index()
    {
        return view('finance.approvals', [
            'incomes' => Income::pending()->with(['customer', 'vehicle', 'creator'])->orderBy('date')->orderBy('id')->get(),
            'expenses' => Expense::pending()->with(['vehicle', 'driver', 'creator'])->orderBy('date')->orderBy('id')->get(),
            'payments' => Payment::pending()->with(['customer', 'income', 'creator'])->orderBy('date')->orderBy('id')->get(),
        ]);
    }

    public function approve(Request $request, string $kind, int $id)
    {
        $obj = (self::KINDS[$kind])::findOrFail($id);
        try {
            [$obj, $cascaded] = Workflow::approve($obj, $request->user());
            $n = count($cascaded);
            $extra = $n ? " (with {$n} linked payment".plural($n).')' : '';
            session()->flash('success', "{$obj->number} approved{$extra}.");
        } catch (WorkflowException $e) {
            session()->flash('error', $e->getMessage());
        }

        return redirect($this->safeNext($request, route('approvals.index')));
    }

    public function reject(Request $request, string $kind, int $id)
    {
        $obj = (self::KINDS[$kind])::findOrFail($id);
        try {
            [$obj] = Workflow::reject($obj, $request->user(), $request->input('reason'));
            session()->flash('success', "{$obj->number} rejected.");
        } catch (WorkflowException $e) {
            session()->flash('error', $e->getMessage());
        }

        return redirect($this->safeNext($request, route('approvals.index')));
    }

    public function bulk(Request $request)
    {
        $done = 0;
        $failed = [];
        // Invoices first so that their payments can be approved in the same batch.
        foreach (self::KINDS as $kind => $model) {
            foreach ((array) $request->input($kind, []) as $id) {
                if (! is_string($id) || ! ctype_digit($id) || ! ($obj = $model::find((int) $id))) {
                    continue;
                }
                try {
                    [, $cascaded] = Workflow::approve($obj, $request->user());
                    $done += 1 + count($cascaded);
                } catch (WorkflowException $e) {
                    $failed[] = "{$obj->number}: {$e->getMessage()}";
                }
            }
        }
        $redirect = redirect()->route('approvals.index');
        if ($done) {
            $redirect->with('success', "{$done} transaction".plural($done).' approved.');
        }
        if ($failed) {
            $redirect->with('warning', implode(' ', $failed));
        }
        if (! $done && ! $failed) {
            $redirect->with('info', 'Select at least one transaction to approve.');
        }

        return $redirect;
    }
}
