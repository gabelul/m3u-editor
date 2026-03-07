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

MARKER="/var/www/config/.ml-matcher-installed"
MODEL_CACHE="/var/www/config/ml-matcher-models"
SCRIPT="/opt/ml-matcher/server.py"

# Install dependencies if not already done
# Installs to system Python (not --target) to avoid cross-device link issues
# when /var/www/config is on a different filesystem (volume mount)
if [ ! -f "${MARKER}" ]; then
    echo "[ml-matcher] First run — installing Python dependencies..."
    echo "[ml-matcher] This may take 3-5 minutes. Subsequent starts will be instant."

    pip3 install \
        --no-cache-dir \
        --break-system-packages \
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

    # Pre-download the ML model (tokenizer + weights) to persistent volume
    mkdir -p "${MODEL_CACHE}"
    echo "[ml-matcher] Downloading ML model (all-MiniLM-L6-v2)..."
    HF_HOME="${MODEL_CACHE}" \
    python3 -c "from transformers import AutoTokenizer, AutoModel; AutoTokenizer.from_pretrained('sentence-transformers/all-MiniLM-L6-v2'); AutoModel.from_pretrained('sentence-transformers/all-MiniLM-L6-v2')" \
        2>&1 || echo "[ml-matcher] WARNING: Model pre-download failed. Will download on first request."
else
    echo "[ml-matcher] Dependencies already installed ($(cat ${MARKER})). Starting server..."
fi

# Point HuggingFace cache to persistent volume so model survives container recreations
export HF_HOME="${MODEL_CACHE}"

exec python3 "${SCRIPT}"
