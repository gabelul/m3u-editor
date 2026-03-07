"""
ML-based EPG Channel Matcher Microservice.

Runs as a lightweight Flask server inside the M3U Editor Docker container.
Provides similarity matching using a multi-strategy approach:
  - RapidFuzz (fast fuzzy string matching)
  - Character n-gram TF-IDF with cosine similarity (catches abbreviations/variants)

No PyTorch or ONNX Runtime needed — runs on Alpine Linux (musl) with just
NumPy + RapidFuzz. The character n-gram approach is actually better suited
for short channel names than heavyweight transformer models anyway.

Endpoints:
    POST /match       - Match a single channel name against EPG candidates
    POST /match-batch - Match multiple channels in one call (efficient)
    GET  /health      - Health check + engine status
    POST /preload     - Warm-up check (kept for API compat)

@see SimilaritySearchService.php for the PHP integration
"""

import os
import re
import time
import math
import logging
import unicodedata
from collections import Counter
from typing import Optional

from flask import Flask, request, jsonify

# Lazy imports for optional libs
_rapidfuzz_available = False

try:
    from rapidfuzz import fuzz
    _rapidfuzz_available = True
except ImportError:
    pass

try:
    import numpy as np
    _numpy_available = True
except ImportError:
    _numpy_available = False

# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------
HOST = os.environ.get("ML_MATCHER_HOST", "127.0.0.1")
PORT = int(os.environ.get("ML_MATCHER_PORT", "5599"))
LOG_LEVEL = os.environ.get("ML_MATCHER_LOG_LEVEL", "INFO")

# Matching thresholds (can be overridden per-request)
DEFAULT_NGRAM_THRESHOLD = 0.58       # Minimum TF-IDF cosine similarity
DEFAULT_FUZZY_THRESHOLD = 80         # Minimum RapidFuzz score (0-100)
DEFAULT_CONSERVATIVE_NGRAM = 0.65    # Stricter threshold for bulk matching
DEFAULT_CONSERVATIVE_FUZZY = 90      # Stricter fuzzy for bulk matching

# N-gram configuration
NGRAM_RANGE = (2, 4)  # Character n-gram sizes (bigrams through 4-grams)

# Input caps — prevent DoS via oversized payloads
MAX_CANDIDATES = 10_000   # Max EPG candidates per request
MAX_CHANNELS = 5_000      # Max channels per batch request

# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------
logging.basicConfig(
    level=getattr(logging, LOG_LEVEL.upper(), logging.INFO),
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)
logger = logging.getLogger("ml-matcher")

app = Flask(__name__)

# Cap request body size at 10MB to prevent memory exhaustion before
# we even get to the per-field input caps in the endpoint handlers
app.config['MAX_CONTENT_LENGTH'] = 10 * 1024 * 1024

# Max names for the /normalize debug endpoint
MAX_NORMALIZE_NAMES = 1_000


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
    'raw', 'backup', 'bkp', 'multi', 'new',  # Provider filler tags
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

    # Unicode NFD normalization -- separates accents from base chars
    name = unicodedata.normalize('NFD', name)
    name = ''.join(c for c in name if unicodedata.category(c) != 'Mn')

    # Lowercase
    name = name.lower()

    # Strip superscript unicode junk
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


# ---------------------------------------------------------------------------
# Character N-gram TF-IDF Engine
#
# Pure Python + NumPy implementation. No scikit-learn, no PyTorch.
# Designed for short strings (channel names) where character-level
# patterns matter more than word-level semantics.
# ---------------------------------------------------------------------------

def extract_char_ngrams(text: str, ngram_range: tuple = NGRAM_RANGE) -> list[str]:
    """
    Extract character n-grams from a text string.

    Pads the text with spaces so edge characters get n-grams too,
    e.g. "bbc" with (2,3) -> [" b", "bb", "bc", "c ", " bb", "bbc", "bc "]

    Returns empty list for empty/whitespace-only input to avoid
    producing meaningless space-only n-grams.

    @param text - Input text (should be normalized/lowercased)
    @param ngram_range - Tuple of (min_n, max_n) for n-gram sizes
    @returns List of character n-gram strings
    """
    if not text or not text.strip():
        return []

    padded = f" {text} "
    ngrams = []
    for n in range(ngram_range[0], ngram_range[1] + 1):
        for i in range(len(padded) - n + 1):
            ngrams.append(padded[i:i + n])
    return ngrams


