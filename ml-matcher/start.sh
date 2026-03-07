#!/bin/sh
##
# Self-bootstrapping startup script for the ML Matcher service.
#
# Installs Python dependencies on first run into a persistent directory
# (/var/www/config/ml-matcher-deps). This avoids baking heavy ML libs
# (~800MB PyTorch + sentence-transformers) into the Docker image.
#
# On subsequent starts, skips install if deps already exist.
# Also downloads the ML model on first run (~90MB).
##

set -e

DEPS_DIR="/var/www/config/ml-matcher-deps"
MARKER="${DEPS_DIR}/.installed"
REQUIREMENTS="/opt/ml-matcher/requirements.txt"
MODEL_CACHE="${DEPS_DIR}/models"
SCRIPT="/opt/ml-matcher/server.py"

# Create deps directory if it doesn't exist
mkdir -p "${DEPS_DIR}"

# Install dependencies if not already done
if [ ! -f "${MARKER}" ]; then
    echo "[ml-matcher] First run — installing Python dependencies to ${DEPS_DIR}..."
    echo "[ml-matcher] This may take 5-10 minutes on first run. Subsequent starts will be fast."

    # Install with pip to the persistent directory
    # Uses transformers + torch directly (no sentence-transformers/scikit-learn
    # which require compilation from source on Alpine's musl libc)
    pip3 install \
        --no-cache-dir \
        --break-system-packages \
        --target="${DEPS_DIR}" \
        --extra-index-url https://download.pytorch.org/whl/cpu \
        torch "transformers>=4.41.0" flask "rapidfuzz>=3.6.0" "numpy>=1.26.0" \
        2>&1

    if [ $? -eq 0 ]; then
        echo "[ml-matcher] Dependencies installed successfully."
        date > "${MARKER}"
    else
        echo "[ml-matcher] ERROR: Failed to install dependencies. ML matching will be unavailable."
        echo "[ml-matcher] The service will retry on next container restart."
        exit 1
    fi

    # Pre-download the ML model (tokenizer + weights)
    echo "[ml-matcher] Downloading ML model (all-MiniLM-L6-v2)..."
    PYTHONPATH="${DEPS_DIR}:${PYTHONPATH}" \
    HF_HOME="${MODEL_CACHE}" \
    python3 -c "from transformers import AutoTokenizer, AutoModel; AutoTokenizer.from_pretrained('sentence-transformers/all-MiniLM-L6-v2'); AutoModel.from_pretrained('sentence-transformers/all-MiniLM-L6-v2')" \
        2>&1 || echo "[ml-matcher] WARNING: Model pre-download failed. Will download on first request."
else
    echo "[ml-matcher] Dependencies already installed ($(cat ${MARKER})). Starting server..."
fi

# Set up paths and start the server
export PYTHONPATH="${DEPS_DIR}:${PYTHONPATH}"
export HF_HOME="${MODEL_CACHE}"

exec python3 "${SCRIPT}"
