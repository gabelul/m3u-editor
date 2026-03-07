#!/bin/sh
##
# Self-bootstrapping startup script for the ML Matcher service.
#
# Installs Python dependencies on first run into system Python.
# Lightweight: only Flask + RapidFuzz + NumPy (~20MB total).
# No PyTorch/ONNX Runtime needed — uses character n-gram TF-IDF.
#
# On subsequent starts, skips install if deps already exist.
# Handles image rebuilds: marker lives on persistent volume but packages
# live in the container filesystem, so we verify importability too.
##

set -e

MARKER="/var/www/config/.ml-matcher-installed"
SCRIPT="/opt/ml-matcher/server.py"
REQUIREMENTS="/opt/ml-matcher/requirements.txt"

# Check if deps are actually importable (not just marker present).
# The marker lives on persistent volume but packages are in the container
# filesystem — after an image rebuild the marker survives but packages don't.
needs_install() {
    if [ ! -f "${MARKER}" ]; then
        return 0  # No marker = definitely needs install
    fi
    # Marker exists, but verify packages are actually importable
    if ! python3 -c "import flask, rapidfuzz" 2>/dev/null; then
        echo "[ml-matcher] Marker exists but packages missing (image rebuilt?). Reinstalling..."
        return 0
    fi
    return 1  # Marker exists and packages work
}

if needs_install; then
    echo "[ml-matcher] Installing Python dependencies..."
    echo "[ml-matcher] This should take under 30 seconds."

    pip3 install \
        --no-cache-dir \
        --break-system-packages \
        -r "${REQUIREMENTS}"

    echo "[ml-matcher] Dependencies installed successfully."
    date > "${MARKER}"
else
    echo "[ml-matcher] Dependencies verified ($(cat ${MARKER})). Starting server..."
fi

exec python3 "${SCRIPT}"