class NgramTfidfMatcher:
    """
    Character n-gram TF-IDF matcher with cosine similarity.

    This is a lightweight alternative to transformer-based semantic matching.
    For short strings like channel names, character n-grams capture the
    structural patterns (shared prefixes, common substrings, abbreviations)
    that matter most for matching.

    How it works:
    1. Extract character n-grams from each name (e.g., "bbc" -> "bb", "bc", "bbc")
    2. Build a vocabulary and compute IDF weights (rare n-grams matter more)
    3. Represent each name as a TF-IDF weighted sparse vector
    4. Compare via cosine similarity (dot product of L2-normalized vectors)

    The IDF weighting is key: common n-grams like "ch" or "an" (from "channel")
    get low weight, while distinctive n-grams like "cnn" or "bbc" get high weight.

    Uses sparse representation (dict per text) internally. Python dict overhead
    is higher than raw floats (~100 bytes per entry vs 8), but still orders of
    magnitude better than a dense matrix at scale (e.g., 50k candidates with
    ~50 non-zero entries each ≈ 250MB vs 7.5GB dense).
    """

    def __init__(self, ngram_range: tuple = NGRAM_RANGE):
        self.ngram_range = ngram_range
        self.vocabulary = {}       # ngram -> column index
        self.idf_weights = None    # IDF vector (1 x vocab_size)
        self._fitted = False

    def fit(self, texts: list[str]) -> 'NgramTfidfMatcher':
        """
        Build vocabulary and compute IDF weights from a corpus of texts.

        IDF = log(N / df) + 1
        The +1 is an additive offset (not smoothed IDF which would be
        log((1+N) / (1+df)) + 1). This prevents zero-weight for n-grams
        appearing in every document while still ranking them lowest.

        @param texts - List of normalized text strings
        @returns self (for chaining)
        """
        if not texts:
            return self

        n_docs = len(texts)

        # Count document frequency for each n-gram
        # (how many texts contain this n-gram, not how many times it appears)
        doc_freq = Counter()
        for text in texts:
            unique_ngrams = set(extract_char_ngrams(text, self.ngram_range))
            doc_freq.update(unique_ngrams)

        # Build vocabulary (assign column index to each n-gram)
        self.vocabulary = {ngram: idx for idx, ngram in enumerate(sorted(doc_freq.keys()))}

        # Compute IDF: log(N / df) + 1
        vocab_size = len(self.vocabulary)
        self.idf_weights = np.zeros(vocab_size)
        for ngram, idx in self.vocabulary.items():
            df = doc_freq[ngram]
            self.idf_weights[idx] = math.log(n_docs / df) + 1.0

        self._fitted = True
        return self

    def _compute_sparse_tfidf(self, text: str) -> dict[int, float]:
        """
        Compute TF-IDF for a single text as a sparse dict {col_idx: value}.
        Only stores non-zero entries, so memory scales with text length,
        not vocabulary size.

        @param text - Normalized text string
        @returns Sparse TF-IDF vector as {column_index: tfidf_value}
        """
        ngrams = extract_char_ngrams(text, self.ngram_range)
        if not ngrams:
            return {}

        counts = Counter(ngrams)
        total = len(ngrams)
        sparse_vec = {}

        for ngram, count in counts.items():
            if ngram in self.vocabulary:
                idx = self.vocabulary[ngram]
                tf = count / total
                sparse_vec[idx] = tf * self.idf_weights[idx]

        # L2 normalize the sparse vector
        norm = math.sqrt(sum(v * v for v in sparse_vec.values()))
        if norm > 0:
            sparse_vec = {k: v / norm for k, v in sparse_vec.items()}

        return sparse_vec

    def transform_sparse(self, texts: list[str]) -> list[dict[int, float]]:
        """
        Transform texts into sparse TF-IDF vectors.
        Each vector is a dict {col_idx: value} with only non-zero entries.
        Memory-safe for large vocabularies since we never allocate a dense matrix.

        @param texts - List of normalized text strings
        @returns List of sparse TF-IDF dicts, L2-normalized
        """
        if not self._fitted or not self.vocabulary:
            return [{} for _ in texts]
        return [self._compute_sparse_tfidf(text) for text in texts]

    def transform_dense(self, texts: list[str]) -> 'np.ndarray':
        """
        Transform texts into a dense TF-IDF matrix.
        Use only for small batches where dense matrix fits comfortably in memory.

        @param texts - List of normalized text strings
        @returns numpy array of shape (len(texts), vocab_size), L2-normalized
        """
        if not self._fitted or not self.vocabulary:
            return np.zeros((len(texts), 1))

        vocab_size = len(self.vocabulary)
        matrix = np.zeros((len(texts), vocab_size))

        for i, text in enumerate(texts):
            sparse = self._compute_sparse_tfidf(text)
            for idx, val in sparse.items():
                matrix[i, idx] = val

        return matrix


