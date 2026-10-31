<?php

namespace App\Services;

use App\Models\BusinessSetting;
use App\Models\Income;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Transaction approval workflow.
 *
 * - Admin entries are approved immediately.
 * - Entry User entries are Pending when BusinessSettings.require_approval is on.
 * - Only Pending items can be approved or rejected, and only by an Admin.
 * - Editing a Rejected entry resubmits it (back to Pending).
 */
final class Workflow
{
    public static function initialStatus(User $user): string
    {
        return $user->isAdmin() || ! BusinessSetting::current()->require_approval ? 'APPROVED' : 'PENDING';
    }

    public static function stampNew(Model $obj, User $user): void
    {
        $obj->created_by = $user->id;
        $obj->status = self::initialStatus($user);
        if ($obj->status === 'APPROVED') {
            $obj->approved_by = $user->id;
            $obj->approved_at = now();
        }
    }

    public static function stampEdit(Model $obj, User $user): void
    {
        if (! $user->isAdmin() && $obj->status === 'REJECTED') {
            $obj->status = self::initialStatus($user);
            $obj->rejection_reason = null;
            if ($obj->status === 'APPROVED') {
                $obj->approved_by = $user->id;
                $obj->approved_at = now();
            }
        }
    }

    private static function requireAdmin(User $user): void
    {
        if (! $user->isAdmin()) {
            throw new AuthorizationException('Only administrators can approve or reject transactions.');
        }
    }

    private static function set(Model $obj, string $status, User $user, ?string $reason = null): void
    {
        $obj->status = $status;
        $obj->approved_by = $user->id;
        $obj->approved_at = now();
        $obj->rejection_reason = $reason;
        $obj->save();
    }

    /** @return array{0: Model, 1: array<Payment>} the approved record and payments approved with it */
    public static function approve(Model $obj, User $user): array
    {
        self::requireAdmin($user);

        return DB::transaction(function () use ($obj, $user) {
            $obj = $obj->newQuery()->lockForUpdate()->findOrFail($obj->getKey());
            if ($obj->status !== 'PENDING') {
                throw new WorkflowException("{$obj->number} is already ".strtolower($obj->statusLabel()).'.');
            }
            if ($obj instanceof Payment && $obj->income_id && $obj->income->status !== 'APPROVED') {
                throw new WorkflowException("Approve invoice {$obj->income->number} before approving this payment.");
            }
            self::set($obj, 'APPROVED', $user);
            Audit::log('APPROVE', $obj, $user);
            $cascaded = [];
            if ($obj instanceof Income) {
                // Money received on the spot was entered together with the invoice.
                foreach ($obj->payments()->where('status', 'PENDING')->where('is_initial', true)->get() as $pay) {
                    self::set($pay, 'APPROVED', $user);
                    Audit::log('APPROVE', $pay, $user, ['note' => "Approved with {$obj->number}"]);
                    $cascaded[] = $pay;
                }
            }

            return [$obj, $cascaded];
        });
    }

    /** @return array{0: Model, 1: array<Payment>} */
    public static function reject(Model $obj, User $user, ?string $reason): array
    {
        self::requireAdmin($user);
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new WorkflowException('Enter a reason so the person who entered it can correct it.');
        }

        return DB::transaction(function () use ($obj, $user, $reason) {
            $obj = $obj->newQuery()->lockForUpdate()->findOrFail($obj->getKey());
            if ($obj->status !== 'PENDING') {
                throw new WorkflowException("{$obj->number} is already ".strtolower($obj->statusLabel()).'.');
            }
            $cascaded = [];
            if ($obj instanceof Income) {
                if ($obj->payments()->where('status', 'APPROVED')->exists()) {
                    throw new WorkflowException('This invoice already has approved payments and cannot be rejected.');
                }
                foreach ($obj->payments()->where('status', 'PENDING')->get() as $pay) {
                    self::set($pay, 'REJECTED', $user, Str::limit("Invoice {$obj->number} rejected: {$reason}", 255, ''));
                    Audit::log('REJECT', $pay, $user, ['reason' => "Invoice {$obj->number} rejected"]);
                    $cascaded[] = $pay;
                }
            }
            self::set($obj, 'REJECTED', $user, Str::limit($reason, 255, ''));
            Audit::log('REJECT', $obj, $user, ['reason' => $reason]);

            return [$obj, $cascaded];
        });
    }
}
