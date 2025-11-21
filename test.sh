#!/usr/bin/env bash
# Example commands for exercising the license server endpoints.

# Activate a license
# curl -X POST http://localhost:8000/license/activate \
#   -H 'Content-Type: application/json' \
#   -d '{"license_key":"TEST-KEY-123","site_url":"https://example.com","plugin_slug":"slotkit-pro"}'

# Check license status
# curl "http://localhost:8000/license/check?license_key=TEST-KEY-123&site_url=https://example.com&plugin_slug=slotkit-pro"

# Check for plugin updates
# curl "http://localhost:8000/update/check?license_key=TEST-KEY-123&site_url=https://example.com&plugin_slug=slotkit-pro&current_version=1.0.0"