def sparse_cosine_similarity(query_sparse: dict[int, float], candidate_sparse: dict[int, float]) -> float:
    """
    Cosine similarity between two sparse L2-normalized vectors.
    Since both are normalized, this is just the dot product of shared dimensions.

    @param query_sparse - Sparse TF-IDF dict for query
    @param candidate_sparse - Sparse TF-IDF dict for candidate
    @returns Cosine similarity score (0-1)
    """
    # Iterate over the smaller dict for efficiency
    if len(query_sparse) > len(candidate_sparse):
        query_sparse, candidate_sparse = candidate_sparse, query_sparse

    dot = 0.0
    for idx, val in query_sparse.items():
        if idx in candidate_sparse:
            dot += val * candidate_sparse[idx]
    return dot


def sparse_cosine_batch(query_sparse: dict[int, float], candidate_sparses: list[dict[int, float]]) -> list[float]:
    """
    Cosine similarity between one sparse query and multiple sparse candidates.

    @param query_sparse - Sparse TF-IDF dict for query
    @param candidate_sparses - List of sparse TF-IDF dicts for candidates
    @returns List of similarity scores
    """
    return [sparse_cosine_similarity(query_sparse, c) for c in candidate_sparses]


# ---------------------------------------------------------------------------
# RapidFuzz Scoring Helper
# ---------------------------------------------------------------------------

def _compute_fuzzy_score(norm_a: str, norm_b: str) -> float:
    """
    Compute the best fuzzy score between two normalized names.

    Uses token_sort_ratio as the primary score (handles word reordering).
    partial_ratio is used as a secondary signal but gated by a 75% length
    ratio to prevent false positives between sibling channels like
    "HBO Max" and "HBO 2" (following Stream-Mapparr's approach).

    @param norm_a - First normalized name
    @param norm_b - Second normalized name
    @returns Best fuzzy score (0-100)
    """
    # Token sort ratio -- handles word order differences
    score = fuzz.token_sort_ratio(norm_a, norm_b)

    # Partial ratio -- handles substrings like "CNN" in "CNN International"
    # Gated by length ratio (75% threshold from Stream-Mapparr) to prevent
    # false matches between sibling channels like "HBO Max" vs "HBO 2"
    len_ratio = min(len(norm_a), len(norm_b)) / max(len(norm_a), len(norm_b), 1)
    if len_ratio >= 0.75:
        partial = fuzz.partial_ratio(norm_a, norm_b)
        score = max(score, partial)

    return score


# ---------------------------------------------------------------------------
# Matching Logic
# ---------------------------------------------------------------------------

