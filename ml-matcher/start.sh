#!/bin/sh
##
# Self-bootstrapping startup script for the ML Matcher service.
#
# Installs Python dependencies on first run into system Python.
# Lightweight: only Flask + RapidFuzz + NumPy (~20MB total).
# No PyTorch/ONNX Runtime needed — uses character n-gram TF-IDF.
#
# On subsequent starts, skips install if deps already exist.
##

set -e

MARKER="/var/www/config/.ml-matcher-installed"
SCRIPT="/opt/ml-matcher/server.py"
REQUIREMENTS="/opt/ml-matcher/requirements.txt"

# Install dependencies if not already done
# Installs to system Python (not --target) to avoid cross-device link issues
# when /var/www/config is on a different filesystem (volume mount)
if [ ! -f "${MARKER}" ]; then
    echo "[ml-matcher] First run — installing Python dependencies..."
    echo "[ml-matcher] This should take under 30 seconds."

    pip3 install \
        --no-cache-dir \
        --break-system-packages \
        -r "${REQUIREMENTS}"

    echo "[ml-matcher] Dependencies installed successfully."
    date > "${MARKER}"
else
    echo "[ml-matcher] Dependencies already installed ($(cat ${MARKER})). Starting server..."
fi

exec python3 "${SCRIPT}"
