from django.db import migrations

DEFAULT_NAME = "Madhusudhan Earthmover"


def create_default(apps, schema_editor):
    BusinessSettings = apps.get_model("core", "BusinessSettings")
    BusinessSettings.objects.get_or_create(
        pk=1,
        defaults={
            "business_name": DEFAULT_NAME,
            "erp_title": f"{DEFAULT_NAME} ERP",
            "tagline": "JCB, Tractor, Earthmoving & Equipment Services",
        },
    )


class Migration(migrations.Migration):
    dependencies = [("core", "0001_initial")]

    operations = [migrations.RunPython(create_default, migrations.RunPython.noop)]
