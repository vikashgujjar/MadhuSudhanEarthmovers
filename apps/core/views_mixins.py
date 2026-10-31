from django.conf import settings
from django.db.models import Q


class SearchFilterMixin:
    """`?q=` searching over `search_fields` for ListViews."""

    search_fields = ()

    def apply_search(self, qs):
        query = (self.request.GET.get("q") or "").strip()
        if query and self.search_fields:
            cond = Q()
            for field in self.search_fields:
                cond |= Q(**{f"{field}__icontains": query})
            qs = qs.filter(cond)
        return qs

    def get_paginate_by(self, queryset):
        return settings.ERP_PAGE_SIZE
