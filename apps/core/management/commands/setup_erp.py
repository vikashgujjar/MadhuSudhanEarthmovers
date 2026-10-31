import os

from django.core.management.base import BaseCommand, CommandError

from apps.accounts.models import User
from apps.core.models import BusinessSettings


class Command(BaseCommand):
    help = "Ensure business settings exist and create the first administrator."

    def add_arguments(self, parser):
        parser.add_argument("--username", default=os.environ.get("ERP_ADMIN_USERNAME", "admin"))
        parser.add_argument("--password", default=os.environ.get("ERP_ADMIN_PASSWORD"))
        parser.add_argument("--email", default=os.environ.get("ERP_ADMIN_EMAIL", ""))
        parser.add_argument("--name", default="Administrator", help="First name of the administrator.")

    def handle(self, *args, **opts):
        business = BusinessSettings.load()
        self.stdout.write(f"Organisation: {business.business_name}")
        username = opts["username"]
        if User.objects.filter(username=username).exists():
            self.stdout.write(self.style.WARNING(f"User '{username}' already exists; nothing changed."))
            return
        password = opts["password"]
        if not password:
            raise CommandError("Provide --password (or ERP_ADMIN_PASSWORD) for the new administrator.")
        if len(password) < 8:
            raise CommandError("Password must be at least 8 characters.")
        User.objects.create_superuser(
            username=username, email=opts["email"], password=password, first_name=opts["name"], role=User.Role.ADMIN
        )
        self.stdout.write(self.style.SUCCESS(f"Administrator '{username}' created."))