def match_channel(
    channel_name: str,
    epg_candidates: list[dict],
    ngram_threshold: float = DEFAULT_NGRAM_THRESHOLD,
    fuzzy_threshold: float = DEFAULT_FUZZY_THRESHOLD,
    use_ngram: bool = True,
) -> Optional[dict]:
    """
    Find the best EPG match for a channel name.

    Two-stage matching:
    1. RapidFuzz (token-sort + length-gated partial ratio)
    2. TF-IDF character n-gram cosine similarity (catches abbreviations/variants)

    @param channel_name - The channel name to match (will be normalized)
    @param epg_candidates - List of dicts with 'id', 'name', 'channel_id' keys
    @param ngram_threshold - Minimum TF-IDF cosine similarity (0-1)
    @param fuzzy_threshold - Minimum RapidFuzz score (0-100)
    @param use_ngram - Whether to try n-gram matching as fallback
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
            candidates_with_norm.append({**c, '_norm': norm})

    if not candidates_with_norm:
        return None

    best_match = None
    best_score = 0

    # --- Stage 1: RapidFuzz matching ---
    if _rapidfuzz_available:
        for c in candidates_with_norm:
            final_score = _compute_fuzzy_score(norm_channel, c['_norm'])
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

    # --- Stage 2: TF-IDF Character N-gram Matching (fallback) ---
    # Fit on candidates only (not query) so query-specific n-grams
    # don't dilute IDF weights. This matches batch-mode behavior.
    if use_ngram and _numpy_available:
        try:
            candidate_texts = [c['_norm'] for c in candidates_with_norm]
            matcher = NgramTfidfMatcher(ngram_range=NGRAM_RANGE)
            matcher.fit(candidate_texts)

            # Use sparse similarity — no dense matrix needed for single match
            query_sparse = matcher._compute_sparse_tfidf(norm_channel)
            candidate_sparses = matcher.transform_sparse(candidate_texts)
            similarities = sparse_cosine_batch(query_sparse, candidate_sparses)

            best_idx = max(range(len(similarities)), key=lambda i: similarities[i])
            best_sim = similarities[best_idx]

            if best_sim >= ngram_threshold:
                c = candidates_with_norm[best_idx]
                return {
                    'id': c.get('id'),
                    'name': c.get('name'),
                    'channel_id': c.get('channel_id'),
                    'score': round(best_sim, 3),
                    'method': 'ngram_tfidf',
                }
        except Exception as e:
            logger.error(f"N-gram matching error: {e}")

    return None


def match_batch(
    channels: list[dict],
    epg_candidates: list[dict],
    conservative: bool = True,
    use_ngram: bool = True,
) -> list[dict]:
    """
    Match multiple channels against EPG candidates in batch.

    More efficient than single matching -- computes EPG TF-IDF vectors once
    and reuses them across all channel queries. Uses sparse representation
    to stay memory-safe with large candidate sets.

    @param channels - List of dicts with 'id' and 'name' keys
    @param epg_candidates - List of dicts with 'id', 'name', 'channel_id' keys
    @param conservative - Use stricter thresholds (for bulk operations)
    @param use_ngram - Whether to use n-gram matching
    @returns List of match result dicts (only matched channels included)
    """
    ngram_threshold = DEFAULT_CONSERVATIVE_NGRAM if conservative else DEFAULT_NGRAM_THRESHOLD
    fuzzy_threshold = DEFAULT_CONSERVATIVE_FUZZY if conservative else DEFAULT_FUZZY_THRESHOLD

    # Pre-normalize all EPG candidates
    norm_candidates = []
    for c in epg_candidates:
        norm = normalize_name(c.get('name', '') or c.get('channel_id', ''))
        if norm:
            norm_candidates.append({**c, '_norm': norm})

    if not norm_candidates:
        return []

    # Pre-compute EPG sparse TF-IDF vectors for n-gram matching
    epg_sparse_vecs = None
    tfidf_matcher = None
    if use_ngram and _numpy_available:
        try:
            epg_texts = [c['_norm'] for c in norm_candidates]
            tfidf_matcher = NgramTfidfMatcher(ngram_range=NGRAM_RANGE)
            tfidf_matcher.fit(epg_texts)
            epg_sparse_vecs = tfidf_matcher.transform_sparse(epg_texts)
        except Exception as e:
            logger.error(f"Failed to compute EPG TF-IDF vectors: {e}")

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
                final = _compute_fuzzy_score(norm_ch, c['_norm'])
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

        # --- N-gram TF-IDF fallback for unmatched ---
        if not matched and epg_sparse_vecs is not None and tfidf_matcher is not None:
            try:
                query_sparse = tfidf_matcher._compute_sparse_tfidf(norm_ch)
                similarities = sparse_cosine_batch(query_sparse, epg_sparse_vecs)

                best_idx = max(range(len(similarities)), key=lambda i: similarities[i])
                best_sim = similarities[best_idx]

                if best_sim >= ngram_threshold:
                    c = norm_candidates[best_idx]
                    results.append({
                        'channel_id': ch.get('id'),
                        'channel_name': ch_name,
                        'epg_id': c.get('id'),
                        'epg_name': c.get('name'),
                        'epg_channel_id': c.get('channel_id'),
                        'score': round(best_sim, 3),
                        'method': 'ngram_tfidf',
                    })
            except Exception as e:
                logger.error(f"N-gram batch matching error for '{ch_name}': {e}")

    return results


# ---------------------------------------------------------------------------
# Request Validation
# ---------------------------------------------------------------------------

def _validate_string(value, field_name: str, max_length: int = 500) -> tuple[Optional[str], Optional[str]]:
    """
    Validate and sanitize a string field from request JSON.

    @param value - Raw value from JSON
    @param field_name - Field name for error messages
    @param max_length - Maximum allowed string length
    @returns Tuple of (sanitized_string, error_message). Error is None if valid.
    """
    if value is None:
        return '', None
    if not isinstance(value, str):
        return None, f'{field_name} must be a string'
    if len(value) > max_length:
        return None, f'{field_name} exceeds max length ({max_length})'
    return value, None


def _validate_list(value, field_name: str, max_items: int) -> tuple[Optional[list], Optional[str]]:
    """
    Validate a list field from request JSON.

    @param value - Raw value from JSON
    @param field_name - Field name for error messages
    @param max_items - Maximum allowed list length
    @returns Tuple of (list, error_message). Error is None if valid.
    """
    if value is None:
        return [], None
    if not isinstance(value, list):
        return None, f'{field_name} must be an array'
    if len(value) > max_items:
        return None, f'{field_name} exceeds max items ({max_items})'
    return value, None


# ---------------------------------------------------------------------------
# Flask Endpoints
# ---------------------------------------------------------------------------

@app.route('/health', methods=['GET'])
def health():
    """Health check endpoint. Returns engine status."""
    return jsonify({
        'status': 'ok',
        'engine': 'ngram_tfidf',
        'ngram_range': list(NGRAM_RANGE),
        'rapidfuzz_available': _rapidfuzz_available,
        'numpy_available': _numpy_available,
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
            {"id": 2, "name": "CNN en Espanol", "channel_id": "CNNE.us"}
        ],
        "ngram_threshold": 0.55,
        "fuzzy_threshold": 80,
        "use_ngram": true
    }

    @returns Match result or null
    """
    data = request.get_json(silent=True)
    if not data or not isinstance(data, dict):
        return jsonify({'error': 'JSON object body required'}), 400

    channel_name, err = _validate_string(data.get('channel_name'), 'channel_name')
    if err:
        return jsonify({'error': err}), 400

    epg_candidates, err = _validate_list(data.get('epg_candidates'), 'epg_candidates', MAX_CANDIDATES)
    if err:
        return jsonify({'error': err}), 413 if 'max' in err else 400

    ngram_threshold = data.get('ngram_threshold', data.get('ml_threshold', DEFAULT_NGRAM_THRESHOLD))
    fuzzy_threshold = data.get('fuzzy_threshold', DEFAULT_FUZZY_THRESHOLD)
    use_ngram = data.get('use_ngram', data.get('use_ml', True))

    result = match_channel(channel_name, epg_candidates, ngram_threshold, fuzzy_threshold, use_ngram)

    return jsonify({'match': result})


