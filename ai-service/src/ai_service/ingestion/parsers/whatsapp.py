import re

# Canonical pattern for a standard WhatsApp chat export line:
# Format: DD/MM/YY, HH:MM - Sender Name: Message content...
# Example: 15/05/23, 14:30 - John Doe: Hello world
WHATSAPP_LINE_PATTERN = re.compile(r"^\d{1,2}/\d{1,2}/\d{2,4}, \d{1,2}:\d{2}\s*-\s*[^:]+:")
