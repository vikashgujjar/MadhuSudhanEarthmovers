from django.contrib import admin

from .models import Customer

admin.site.register(Customer, list_display=("name", "phone", "opening_balance", "is_active"), search_fields=("name",))