@app.route('/match-batch', methods=['POST'])
def match_batch_endpoint():
    """
    Match multiple channels against EPG candidates in batch.
    EPG TF-IDF vectors are computed once and reused -- much more efficient.

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
        "use_ngram": true
    }

    @returns List of matched results (unmatched channels not included)
    """
    data = request.get_json(silent=True)
    if not data or not isinstance(data, dict):
        return jsonify({'error': 'JSON object body required'}), 400

    channels, err = _validate_list(data.get('channels'), 'channels', MAX_CHANNELS)
    if err:
        return jsonify({'error': err}), 413 if 'max' in err else 400

    epg_candidates, err = _validate_list(data.get('epg_candidates'), 'epg_candidates', MAX_CANDIDATES)
    if err:
        return jsonify({'error': err}), 413 if 'max' in err else 400

    conservative = data.get('conservative', True)
    use_ngram = data.get('use_ngram', data.get('use_ml', True))

    start = time.time()
    results = match_batch(channels, epg_candidates, conservative, use_ngram)
    elapsed = time.time() - start

    return jsonify({
        'matches': results,
        'total_channels': len(channels),
        'total_matched': len(results),
        'elapsed_seconds': round(elapsed, 2),
    })


@app.route('/preload', methods=['POST'])
def preload():
    """Warm up check. N-gram TF-IDF doesn't need preloading, but kept for API compat."""
    return jsonify({
        'engine': 'ngram_tfidf',
        'ready': _numpy_available,
        'rapidfuzz_available': _rapidfuzz_available,
    })


@app.route('/normalize', methods=['POST'])
def normalize_endpoint():
    """
    Normalize channel names (useful for debugging).

    POST body: {"names": ["GO: CNN INT RAW", "IT| RAI 1 UHD"]}
    @returns List of normalized names
    """
    data = request.get_json(silent=True)
    if not data or not isinstance(data, dict):
        return jsonify({'error': 'JSON object body required'}), 400

    names, err = _validate_list(data.get('names'), 'names', MAX_NORMALIZE_NAMES)
    if err:
        return jsonify({'error': err}), 413 if 'max' in err else 400

    results = [{'original': n, 'normalized': normalize_name(str(n))} for n in names]

    return jsonify({'results': results})


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

if __name__ == '__main__':
    logger.info(f"Starting ML Matcher on {HOST}:{PORT}")
    logger.info(f"Engine: Character n-gram TF-IDF ({NGRAM_RANGE[0]}-{NGRAM_RANGE[1]}grams)")
    logger.info(f"RapidFuzz available: {_rapidfuzz_available}")
    logger.info(f"NumPy available: {_numpy_available}")
    app.run(host=HOST, port=PORT, debug=False)
