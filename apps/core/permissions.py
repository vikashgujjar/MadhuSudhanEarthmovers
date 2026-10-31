"""Server-side access control. Every view that reads or writes business data
uses one of these mixins; templates hiding a button is never the only guard."""
from django.contrib.auth.mixins import LoginRequiredMixin
from django.core.exceptions import PermissionDenied


class AdminRequiredMixin(LoginRequiredMixin):
    def dispatch(self, request, *args, **kwargs):
        if not request.user.is_authenticated:
            return self.handle_no_permission()
        if not request.user.is_admin:
            raise PermissionDenied("Only administrators can access this page.")
        return super().dispatch(request, *args, **kwargs)


class CapabilityRequiredMixin(LoginRequiredMixin):
    """Requires `capability` (a key of User.CAPABILITIES). A list means the
    user needs at least one of them."""

    capability = None

    def get_capability(self):
        return self.capability

    def dispatch(self, request, *args, **kwargs):
        if not request.user.is_authenticated:
            return self.handle_no_permission()
        required = self.get_capability()
        if required:
            names = [required] if isinstance(required, str) else list(required)
            if not any(request.user.has_capability(n) for n in names):
                raise PermissionDenied("You do not have permission to perform this action.")
        return super().dispatch(request, *args, **kwargs)


class WriteAccessMixin(LoginRequiredMixin):
    """Blocks Viewers from any form that writes data."""

    def dispatch(self, request, *args, **kwargs):
        if request.user.is_authenticated and request.user.is_viewer:
            raise PermissionDenied("Viewers have read-only access.")
        return super().dispatch(request, *args, **kwargs)
