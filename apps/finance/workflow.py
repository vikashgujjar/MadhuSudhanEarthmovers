"""Transaction approval workflow.

* Admin entries are approved immediately.
* Entry User entries are Pending when BusinessSettings.require_approval is on.
* Only Pending items can be approved or rejected, and only by an Admin.
* Editing a Rejected entry resubmits it (back to Pending).
"""
from django.core.exceptions import PermissionDenied, ValidationError
from django.db import transaction
from django.utils import timezone

from apps.audit import services as audit
from apps.audit.models import AuditLog
from apps.core.models import BusinessSettings

from .models import Income, Payment, Status


def initial_status(user):
    if user.is_admin or not BusinessSettings.load().require_approval:
        return Status.APPROVED
    return Status.PENDING


def stamp_new(obj, user):
    obj.created_by = user
    obj.status = initial_status(user)
    if obj.status == Status.APPROVED:
        obj.approved_by = user
        obj.approved_at = timezone.now()


def stamp_edit(obj, user):
    if not user.is_admin and obj.status == Status.REJECTED:
        obj.status = initial_status(user)
        obj.rejection_reason = ""
        if obj.status == Status.APPROVED:
            obj.approved_by = user
            obj.approved_at = timezone.now()


def _require_admin(user):
    if not user.is_admin:
        raise PermissionDenied("Only administrators can approve or reject transactions.")


def _set(obj, status, user, reason=""):
    obj.status = status
    obj.approved_by = user
    obj.approved_at = timezone.now()
    obj.rejection_reason = reason
    obj.save(update_fields=["status", "approved_by", "approved_at", "rejection_reason", "updated_at"])


@transaction.atomic
def approve(obj, user):
    _require_admin(user)
    obj = type(obj).objects.select_for_update().get(pk=obj.pk)
    if obj.status != Status.PENDING:
        raise ValidationError(f"{obj.number} is already {obj.get_status_display().lower()}.")
    if isinstance(obj, Payment) and obj.income_id and obj.income.status != Status.APPROVED:
        raise ValidationError(f"Approve invoice {obj.income.number} before approving this payment.")
    _set(obj, Status.APPROVED, user)
    audit.log(AuditLog.Action.APPROVE, obj, user)
    cascaded = []
    if isinstance(obj, Income):
        # Money received on the spot was entered together with the invoice.
        for pay in obj.payments.filter(status=Status.PENDING, is_initial=True):
            _set(pay, Status.APPROVED, user)
            audit.log(AuditLog.Action.APPROVE, pay, user, changes={"note": f"Approved with {obj.number}"})
            cascaded.append(pay)
    return obj, cascaded


@transaction.atomic
def reject(obj, user, reason):
    _require_admin(user)
    reason = (reason or "").strip()
    if not reason:
        raise ValidationError("Enter a reason so the person who entered it can correct it.")
    obj = type(obj).objects.select_for_update().get(pk=obj.pk)
    if obj.status != Status.PENDING:
        raise ValidationError(f"{obj.number} is already {obj.get_status_display().lower()}.")
    cascaded = []
    if isinstance(obj, Income):
        if obj.payments.filter(status=Status.APPROVED).exists():
            raise ValidationError("This invoice already has approved payments and cannot be rejected.")
        for pay in obj.payments.filter(status=Status.PENDING):
            _set(pay, Status.REJECTED, user, f"Invoice {obj.number} rejected: {reason}"[:255])
            audit.log(AuditLog.Action.REJECT, pay, user, changes={"reason": f"Invoice {obj.number} rejected"})
            cascaded.append(pay)
    _set(obj, Status.REJECTED, user, reason[:255])
    audit.log(AuditLog.Action.REJECT, obj, user, changes={"reason": reason})
    return obj, cascaded
