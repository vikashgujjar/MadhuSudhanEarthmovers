from datetime import timedelta

from django import forms
from django.contrib.auth.forms import AuthenticationForm, PasswordChangeForm, SetPasswordForm, UserCreationForm
from django.utils import timezone

from apps.audit.models import AuditLog
from apps.core.forms import StyledFormMixin

from .models import User

LOCKOUT_ATTEMPTS = 5
LOCKOUT_MINUTES = 15

CAPABILITY_FIELDS = [f"can_{name}" for name in User.CAPABILITIES]


class LoginForm(StyledFormMixin, AuthenticationForm):
    error_messages = {
        **AuthenticationForm.error_messages,
        "invalid_login": "Incorrect username or password. Passwords are case-sensitive.",
        "inactive": "This account has been deactivated. Contact your administrator.",
        "locked": f"Too many failed attempts. Try again in {LOCKOUT_MINUTES} minutes.",
    }

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.fields["username"].widget.attrs.update({"placeholder": "Enter your username", "autofocus": True})
        self.fields["password"].widget.attrs.update({"placeholder": "Enter your password"})

    def clean(self):
        username = (self.data.get("username") or "").strip()
        if username:
            since = timezone.now() - timedelta(minutes=LOCKOUT_MINUTES)
            failures = AuditLog.objects.filter(
                action=AuditLog.Action.LOGIN_FAILED, username__iexact=username, timestamp__gte=since
            )
            last_ok = AuditLog.objects.filter(
                action=AuditLog.Action.LOGIN, username__iexact=username, timestamp__gte=since
            ).order_by("-timestamp").first()
            if last_ok:
                failures = failures.filter(timestamp__gt=last_ok.timestamp)
            if failures.count() >= LOCKOUT_ATTEMPTS:
                raise forms.ValidationError(self.error_messages["locked"], code="locked")
        return super().clean()


class UserPermissionsMixin:
    def clean(self):
        cleaned = super().clean()
        role = cleaned.get("role")
        if role == User.Role.VIEWER:
            # Viewers are read-only regardless of flags.
            for name in User.WRITE_CAPABILITIES:
                cleaned[f"can_{name}"] = False
        if role == User.Role.ADMIN:
            for field in CAPABILITY_FIELDS:
                cleaned[field] = True
        return cleaned


class UserCreateForm(UserPermissionsMixin, StyledFormMixin, UserCreationForm):
    class Meta:
        model = User
        fields = ["username", "first_name", "last_name", "email", "phone", "role", *CAPABILITY_FIELDS]

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.fields["first_name"].required = True
        for name, label in User.CAPABILITIES.items():
            self.fields[f"can_{name}"].label = label


class UserUpdateForm(UserPermissionsMixin, StyledFormMixin, forms.ModelForm):
    class Meta:
        model = User
        fields = ["username", "first_name", "last_name", "email", "phone", "role", "is_active", *CAPABILITY_FIELDS]

    def __init__(self, *args, acting_user=None, **kwargs):
        self.acting_user = acting_user
        super().__init__(*args, **kwargs)
        self.fields["first_name"].required = True
        self.fields["is_active"].label = "Account active (can sign in)"
        for name, label in User.CAPABILITIES.items():
            self.fields[f"can_{name}"].label = label

    def clean(self):
        cleaned = super().clean()
        user = self.instance
        demoting = user.role == User.Role.ADMIN and (
            cleaned.get("role") != User.Role.ADMIN or not cleaned.get("is_active", True)
        )
        if demoting:
            other_admins = User.objects.filter(role=User.Role.ADMIN, is_active=True).exclude(pk=user.pk)
            if not other_admins.exists():
                raise forms.ValidationError("At least one active administrator is required.")
        if self.acting_user and user.pk == self.acting_user.pk:
            if not cleaned.get("is_active", True):
                self.add_error("is_active", "You cannot deactivate your own account.")
            if cleaned.get("role") != User.Role.ADMIN and user.role == User.Role.ADMIN:
                self.add_error("role", "You cannot remove your own administrator role.")
        return cleaned


class AdminSetPasswordForm(StyledFormMixin, SetPasswordForm):
    pass


class StyledPasswordChangeForm(StyledFormMixin, PasswordChangeForm):
    pass
