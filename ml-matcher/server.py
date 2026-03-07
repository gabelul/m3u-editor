"""
ML-based EPG Channel Matcher Microservice.

Runs as a lightweight Flask server inside the M3U Editor Docker container.
Provides semantic similarity matching using sentence-transformers when
traditional Levenshtein/cosine matching fails.

Endpoints:
    POST /match       - Match a single channel name against EPG candidates
    POST /match-batch - Match multiple channels in one call (efficient)
    GET  /health      - Health check + model status
    POST /preload     - Force model loading (warm up cache)

The model (all-MiniLM-L6-v2) is lazy-loaded on first request and cached
in memory for subsequent calls. ~80MB RAM footprint.

@see SimilaritySearchService.php for the PHP integration
"""

import os
import re
import time
import logging
import unicodedata
from functools import lru_cache
from typing import Optional

from flask import Flask, request, jsonify

# Lazy imports for heavy ML libs — only loaded when needed
_model = None
_rapidfuzz_available = False

try:
    from rapidfuzz import fuzz, process as rf_process
    _rapidfuzz_available = True
except ImportError:
    pass

# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------
MODEL_NAME = os.environ.get("ML_MODEL", "sentence-transformers/all-MiniLM-L6-v2")
HOST = os.environ.get("ML_MATCHER_HOST", "127.0.0.1")
PORT = int(os.environ.get("ML_MATCHER_PORT", "5599"))
LOG_LEVEL = os.environ.get("ML_MATCHER_LOG_LEVEL", "INFO")

# Matching thresholds (can be overridden per-request)
DEFAULT_ML_THRESHOLD = 0.65       # Minimum cosine similarity for ML match
DEFAULT_FUZZY_THRESHOLD = 85      # Minimum RapidFuzz score (0-100)
DEFAULT_CONSERVATIVE_ML = 0.75    # Stricter threshold for bulk matching
DEFAULT_CONSERVATIVE_FUZZY = 90   # Stricter fuzzy for bulk matching

# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------
logging.basicConfig(
    level=getattr(logging, LOG_LEVEL.upper(), logging.INFO),
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)
logger = logging.getLogger("ml-matcher")

app = Flask(__name__)


# ---------------------------------------------------------------------------
# Name Normalization Pipeline
# Borrowed from Stream-Mapparr's proven approach + our normalizer
# ---------------------------------------------------------------------------

# Quality indicators to strip
QUALITY_PATTERNS = [
    r'\[(?:4K|8K|UHD|FHD|HD|SD|FD)\]',
    r'\((?:4K|8K|UHD|FHD|HD|SD|FD)\)',
    r'\b(?:4K|8K|UHD|FHD|HEVC|H\.?264|H\.?265)\b',
    r'\b(?:1080[pi]|720p|2160p|480p)\b',
]

# Country/region prefixes to strip
GEO_PATTERNS = [
    r'^[A-Z]{2,3}\s*[|:]\s*',       # "IT: ", "US| ", "RO: "
    r'^[A-Z]{2}-[A-Z]+\|\s*',       # "UK-ITV| "
    r'^\([A-Z]{2,3}\)\s*',          # "(US) "
    r'\|[A-Z]{2}\|',                # "|FR|"
]

# Unicode superscript junk from IPTV providers
SUPERSCRIPT_PATTERN = r'[ᴬᴮᴰᴱᴳᴴᴵᴶᴷᴸᴹᴺᴼᴾᴿᵀᵁᵂᵃᵇᵈᵉᵍᵏᵐᵒᵖᵗᵘᵛᶜᶠᶦᶰᶻʰʲʷʸˡˢ⁰¹²³⁴⁵⁶⁷⁸⁹]+'

# Special characters and symbols
SYMBOL_PATTERN = r'[◉●★▶►▪■□◆♦✦⚡🔴🟢🔵⬤┃│]+'

# Hash markers
HASH_PATTERN = r'#+\s*|\s*#+$'

