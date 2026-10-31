<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Uploaded files, served with access checks. The logo is public; expense
 * bills are visible only to users who can see that expense.
 */
class MediaController extends Controller
{
    public function show(Request $request, string $path)
    {
        if (str_contains($path, '..') || str_contains($path, '\\')) {
            abort(404);
        }
        $disk = Storage::disk('local');

        if (str_starts_with($path, 'branding/')) {
            abort_unless($path === $this->business()->logo && $disk->exists($path), 404);

            return $disk->response($path, null, ['Cache-Control' => 'public, max-age=86400']);
        }

        if (! $request->user()) {
            return redirect()->guest(route('login'));
        }
        if (str_starts_with($path, 'expense_bills/')) {
            $expense = Expense::where('attachment', $path)->first();
            abort_unless($expense && $disk->exists($path), 404);
            abort_unless($expense->isVisibleTo($request->user()), 403, 'You cannot view this attachment.');

            return $disk->response($path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        }
        abort(404);
    }
}
