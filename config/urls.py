from django.contrib import admin
from django.urls import include, path

from apps.core.views import protected_media

urlpatterns = [
    path("", include("apps.core.urls")),
    path("accounts/", include("apps.accounts.urls")),
    path("fleet/", include("apps.fleet.urls")),
    path("customers/", include("apps.customers.urls")),
    path("finance/", include("apps.finance.urls")),
    path("reports/", include("apps.reports.urls")),
    path("audit/", include("apps.audit.urls")),
    path("media/<path:path>", protected_media, name="media"),
    path("django-admin/", admin.site.urls),
]

handler403 = "apps.core.views.error_403"
handler404 = "apps.core.views.error_404"
handler500 = "apps.core.views.error_500"