# Stop words (common filler that hurts matching)
STOP_WORDS = {
    'tv', 'channel', 'network', 'television', 'the', 'and',
    'live', 'stream', 'online', 'free', 'iptv',
}


def normalize_name(name: str) -> str:
    """
    Normalize a channel name for matching.

    Applies the full cleaning pipeline:
    1. Unicode NFD decomposition (strips accents)
    2. Country/region prefix removal
    3. Quality indicator removal
    4. Superscript/symbol removal
    5. Stop word removal
    6. Numeric spacing normalization ("BBC1" -> "BBC 1")
    7. Whitespace collapse

    @param name - Raw channel name from provider
    @returns Cleaned, lowercased name ready for matching
    """
    if not name:
        return ""

    # Unicode NFD normalization — separates accents from base chars
    # "é" becomes "e" + combining accent, then we strip combining chars
    name = unicodedata.normalize('NFD', name)
    name = ''.join(c for c in name if unicodedata.category(c) != 'Mn')

    # Lowercase
    name = name.lower()

    # Strip superscript unicode junk (ᴿᴬᵂ, ᴴᴰ, etc.)
    name = re.sub(SUPERSCRIPT_PATTERN, '', name)

    # Strip special symbols
    name = re.sub(SYMBOL_PATTERN, '', name)

    # Strip hash markers
    name = re.sub(HASH_PATTERN, '', name)

    # Strip country/region prefixes
    for pattern in GEO_PATTERNS:
        name = re.sub(pattern, '', name, flags=re.IGNORECASE)

    # Strip quality indicators
    for pattern in QUALITY_PATTERNS:
        name = re.sub(pattern, '', name, flags=re.IGNORECASE)

    # Remove brackets/parentheses content
    name = re.sub(r'\[.*?\]', '', name)
    name = re.sub(r'\(.*?\)', '', name)

    # Remove non-alphanumeric except spaces
    name = re.sub(r'[^\w\s]', ' ', name)

    # Add space between letters and numbers ("bbc1" -> "bbc 1")
    # This makes "ITV1" and "ITV 1" equivalent after tokenization
    name = re.sub(r'([a-z])(\d)', r'\1 \2', name)

    # Tokenize, remove stop words, collapse
    tokens = name.split()
    tokens = [t for t in tokens if t and t not in STOP_WORDS]

    return ' '.join(tokens).strip()


def token_sort_key(name: str) -> str:
    """
    Create a token-sorted version of a name for comparison.
    Sorting tokens makes "Fox News" == "News Fox" match.

    @param name - Normalized channel name
    @returns Space-joined sorted tokens
    """
    return ' '.join(sorted(name.split()))


# ---------------------------------------------------------------------------
# ML Model Management
# ---------------------------------------------------------------------------

def _load_model():
    """
    Lazy-load the sentence-transformers model.
    Only called on first matching request. ~80MB RAM, ~2s load time.
    """
    global _model
    if _model is not None:
        return _model

    logger.info(f"Loading ML model: {MODEL_NAME}")
    start = time.time()

    try:
        from sentence_transformers import SentenceTransformer
        _model = SentenceTransformer(MODEL_NAME)
        elapsed = time.time() - start
        logger.info(f"Model loaded in {elapsed:.1f}s")
    except Exception as e:
        logger.error(f"Failed to load ML model: {e}")
        _model = None

    return _model


def compute_embeddings(texts: list[str]):
    """
    Compute sentence embeddings for a list of texts.

    @param texts - List of normalized channel names
    @returns numpy array of embeddings, or None if model unavailable
    """
    model = _load_model()
    if model is None:
        return None

    import numpy as np
    embeddings = model.encode(texts, convert_to_numpy=True, show_progress_bar=False)

    # Normalize for cosine similarity (dot product of normalized vectors = cosine sim)
    norms = np.linalg.norm(embeddings, axis=1, keepdims=True)
    norms[norms == 0] = 1  # Avoid division by zero
    return embeddings / norms


