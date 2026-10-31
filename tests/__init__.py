import logging

# Tests deliberately trigger 403/404 responses; don't print their tracebacks.
logging.getLogger("django.request").setLevel(logging.ERROR)
