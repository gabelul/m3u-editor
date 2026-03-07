#!/bin/bash
# =============================================================================
# Install ML Matcher dependencies
# =============================================================================
# Called during Docker build or on first container startup.
# Installs sentence-transformers, rapidfuzz, and pre-downloads the model
# so the first matching request doesn't have a cold-start delay.
# =============================================================================

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "=== Installing ML Matcher dependencies ==="
pip3 install --no-cache-dir --break-system-packages -r "$SCRIPT_DIR/requirements.txt"

echo "=== Pre-downloading ML model ==="
python3 -c "
from sentence_transformers import SentenceTransformer
model = SentenceTransformer('sentence-transformers/all-MiniLM-L6-v2')
print(f'Model loaded: {model.get_sentence_embedding_dimension()} dimensions')
"

echo "=== ML Matcher installation complete ==="
