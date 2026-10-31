from django.contrib import admin

from .models import Driver, Vehicle

admin.site.register(Vehicle, list_display=("registration_number", "category", "name", "status"), list_filter=("category", "status"))
admin.site.register(Driver, list_display=("name", "phone", "assigned_vehicle", "status"), list_filter=("status",))
