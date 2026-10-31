from datetime import date, datetime
from decimal import Decimal

from django.db.models.fields.files import FieldFile

from .middleware import get_current_request
from .models import AuditLog

EXCLUDED_FIELDS = {"password", "updated_at", "last_login"}


def _serialise(value):
    if value is None:
        return None
    if isinstance(value, Decimal):
        return f"{value:.2f}"
    if isinstance(value, (date, datetime)):
        return value.isoformat()
    if isinstance(value, FieldFile):
        return value.name or None
    if hasattr(value, "pk"):
        return str(value)
    return value if isinstance(value, (int, float, bool, str)) else str(value)


def snapshot(instance):
    """Readable dict of concrete field values (foreign keys shown by name)."""
    data = {}
    for field in instance._meta.concrete_fields:
        if field.name in EXCLUDED_FIELDS:
            continue
        if field.is_relation:
            value = getattr(instance, field.name, None)
        else:
            value = field.value_from_object(instance)
        data[field.name] = _serialise(value)
    return data


def diff(before, after):
    return {
        key: [before.get(key), after.get(key)]
        for key in sorted(set(before) | set(after))
        if before.get(key) != after.get(key)
    }


def client_ip(request):
    if request is None:
        return None
    forwarded = request.META.get("HTTP_X_FORWARDED_FOR")
    ip = forwarded.split(",")[0].strip() if forwarded else request.META.get("REMOTE_ADDR")
    return ip or None


def log(action, instance=None, user=None, changes=None, object_repr=None, model_name=None, username=None):
    request = get_current_request()
    if user is None and request is not None and getattr(request, "user", None) and request.user.is_authenticated:
        user = request.user
    return AuditLog.objects.create(
        user=user if user and getattr(user, "pk", None) else None,
        username=(username or getattr(user, "username", "") or "")[:150],
        action=action,
        model_name=model_name or (instance._meta.verbose_name.title() if instance is not None else ""),
        object_id=str(instance.pk) if instance is not None and instance.pk else "",
        object_repr=(object_repr or (str(instance) if instance is not None else ""))[:255],
        changes=changes or {},
        ip_address=client_ip(request),
    )


def log_create(instance, user=None):
    return log(AuditLog.Action.CREATE, instance, user, {"snapshot": snapshot(instance)})


def log_update(instance, before, user=None):
    changes = diff(before, snapshot(instance))
    if changes:
        return log(AuditLog.Action.UPDATE, instance, user, {"fields": changes})
    return None


def log_delete(instance, user=None):
    return log(AuditLog.Action.DELETE, instance, user, {"snapshot": snapshot(instance)})
