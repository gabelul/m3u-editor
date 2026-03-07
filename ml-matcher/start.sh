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

# Check if deps need (re)install. Handles three cases:
# 1. No marker at all → first install
# 2. Marker exists but packages missing → image was rebuilt
# 3. Marker exists but requirements.txt changed → deps were updated
needs_install() {
    if [ ! -f "${MARKER}" ]; then
        return 0  # No marker = definitely needs install
    fi
    # Verify all packages are actually importable
    if ! python3 -c "import flask, rapidfuzz, numpy" 2>/dev/null; then
        echo "[ml-matcher] Marker exists but packages missing (image rebuilt?). Reinstalling..."
        return 0
    fi
    # Check if requirements.txt changed since last install (new deps)
    CURRENT_HASH=$(md5sum "${REQUIREMENTS}" 2>/dev/null | cut -d' ' -f1)
    STORED_HASH=$(cat "${MARKER}.hash" 2>/dev/null)
    if [ "${CURRENT_HASH}" != "${STORED_HASH}" ]; then
        echo "[ml-matcher] requirements.txt changed. Reinstalling..."
        return 0
    fi
    return 1  # Marker exists, packages work, deps unchanged
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
    md5sum "${REQUIREMENTS}" 2>/dev/null | cut -d' ' -f1 > "${MARKER}.hash"
else
    echo "[ml-matcher] Dependencies verified ($(cat ${MARKER})). Starting server..."
fi

exec python3 "${SCRIPT}"