def cosine_similarity_batch(query_embedding, candidate_embeddings):
    """
    Compute cosine similarity between one query and multiple candidates.
    Since embeddings are normalized, this is just a dot product.

    @param query_embedding - Single embedding vector
    @param candidate_embeddings - Matrix of candidate embeddings
    @returns Array of similarity scores
    """
    import numpy as np
    return np.dot(candidate_embeddings, query_embedding)


# ---------------------------------------------------------------------------
# Matching Logic
# ---------------------------------------------------------------------------

def match_channel(
    channel_name: str,
    epg_candidates: list[dict],
    ml_threshold: float = DEFAULT_ML_THRESHOLD,
    fuzzy_threshold: float = DEFAULT_FUZZY_THRESHOLD,
    use_ml: bool = True,
) -> Optional[dict]:
    """
    Find the best EPG match for a channel name.

    Three-stage matching:
    1. RapidFuzz token-sort ratio (fast, catches abbreviations)
    2. RapidFuzz partial ratio (catches substrings like "CNN" in "CNN International")
    3. ML semantic similarity (catches "CNN INT" -> "CNN International")

    @param channel_name - The channel name to match (will be normalized)
    @param epg_candidates - List of dicts with 'id', 'name', 'channel_id' keys
    @param ml_threshold - Minimum ML similarity score (0-1)
    @param fuzzy_threshold - Minimum RapidFuzz score (0-100)
    @param use_ml - Whether to try ML matching as fallback
    @returns Best match dict with 'id', 'name', 'score', 'method' or None
    """
    norm_channel = normalize_name(channel_name)
    if not norm_channel or len(norm_channel) < 2:
        return None

    # Normalize all candidates
    candidates_with_norm = []
    for c in epg_candidates:
        norm = normalize_name(c.get('name', '') or c.get('channel_id', ''))
        if norm:
            candidates_with_norm.append({**c, '_norm': norm, '_token_sort': token_sort_key(norm)})

    if not candidates_with_norm:
        return None

    channel_token_sort = token_sort_key(norm_channel)
    best_match = None
    best_score = 0

    # --- Stage 1: RapidFuzz token-sort matching ---
    if _rapidfuzz_available:
        for c in candidates_with_norm:
            # Token sort ratio — handles word order differences
            score = fuzz.token_sort_ratio(norm_channel, c['_norm'])

            # Also try partial ratio — handles "CNN" matching "CNN International"
            partial = fuzz.partial_ratio(norm_channel, c['_norm'])
            # Partial ratio needs length guard (Stream-Mapparr's 75% rule)
            len_ratio = min(len(norm_channel), len(c['_norm'])) / max(len(norm_channel), len(c['_norm']), 1)
            if len_ratio < 0.4:
                partial = partial * 0.7  # Penalize very different lengths

            final_score = max(score, partial)

            if final_score > best_score:
                best_score = final_score
                best_match = c

        if best_match and best_score >= fuzzy_threshold:
            return {
                'id': best_match.get('id'),
                'name': best_match.get('name'),
                'channel_id': best_match.get('channel_id'),
                'score': round(best_score / 100, 3),
                'method': 'rapidfuzz',
            }

    # --- Stage 2: ML Semantic Matching (fallback) ---
    if use_ml:
        model = _load_model()
        if model is not None:
            try:
                # Encode channel name + all candidates in one batch
                all_texts = [norm_channel] + [c['_norm'] for c in candidates_with_norm]
                embeddings = compute_embeddings(all_texts)

                if embeddings is not None:
                    query_emb = embeddings[0]
                    candidate_embs = embeddings[1:]
                    similarities = cosine_similarity_batch(query_emb, candidate_embs)

                    best_idx = similarities.argmax()
                    best_sim = float(similarities[best_idx])

                    if best_sim >= ml_threshold:
                        c = candidates_with_norm[best_idx]
                        return {
                            'id': c.get('id'),
                            'name': c.get('name'),
                            'channel_id': c.get('channel_id'),
                            'score': round(best_sim, 3),
                            'method': 'ml_semantic',
                        }
            except Exception as e:
                logger.error(f"ML matching error: {e}")

    return None


