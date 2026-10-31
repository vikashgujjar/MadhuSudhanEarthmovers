from django.contrib import messages
from django.contrib.auth import update_session_auth_hash
from django.contrib.auth import views as auth_views
from django.shortcuts import get_object_or_404, redirect
from django.urls import reverse, reverse_lazy
from django.views import View
from django.views.generic import CreateView, FormView, ListView, UpdateView

from apps.audit import services as audit
from apps.audit.models import AuditLog
from apps.core.permissions import AdminRequiredMixin
from apps.core.views_mixins import SearchFilterMixin

from .forms import AdminSetPasswordForm, LoginForm, StyledPasswordChangeForm, UserCreateForm, UserUpdateForm
from .models import User


class LoginView(auth_views.LoginView):
    template_name = "accounts/login.html"
    authentication_form = LoginForm
    redirect_authenticated_user = True


class LogoutView(auth_views.LogoutView):
    pass


class PasswordChangeView(auth_views.PasswordChangeView):
    template_name = "accounts/password_change.html"
    form_class = StyledPasswordChangeForm
    success_url = reverse_lazy("core:dashboard")

    def form_valid(self, form):
        response = super().form_valid(form)
        audit.log(AuditLog.Action.UPDATE, self.request.user, changes={"note": "Changed own password"})
        messages.success(self.request, "Your password has been changed.")
        return response


class UserListView(AdminRequiredMixin, SearchFilterMixin, ListView):
    model = User
    template_name = "accounts/user_list.html"
    context_object_name = "users"
    search_fields = ("username", "first_name", "last_name", "email", "phone")

    def get_queryset(self):
        qs = self.apply_search(User.objects.all())
        role = self.request.GET.get("role")
        if role in User.Role.values:
            qs = qs.filter(role=role)
        status = self.request.GET.get("status")
        if status == "active":
            qs = qs.filter(is_active=True)
        elif status == "inactive":
            qs = qs.filter(is_active=False)
        return qs.order_by("-is_active", "role", "first_name", "username")

    def get_context_data(self, **kwargs):
        ctx = super().get_context_data(**kwargs)
        ctx["roles"] = User.Role.choices
        ctx["capability_labels"] = User.CAPABILITIES
        return ctx


class UserCreateView(AdminRequiredMixin, CreateView):
    model = User
    form_class = UserCreateForm
    template_name = "accounts/user_form.html"
    success_url = reverse_lazy("accounts:user_list")

    def get_initial(self):
        return {"role": User.Role.ENTRY}

    def form_valid(self, form):
        response = super().form_valid(form)
        audit.log_create(self.object)
        messages.success(self.request, f"User '{self.object.username}' created.")
        return response


class UserUpdateView(AdminRequiredMixin, UpdateView):
    model = User
    form_class = UserUpdateForm
    template_name = "accounts/user_form.html"
    success_url = reverse_lazy("accounts:user_list")

    def get_form_kwargs(self):
        kwargs = super().get_form_kwargs()
        kwargs["acting_user"] = self.request.user
        return kwargs

    def get_object(self, queryset=None):
        obj = super().get_object(queryset)
        self._before = audit.snapshot(obj)
        return obj

    def form_valid(self, form):
        response = super().form_valid(form)
        audit.log_update(self.object, self._before)
        messages.success(self.request, f"User '{self.object.username}' updated.")
        return response


class UserSetPasswordView(AdminRequiredMixin, FormView):
    form_class = AdminSetPasswordForm
    template_name = "accounts/user_set_password.html"

    def dispatch(self, request, *args, **kwargs):
        self.target = get_object_or_404(User, pk=kwargs["pk"])
        return super().dispatch(request, *args, **kwargs)

    def get_form_kwargs(self):
        kwargs = super().get_form_kwargs()
        kwargs["user"] = self.target
        return kwargs

    def get_context_data(self, **kwargs):
        ctx = super().get_context_data(**kwargs)
        ctx["target"] = self.target
        return ctx

    def form_valid(self, form):
        form.save()
        if self.target == self.request.user:
            update_session_auth_hash(self.request, self.target)
        audit.log(AuditLog.Action.UPDATE, self.target, changes={"note": "Password reset by administrator"})
        messages.success(self.request, f"Password for '{self.target.username}' has been reset.")
        return redirect("accounts:user_list")


class UserToggleActiveView(AdminRequiredMixin, View):
    def post(self, request, pk):
        target = get_object_or_404(User, pk=pk)
        if target == request.user:
            messages.error(request, "You cannot deactivate your own account.")
        elif target.is_active and target.role == User.Role.ADMIN and not User.objects.filter(
            role=User.Role.ADMIN, is_active=True
        ).exclude(pk=target.pk).exists():
            messages.error(request, "At least one active administrator is required.")
        else:
            before = audit.snapshot(target)
            target.is_active = not target.is_active
            target.save(update_fields=["is_active"])
            audit.log_update(target, before)
            messages.success(request, f"'{target.username}' {'activated' if target.is_active else 'deactivated'}.")
        return redirect(request.POST.get("next") or reverse("accounts:user_list"))
