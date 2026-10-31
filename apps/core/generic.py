"""Create / update / delete views that write the audit log and show messages."""
from django.contrib import messages
from django.db import transaction
from django.db.models import ProtectedError
from django.http import HttpResponseRedirect
from django.views.generic import CreateView, DeleteView, UpdateView

from apps.audit import services as audit


class AuditedCreateView(CreateView):
    template_name = "partials/generic_form.html"
    success_message = "{obj} saved."

    def form_valid(self, form):
        if hasattr(form.instance, "created_by_id") and not form.instance.created_by_id:
            form.instance.created_by = self.request.user
        response = super().form_valid(form)
        audit.log_create(self.object)
        messages.success(self.request, self.success_message.format(obj=self.object))
        return response

    def form_invalid(self, form):
        messages.error(self.request, "The record was not saved. Please correct the highlighted fields.")
        return super().form_invalid(form)


class AuditedUpdateView(UpdateView):
    template_name = "partials/generic_form.html"
    success_message = "{obj} updated."

    def get_object(self, queryset=None):
        obj = super().get_object(queryset)
        self._before = audit.snapshot(obj)
        return obj

    def form_valid(self, form):
        response = super().form_valid(form)
        audit.log_update(self.object, self._before)
        messages.success(self.request, self.success_message.format(obj=self.object))
        return response

    def form_invalid(self, form):
        messages.error(self.request, "Changes were not saved. Please correct the highlighted fields.")
        return super().form_invalid(form)


class AuditedDeleteView(DeleteView):
    template_name = "partials/confirm_delete.html"
    protected_message = "This record cannot be deleted because other records depend on it."

    def form_valid(self, form):
        obj = self.get_object()
        label = str(obj)
        try:
            # The audit entry and the delete commit together, or not at all.
            with transaction.atomic():
                audit.log_delete(obj)
                obj.delete()
        except ProtectedError:
            messages.error(self.request, self.protected_message)
            return HttpResponseRedirect(self.get_protected_url(obj))
        messages.success(self.request, f"{label} deleted.")
        return HttpResponseRedirect(self.get_success_url())

    def get_protected_url(self, obj):
        return obj.get_absolute_url() if hasattr(obj, "get_absolute_url") else self.get_success_url()