def match_batch(
    channels: list[dict],
    epg_candidates: list[dict],
    conservative: bool = True,
    use_ml: bool = True,
) -> list[dict]:
    """
    Match multiple channels against EPG candidates in batch.

    More efficient than single matching — computes EPG embeddings once
    and reuses them across all channel queries.

    @param channels - List of dicts with 'id' and 'name' keys
    @param epg_candidates - List of dicts with 'id', 'name', 'channel_id' keys
    @param conservative - Use stricter thresholds (for bulk operations)
    @param use_ml - Whether to use ML matching
    @returns List of match result dicts (only matched channels included)
    """
    ml_threshold = DEFAULT_CONSERVATIVE_ML if conservative else DEFAULT_ML_THRESHOLD
    fuzzy_threshold = DEFAULT_CONSERVATIVE_FUZZY if conservative else DEFAULT_FUZZY_THRESHOLD

    # Pre-normalize all EPG candidates
    norm_candidates = []
    for c in epg_candidates:
        norm = normalize_name(c.get('name', '') or c.get('channel_id', ''))
        if norm:
            norm_candidates.append({**c, '_norm': norm, '_token_sort': token_sort_key(norm)})

    if not norm_candidates:
        return []

    # Pre-compute EPG embeddings if ML enabled
    epg_embeddings = None
    if use_ml:
        model = _load_model()
        if model is not None:
            try:
                epg_texts = [c['_norm'] for c in norm_candidates]
                epg_embeddings = compute_embeddings(epg_texts)
            except Exception as e:
                logger.error(f"Failed to compute EPG embeddings: {e}")

    results = []
    for ch in channels:
        ch_name = ch.get('name', '')
        norm_ch = normalize_name(ch_name)
        if not norm_ch or len(norm_ch) < 2:
            continue

        matched = False

        # --- RapidFuzz matching ---
        if _rapidfuzz_available:
            best_score = 0
            best_candidate = None

            for c in norm_candidates:
                score = fuzz.token_sort_ratio(norm_ch, c['_norm'])
                partial = fuzz.partial_ratio(norm_ch, c['_norm'])
                len_ratio = min(len(norm_ch), len(c['_norm'])) / max(len(norm_ch), len(c['_norm']), 1)
                if len_ratio < 0.4:
                    partial = partial * 0.7

                final = max(score, partial)
                if final > best_score:
                    best_score = final
                    best_candidate = c

            if best_candidate and best_score >= fuzzy_threshold:
                results.append({
                    'channel_id': ch.get('id'),
                    'channel_name': ch_name,
                    'epg_id': best_candidate.get('id'),
                    'epg_name': best_candidate.get('name'),
                    'epg_channel_id': best_candidate.get('channel_id'),
                    'score': round(best_score / 100, 3),
                    'method': 'rapidfuzz',
                })
                matched = True

        # --- ML fallback for unmatched ---
        if not matched and epg_embeddings is not None:
            try:
                import numpy as np
                ch_embedding = compute_embeddings([norm_ch])
                if ch_embedding is not None:
                    similarities = cosine_similarity_batch(ch_embedding[0], epg_embeddings)
                    best_idx = int(similarities.argmax())
                    best_sim = float(similarities[best_idx])

                    if best_sim >= ml_threshold:
                        c = norm_candidates[best_idx]
                        results.append({
                            'channel_id': ch.get('id'),
                            'channel_name': ch_name,
                            'epg_id': c.get('id'),
                            'epg_name': c.get('name'),
                            'epg_channel_id': c.get('channel_id'),
                            'score': round(best_sim, 3),
                            'method': 'ml_semantic',
                        })
            except Exception as e:
                logger.error(f"ML batch matching error for '{ch_name}': {e}")

    return results


