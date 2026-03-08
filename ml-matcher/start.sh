#!/bin/sh
##
# Startup script for the ML Matcher service.
#
# Python dependencies are installed into the image at Docker build time,
# so startup only needs a lightweight import smoke test before launching.
##

set -e

SCRIPT="/opt/ml-matcher/server.py"

python3 -c "import flask, rapidfuzz, numpy" >/dev/null 2>&1 || {
    echo "[ml-matcher] ERROR: Required Python dependencies are missing from the image."
    exit 1
}

exec python3 "${SCRIPT}"
