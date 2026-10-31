from django.contrib import admin
from django.contrib.auth.admin import UserAdmin

from .models import User


@admin.register(User)
class ERPUserAdmin(UserAdmin):
    list_display = ("username", "first_name", "last_name", "role", "is_active", "last_login")
    list_filter = ("role", "is_active")
    fieldsets = UserAdmin.fieldsets + (
        ("ERP role & permissions", {"fields": ("role", "phone", *[f"can_{c}" for c in User.CAPABILITIES])}),
    )