# ---------------------------------------------------------------------------
# Flask Endpoints
# ---------------------------------------------------------------------------

@app.route('/health', methods=['GET'])
def health():
    """Health check endpoint. Returns model status."""
    return jsonify({
        'status': 'ok',
        'model_loaded': _model is not None,
        'model_name': MODEL_NAME,
        'rapidfuzz_available': _rapidfuzz_available,
    })


@app.route('/match', methods=['POST'])
def match_single():
    """
    Match a single channel name against EPG candidates.

    POST body:
    {
        "channel_name": "GO: CNN INT RAW",
        "epg_candidates": [
            {"id": 1, "name": "CNN International", "channel_id": "CNNI.us"},
            {"id": 2, "name": "CNN en Español", "channel_id": "CNNE.us"}
        ],
        "ml_threshold": 0.65,
        "fuzzy_threshold": 85,
        "use_ml": true
    }

    @returns Match result or null
    """
    data = request.get_json()
    if not data:
        return jsonify({'error': 'JSON body required'}), 400

    channel_name = data.get('channel_name', '')
    epg_candidates = data.get('epg_candidates', [])
    ml_threshold = data.get('ml_threshold', DEFAULT_ML_THRESHOLD)
    fuzzy_threshold = data.get('fuzzy_threshold', DEFAULT_FUZZY_THRESHOLD)
    use_ml = data.get('use_ml', True)

    result = match_channel(channel_name, epg_candidates, ml_threshold, fuzzy_threshold, use_ml)

    return jsonify({'match': result})


@app.route('/match-batch', methods=['POST'])
def match_batch_endpoint():
    """
    Match multiple channels against EPG candidates in batch.
    EPG embeddings are computed once and reused — much more efficient.

    POST body:
    {
        "channels": [
            {"id": 123, "name": "GO: CNN INT RAW"},
            {"id": 456, "name": "IT: RAI 1 4K"}
        ],
        "epg_candidates": [
            {"id": 1, "name": "CNN International", "channel_id": "CNNI.us"},
            {"id": 2, "name": "Rai 1", "channel_id": "RaiUno.it"}
        ],
        "conservative": true,
        "use_ml": true
    }

    @returns List of matched results (unmatched channels not included)
    """
    data = request.get_json()
    if not data:
        return jsonify({'error': 'JSON body required'}), 400

    channels = data.get('channels', [])
    epg_candidates = data.get('epg_candidates', [])
    conservative = data.get('conservative', True)
    use_ml = data.get('use_ml', True)

    start = time.time()
    results = match_batch(channels, epg_candidates, conservative, use_ml)
    elapsed = time.time() - start

    return jsonify({
        'matches': results,
        'total_channels': len(channels),
        'total_matched': len(results),
        'elapsed_seconds': round(elapsed, 2),
    })


@app.route('/preload', methods=['POST'])
def preload():
    """Force model loading. Call during startup to warm the cache."""
    model = _load_model()
    return jsonify({
        'model_loaded': model is not None,
        'model_name': MODEL_NAME,
    })


@app.route('/normalize', methods=['POST'])
def normalize_endpoint():
    """
    Normalize channel names (useful for debugging).

    POST body: {"names": ["GO: CNN INT RAW", "IT| RAI 1 UHD"]}
    @returns List of normalized names
    """
    data = request.get_json()
    if not data:
        return jsonify({'error': 'JSON body required'}), 400

    names = data.get('names', [])
    results = [{'original': n, 'normalized': normalize_name(n)} for n in names]

    return jsonify({'results': results})


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

if __name__ == '__main__':
    logger.info(f"Starting ML Matcher on {HOST}:{PORT}")
    logger.info(f"Model: {MODEL_NAME}")
    logger.info(f"RapidFuzz available: {_rapidfuzz_available}")
    app.run(host=HOST, port=PORT, debug=False)
