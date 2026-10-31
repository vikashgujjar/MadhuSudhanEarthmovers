from decimal import Decimal, InvalidOperation
from urllib.parse import urlencode

from django import template

register = template.Library()


def format_inr(value, decimals=2):
    """Indian digit grouping: 12,34,567.00"""
    try:
        number = Decimal(value or 0)
    except (InvalidOperation, TypeError, ValueError):
        return value
    negative = number < 0
    text = f"{abs(number):.{decimals}f}"
    whole, _, fraction = text.partition(".")
    if len(whole) > 3:
        head, tail = whole[:-3], whole[-3:]
        groups = []
        while len(head) > 2:
            groups.insert(0, head[-2:])
            head = head[:-2]
        if head:
            groups.insert(0, head)
        whole = ",".join(groups + [tail])
    result = f"{whole}.{fraction}" if fraction else whole
    return f"-{result}" if negative else result


@register.filter
def inr(value):
    return format_inr(value)


@register.filter
def inr0(value):
    return format_inr(value, 0)


@register.filter
def can(user, capability):
    return bool(user and user.is_authenticated and user.has_capability(capability))


@register.filter
def can_edit(obj, user):
    return obj.can_edit(user)


@register.simple_tag(takes_context=True)
def query_replace(context, **kwargs):
    """Current querystring with some keys replaced (used by pagination/sorting)."""
    params = context["request"].GET.copy()
    for key, value in kwargs.items():
        if value is None or value == "":
            params.pop(key, None)
        else:
            params[key] = value
    return urlencode(sorted((k, v) for k in params for v in params.getlist(k)))


@register.filter
def status_badge(status):
    return {
        "APPROVED": "badge-green",
        "PENDING": "badge-amber",
        "REJECTED": "badge-red",
        "ACTIVE": "badge-green",
        "MAINTENANCE": "badge-amber",
        "INACTIVE": "badge-slate",
    }.get(status, "badge-slate")


@register.filter
def get_item(mapping, key):
    return mapping.get(key) if mapping else None


@register.simple_tag(takes_context=True)
def active(context, *prefixes, exact=False, exclude=None):
    path = context["request"].path
    if exclude and path.startswith(exclude):
        return ""
    for prefix in prefixes:
        if (exact and path == prefix) or (not exact and path.startswith(prefix)):
            return "active"
    return ""


@register.filter
def visible(obj, user):
    """Can `user` open this transaction's detail page? (Entry Users: own records only.)"""
    return bool(obj) and (user.sees_all_records or obj.created_by_id == user.pk)
