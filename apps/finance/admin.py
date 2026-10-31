from django.contrib import admin

from .models import Expense, Income, Payment

for model in (Income, Expense, Payment):
    admin.site.register(model, list_display=("number", "date", "amount", "status"), list_filter=("status",),
                        search_fields=("number",))
