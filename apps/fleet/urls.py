from django.urls import path

from . import views
from .models import VehicleCategory

app_name = "fleet"

urlpatterns = [
    path("jcb/", views.VehicleListView.as_view(category=VehicleCategory.JCB,
                                               template_name="fleet/category_fleet.html"), name="jcb"),
    path("tractors/", views.VehicleListView.as_view(category=VehicleCategory.TRACTOR,
                                                    template_name="fleet/category_fleet.html"), name="tractors"),
    path("vehicles/", views.VehicleListView.as_view(), name="vehicle_list"),
    path("vehicles/new/", views.VehicleCreateView.as_view(), name="vehicle_create"),
    path("vehicles/<int:pk>/", views.VehicleDetailView.as_view(), name="vehicle_detail"),
    path("vehicles/<int:pk>/edit/", views.VehicleUpdateView.as_view(), name="vehicle_update"),
    path("vehicles/<int:pk>/delete/", views.VehicleDeleteView.as_view(), name="vehicle_delete"),
    path("drivers/", views.DriverListView.as_view(), name="driver_list"),
    path("drivers/new/", views.DriverCreateView.as_view(), name="driver_create"),
    path("drivers/<int:pk>/", views.DriverDetailView.as_view(), name="driver_detail"),
    path("drivers/<int:pk>/edit/", views.DriverUpdateView.as_view(), name="driver_update"),
    path("drivers/<int:pk>/delete/", views.DriverDeleteView.as_view(), name="driver_delete"),
    path("api/vehicles/", views.VehicleOptionsView.as_view(), name="api_vehicles"),
]
