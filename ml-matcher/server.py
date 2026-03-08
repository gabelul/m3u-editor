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
# Each pattern targets a specific provider formatting style
GEO_PATTERNS = [
    r'^[A-Z]{2,3}\s*[|:]\s*',       # "IT: ", "US| ", "RO: ", "UK:"
    r'^[A-Z]{2}-[A-Z]+\|\s*',       # "UK-ITV| "
    r'^[A-Z]{2,3}\s*-\s+',          # "US - CNN", "UK - Sky News"
    r'^\([A-Z]{2,3}\)\s*',          # "(US) "
    r'\|[A-Z]{2,3}\|',              # "|FR|", "|USA|"
    r'┃[A-Z]{2,3}┃\s*',            # "┃NL┃ RTL4", "┃US┃ CNN"
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
    2. Country/region prefix removal (before symbol strip so ┃XX┃ works)
    3. Superscript/symbol/hash removal
    4. Quality indicator removal
    5. Bracket content removal
    6. Numeric spacing normalization ("BBC1" -> "BBC 1")
    7. Stop word removal + whitespace collapse

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

    # Strip country/region prefixes FIRST -- before symbol stripping
    # so patterns like ┃XX┃ can still match their special chars
    for pattern in GEO_PATTERNS:
        name = re.sub(pattern, '', name, flags=re.IGNORECASE)

    # Strip superscript unicode junk
    name = re.sub(SUPERSCRIPT_PATTERN, '', name)

    # Strip special symbols
    name = re.sub(SYMBOL_PATTERN, '', name)

    # Strip hash markers
    name = re.sub(HASH_PATTERN, '', name)

    # Strip quality indicators
    for pattern in QUALITY_PATTERNS:
        name = re.sub(pattern, '', name, flags=re.IGNORECASE)

    # Remove brackets/parentheses content
    name = re.sub(r'\[.*?\]', '', name)
    name = re.sub(r'\(.*?\)', '', name)

    # Remove non-alphanumeric except spaces
    name = re.sub(r'[^\w\s]', ' ', name)

    # Add space between letters and numbers ("bbc1" -> "bbc 1", "4music" -> "4 music")
    # This makes "ITV1" and "ITV 1" equivalent after tokenization
    # Both directions needed: letter→digit AND digit→letter (e.g. "E4" -> "E 4")
    name = re.sub(r'([a-z])(\d)', r'\1 \2', name)
    name = re.sub(r'(\d)([a-z])', r'\1 \2', name)

    # Tokenize, remove stop words, collapse
    tokens = name.split()
    tokens = [t for t in tokens if t and t not in STOP_WORDS]

    return ' '.join(tokens).strip()


# Compiled regex for extracting country codes from raw channel/EPG names.
# Matches common IPTV provider prefix formats and returns the 2-3 letter code.
_COUNTRY_EXTRACT_PATTERNS = [
    re.compile(r'^([A-Z]{2,3})\s*[|:]\s*', re.IGNORECASE),     # "IT: ", "US| "
    re.compile(r'^([A-Z]{2,3})\s*-\s+', re.IGNORECASE),         # "US - CNN"
    re.compile(r'^\(([A-Z]{2,3})\)\s*', re.IGNORECASE),         # "(US) "
    re.compile(r'\|([A-Z]{2,3})\|', re.IGNORECASE),             # "|FR|"
    re.compile(r'┃([A-Z]{2,3})┃', re.IGNORECASE),               # "┃NL┃"
    re.compile(r'^([A-Z]{2})-[A-Z]+\|\s*', re.IGNORECASE),      # "UK-ITV| " → "UK"
]

# Country bonus for same-country candidates during ranking.
# Small enough not to override clearly better textual matches,
# but enough to break ties between cross-country duplicates.
COUNTRY_BONUS = 0.03

# Maximum score gap from leader for country bonus to apply.
# Candidates more than this far behind the best base score
# won't be rescued by the country bonus alone.
COUNTRY_BONUS_WINDOW = 0.08


def extract_country(name: str) -> Optional[str]:
    """
    Extract a country/region code from a raw channel name prefix.

    Detects common IPTV provider formatting patterns like "US: CNN",
    "UK| Sky News", "┃NL┃ RTL4", etc.

    @param name - Raw (un-normalized) channel or EPG name
    @returns Uppercase 2-3 letter country code, or None if not found
    """
    if not name:
        return None
    for pattern in _COUNTRY_EXTRACT_PATTERNS:
        m = pattern.search(name)
        if m:
            code = m.group(1).upper()
            # Sanity check: skip common false positives that look like country codes
            # but are actually channel name prefixes (e.g., "GO: Bloomberg" → "GO")
            if code in {'GO', 'TV', 'HD', 'SD', 'VR', 'OK', 'NO', 'VO', 'TY'}:
                continue
            return code
    return None


def _first_string(mapping: dict, *keys: str) -> str:
    """
    Return the first string value found for the given keys.

    Nested request validation already ensures top-level arrays contain dicts,
    but individual fields may still be null or non-strings. Returning an empty
    string here keeps matching logic defensive and avoids type errors.

    @param mapping - Input dict to inspect
    @param keys - Keys to try in order
    @returns First string value, or empty string if none are strings
    """
    for key in keys:
        value = mapping.get(key)
        if isinstance(value, str):
            return value
    return ""


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

def _pick_best_by_country(matches: list[dict], hint: Optional[str]) -> Optional[dict]:
    """
    From a list of equally-scored matches, prefer the same-country candidate.

    Used in exact/substring stages where multiple candidates produce the same
    normalized form. Without this, the first iteration-order match wins,
    which is arbitrary and often wrong for cross-country duplicates.

    @param matches - List of candidate dicts (must have '_country' key if hint is set)
    @param hint - Uppercase 2-3 letter country code, or None
    @returns Best candidate, or None if matches is empty
    """
    if not matches:
        return None
    if not hint or len(matches) == 1:
        return matches[0]
    # Prefer same-country match if available
    for m in matches:
        if m.get('_country') == hint:
            return m
    return matches[0]  # Fall back to first match


def match_channel(
    channel_name: str,
    epg_candidates: list[dict],
    ngram_threshold: float = DEFAULT_NGRAM_THRESHOLD,
    fuzzy_threshold: float = DEFAULT_FUZZY_THRESHOLD,
    use_ngram: bool = True,
    country_hint: Optional[str] = None,
) -> Optional[dict]:
    """
    Find the best EPG match for a channel name.

    Four-stage pipeline:
    1. Exact match after normalization
    2. Substring containment with 75% length gate
    3. RapidFuzz (token-sort + length-gated partial ratio)
    4. TF-IDF character n-gram cosine similarity (catches abbreviations/variants)

    Country-aware ranking: when country_hint is provided, candidates from the
    same country get a small scoring bonus (+0.03) in stages 3-4, but only if
    they're already within 0.08 of the best base score. This breaks ties between
    cross-country duplicates without overriding clearly better textual matches.

    @param channel_name - The channel name to match (will be normalized)
    @param epg_candidates - List of dicts with 'id', 'name', 'channel_id' keys
    @param ngram_threshold - Minimum TF-IDF cosine similarity (0-1)
    @param fuzzy_threshold - Minimum RapidFuzz score (0-100)
    @param use_ngram - Whether to try n-gram matching as fallback
    @param country_hint - Optional 2-3 letter country code for same-country bonus
    @returns Best match dict with 'id', 'name', 'score', 'method' or None
    """
    norm_channel = normalize_name(channel_name)
    if not norm_channel or len(norm_channel) < 2:
        return None

    # Normalize all candidates and extract their country codes for ranking
    candidates_with_norm = []
    for c in epg_candidates:
        raw_name = _first_string(c, 'name', 'channel_id')
        norm = normalize_name(raw_name)
        if norm:
            entry = {**c, '_norm': norm}
            # Extract country from original (un-normalized) name for country bonus
            if country_hint:
                entry['_country'] = extract_country(raw_name)
            candidates_with_norm.append(entry)

    if not candidates_with_norm:
        return None

    # --- Stage 1: Exact match after normalization (cheapest check) ---
    # If normalized names are identical, it's a perfect match — no fuzzy needed.
    # When country_hint is set and multiple exact matches exist, prefer same-country.
    norm_collapsed = re.sub(r'[\s&]+', '', norm_channel)

    # Collect all exact matches (full and collapsed) rather than returning first
    exact_matches = []
    collapsed_matches = []
    for c in candidates_with_norm:
        if c['_norm'] == norm_channel:
            exact_matches.append(c)
        else:
            cand_collapsed = re.sub(r'[\s&]+', '', c['_norm'])
            if cand_collapsed == norm_collapsed:
                collapsed_matches.append(c)

    best_exact = _pick_best_by_country(exact_matches, country_hint)
    if best_exact:
        result = {
            'id': best_exact.get('id'),
            'name': best_exact.get('name'),
            'channel_id': best_exact.get('channel_id'),
            'score': 1.0,
            'method': 'exact',
        }
        if country_hint:
            result['country_matched'] = best_exact.get('_country') == country_hint
        return result

    best_collapsed = _pick_best_by_country(collapsed_matches, country_hint)
    if best_collapsed:
        result = {
            'id': best_collapsed.get('id'),
            'name': best_collapsed.get('name'),
            'channel_id': best_collapsed.get('channel_id'),
            'score': 0.99,
            'method': 'exact',
        }
        if country_hint:
            result['country_matched'] = best_collapsed.get('_country') == country_hint
        return result

    # --- Stage 2: Substring match with 75% length gate ---
    # Catches close-length containment cases without needing fuzzy scoring,
    # while blocking loose matches like "story" ↔ "history".
    # Collects all matches at the best ratio, then prefers same-country.
    best_substr_ratio = 0.0
    substr_matches = []
    for c in candidates_with_norm:
        cn = c['_norm']
        if norm_channel in cn or cn in norm_channel:
            length_ratio = min(len(norm_channel), len(cn)) / max(len(norm_channel), len(cn), 1)
            if length_ratio >= 0.75:
                if length_ratio > best_substr_ratio:
                    best_substr_ratio = length_ratio
                    substr_matches = [c]
                elif length_ratio == best_substr_ratio:
                    substr_matches.append(c)

    if substr_matches and best_substr_ratio >= 0.75:
        best_substr_match = _pick_best_by_country(substr_matches, country_hint)
        result = {
            'id': best_substr_match.get('id'),
            'name': best_substr_match.get('name'),
            'channel_id': best_substr_match.get('channel_id'),
            'score': round(best_substr_ratio, 3),
            'method': 'substring',
        }
        if country_hint:
            result['country_matched'] = best_substr_match.get('_country') == country_hint
        return result

    best_match = None
    best_score = 0

    # --- Stage 3: RapidFuzz matching (with optional country bonus) ---
    if _rapidfuzz_available:
        # Collect all candidates with their base scores for country-aware ranking
        scored = []
        for c in candidates_with_norm:
            base_score = _compute_fuzzy_score(norm_channel, c['_norm']) / 100.0
            scored.append((base_score, c))

        if scored:
            # Find the best base score across all candidates
            best_base = max(s[0] for s in scored)

            # Apply country bonus per Codex's spec:
            # only for candidates within COUNTRY_BONUS_WINDOW of the leader
            best_adjusted = 0
            for base, c in scored:
                adjusted = base
                country_matched = False
                if country_hint and c.get('_country') == country_hint:
                    if best_base - base <= COUNTRY_BONUS_WINDOW:
                        adjusted = base + COUNTRY_BONUS
                        country_matched = True

                if adjusted > best_adjusted:
                    best_adjusted = adjusted
                    best_match = c
                    best_score = base  # Keep the original base score for reporting
                    best_match['_country_matched'] = country_matched
                    best_match['_country_bonus'] = COUNTRY_BONUS if country_matched else 0

        if best_match and best_adjusted >= (fuzzy_threshold / 100.0):
            result = {
                'id': best_match.get('id'),
                'name': best_match.get('name'),
                'channel_id': best_match.get('channel_id'),
                'score': round(best_adjusted, 3),
                'base_score': round(best_score, 3),
                'method': 'rapidfuzz',
            }
            # Include country debug fields when hint was provided
            if country_hint:
                result['country_bonus'] = best_match.get('_country_bonus', 0)
                result['country_matched'] = best_match.get('_country_matched', False)
            return result

    # --- Stage 4: TF-IDF Character N-gram Matching (fallback, with country bonus) ---
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

            # Apply country bonus to n-gram scores
            best_base_sim = max(similarities) if similarities else 0
            best_sim = 0
            best_idx = 0
            best_country_matched = False

            for i, sim in enumerate(similarities):
                adjusted = sim
                cm = False
                if country_hint and candidates_with_norm[i].get('_country') == country_hint:
                    if best_base_sim - sim <= COUNTRY_BONUS_WINDOW:
                        adjusted = sim + COUNTRY_BONUS
                        cm = True
                if adjusted > best_sim:
                    best_sim = adjusted
                    best_idx = i
                    best_country_matched = cm

            if best_sim >= ngram_threshold:
                c = candidates_with_norm[best_idx]
                result = {
                    'id': c.get('id'),
                    'name': c.get('name'),
                    'channel_id': c.get('channel_id'),
                    'score': round(best_sim, 3),
                    'base_score': round(similarities[best_idx], 3),
                    'method': 'ngram_tfidf',
                }
                if country_hint:
                    result['country_bonus'] = COUNTRY_BONUS if best_country_matched else 0
                    result['country_matched'] = best_country_matched
                return result
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

    Each channel dict may include an optional 'country_hint' field (2-3 letter
    country code). When present, same-country candidates get a small scoring
    bonus in fuzzy/n-gram stages to break ties between cross-country duplicates.

    @param channels - List of dicts with 'id', 'name', and optional 'country_hint' keys
    @param epg_candidates - List of dicts with 'id', 'name', 'channel_id' keys
    @param conservative - Use stricter thresholds (for bulk operations)
    @param use_ngram - Whether to use n-gram matching
    @returns List of match result dicts (only matched channels included)
    """
    ngram_threshold = DEFAULT_CONSERVATIVE_NGRAM if conservative else DEFAULT_NGRAM_THRESHOLD
    fuzzy_threshold = DEFAULT_CONSERVATIVE_FUZZY if conservative else DEFAULT_FUZZY_THRESHOLD

    # Check if any channel has a country_hint — if so, pre-extract countries from EPG names
    any_country_hints = any(ch.get('country_hint') for ch in channels)

    # Pre-normalize all EPG candidates
    norm_candidates = []
    for c in epg_candidates:
        raw_name = _first_string(c, 'name', 'channel_id')
        norm = normalize_name(raw_name)
        if norm:
            entry = {**c, '_norm': norm}
            # Only extract countries when at least one channel has a hint
            if any_country_hints:
                entry['_country'] = extract_country(raw_name)
            norm_candidates.append(entry)

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

    # Pre-compute collapsed names for exact matching (strip whitespace & ampersands)
    norm_cand_collapsed = [(re.sub(r'[\s&]+', '', c['_norm']), c) for c in norm_candidates]

    results = []
    for ch in channels:
        ch_name = _first_string(ch, 'name')
        norm_ch = normalize_name(ch_name)
        if not norm_ch or len(norm_ch) < 2:
            continue

        matched = False

        # --- Stage 1: Exact match after normalization ---
        # Collect all exact matches and prefer same-country when hint is set
        country_hint = ch.get('country_hint', '').upper() if ch.get('country_hint') else None
        norm_ch_collapsed = re.sub(r'[\s&]+', '', norm_ch)

        exact_hits = [c for c in norm_candidates if c['_norm'] == norm_ch]
        if exact_hits:
            pick = _pick_best_by_country(exact_hits, country_hint) if country_hint else exact_hits[0]
            entry = {
                'channel_id': ch.get('id'),
                'channel_name': ch_name,
                'epg_id': pick.get('id'),
                'epg_name': pick.get('name'),
                'epg_channel_id': pick.get('channel_id'),
                'score': 1.0,
                'method': 'exact',
            }
            if country_hint:
                entry['country_matched'] = pick.get('_country') == country_hint
            results.append(entry)
            matched = True

        if not matched:
            collapsed_hits = [c for coll, c in norm_cand_collapsed if coll == norm_ch_collapsed]
            if collapsed_hits:
                pick = _pick_best_by_country(collapsed_hits, country_hint) if country_hint else collapsed_hits[0]
                entry = {
                    'channel_id': ch.get('id'),
                    'channel_name': ch_name,
                    'epg_id': pick.get('id'),
                    'epg_name': pick.get('name'),
                    'epg_channel_id': pick.get('channel_id'),
                    'score': 0.99,
                    'method': 'exact',
                }
                if country_hint:
                    entry['country_matched'] = pick.get('_country') == country_hint
                results.append(entry)
                matched = True

        # --- Stage 2: Substring match with 75% length gate ---
        # Extract country hint early so substring and fuzzy stages can both use it
        country_hint = ch.get('country_hint', '').upper() if ch.get('country_hint') else None

        if not matched:
            best_ratio = 0.0
            substr_hits = []
            for c in norm_candidates:
                cn = c['_norm']
                if norm_ch in cn or cn in norm_ch:
                    lr = min(len(norm_ch), len(cn)) / max(len(norm_ch), len(cn), 1)
                    if lr >= 0.75:
                        if lr > best_ratio:
                            best_ratio = lr
                            substr_hits = [c]
                        elif lr == best_ratio:
                            substr_hits.append(c)

            if substr_hits:
                pick = _pick_best_by_country(substr_hits, country_hint) if country_hint else substr_hits[0]
                entry = {
                    'channel_id': ch.get('id'),
                    'channel_name': ch_name,
                    'epg_id': pick.get('id'),
                    'epg_name': pick.get('name'),
                    'epg_channel_id': pick.get('channel_id'),
                    'score': round(best_ratio, 3),
                    'method': 'substring',
                }
                if country_hint:
                    entry['country_matched'] = pick.get('_country') == country_hint
                results.append(entry)
                matched = True

        # --- Stage 3: RapidFuzz matching (with country bonus) ---

        if not matched and _rapidfuzz_available:
            scored = []
            for c in norm_candidates:
                base = _compute_fuzzy_score(norm_ch, c['_norm']) / 100.0
                scored.append((base, c))

            if scored:
                best_base = max(s[0] for s in scored)
                best_adjusted = 0
                best_candidate = None
                best_base_score = 0
                was_country_matched = False

                for base, c in scored:
                    adjusted = base
                    cm = False
                    if country_hint and c.get('_country') == country_hint:
                        if best_base - base <= COUNTRY_BONUS_WINDOW:
                            adjusted = base + COUNTRY_BONUS
                            cm = True
                    if adjusted > best_adjusted:
                        best_adjusted = adjusted
                        best_candidate = c
                        best_base_score = base
                        was_country_matched = cm

                if best_candidate and best_adjusted >= (fuzzy_threshold / 100.0):
                    entry = {
                        'channel_id': ch.get('id'),
                        'channel_name': ch_name,
                        'epg_id': best_candidate.get('id'),
                        'epg_name': best_candidate.get('name'),
                        'epg_channel_id': best_candidate.get('channel_id'),
                        'score': round(best_adjusted, 3),
                        'base_score': round(best_base_score, 3),
                        'method': 'rapidfuzz',
                    }
                    if country_hint:
                        entry['country_bonus'] = COUNTRY_BONUS if was_country_matched else 0
                        entry['country_matched'] = was_country_matched
                    results.append(entry)
                    matched = True

        # --- Stage 4: N-gram TF-IDF fallback (with country bonus) ---
        if not matched and epg_sparse_vecs is not None and tfidf_matcher is not None:
            try:
                query_sparse = tfidf_matcher._compute_sparse_tfidf(norm_ch)
                similarities = sparse_cosine_batch(query_sparse, epg_sparse_vecs)

                best_base_sim = max(similarities) if similarities else 0
                best_sim = 0
                best_idx = 0
                was_country_matched = False

                for i, sim in enumerate(similarities):
                    adjusted = sim
                    cm = False
                    if country_hint and norm_candidates[i].get('_country') == country_hint:
                        if best_base_sim - sim <= COUNTRY_BONUS_WINDOW:
                            adjusted = sim + COUNTRY_BONUS
                            cm = True
                    if adjusted > best_sim:
                        best_sim = adjusted
                        best_idx = i
                        was_country_matched = cm

                if best_sim >= ngram_threshold:
                    c = norm_candidates[best_idx]
                    entry = {
                        'channel_id': ch.get('id'),
                        'channel_name': ch_name,
                        'epg_id': c.get('id'),
                        'epg_name': c.get('name'),
                        'epg_channel_id': c.get('channel_id'),
                        'score': round(best_sim, 3),
                        'base_score': round(similarities[best_idx], 3),
                        'method': 'ngram_tfidf',
                    }
                    if country_hint:
                        entry['country_bonus'] = COUNTRY_BONUS if was_country_matched else 0
                        entry['country_matched'] = was_country_matched
                    results.append(entry)
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


def _validate_list(value, field_name: str, max_items: int, element_type: type = None) -> tuple[Optional[list], Optional[str]]:
    """
    Validate a list field from request JSON.

    @param value - Raw value from JSON
    @param field_name - Field name for error messages
    @param max_items - Maximum allowed list length
    @param element_type - If set, each element must be this type (non-matching elements are silently dropped)
    @returns Tuple of (filtered_list, error_message). Error is None if valid.
    """
    if value is None:
        return [], None
    if not isinstance(value, list):
        return None, f'{field_name} must be an array'
    if len(value) > max_items:
        return None, f'{field_name} exceeds max items ({max_items})'
    # Filter out non-matching elements (null, wrong type) instead of 500ing
    if element_type is not None:
        value = [item for item in value if isinstance(item, element_type)]
    return value, None


def _coerce_float(value, default: float) -> float:
    """Safely coerce a JSON value to float, returning default on failure."""
    if isinstance(value, (int, float)):
        return float(value)
    return default


def _coerce_bool(value, default: bool) -> bool:
    """Safely coerce a JSON value to bool. Only actual booleans count — strings like 'false' don't."""
    if isinstance(value, bool):
        return value
    return default


# ---------------------------------------------------------------------------
# Flask Endpoints
# ---------------------------------------------------------------------------

@app.route('/health', methods=['GET'])
def health():
    """Health check endpoint. Returns engine status and readiness."""
    # Service is degraded if numpy is missing (n-gram TF-IDF fallback won't work)
    ready = _rapidfuzz_available and _numpy_available
    status = 'ok' if ready else 'degraded'
    return jsonify({
        'status': status,
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
        "country_hint": "US",
        "ngram_threshold": 0.55,
        "fuzzy_threshold": 80,
        "use_ngram": true
    }

    @returns Match result or null (includes country debug fields when hint provided)
    """
    data = request.get_json(silent=True)
    if not data or not isinstance(data, dict):
        return jsonify({'error': 'JSON object body required'}), 400

    channel_name, err = _validate_string(data.get('channel_name'), 'channel_name')
    if err:
        return jsonify({'error': err}), 400

    epg_candidates, err = _validate_list(data.get('epg_candidates'), 'epg_candidates', MAX_CANDIDATES, dict)
    if err:
        return jsonify({'error': err}), 413 if 'max' in err else 400

    ngram_threshold = _coerce_float(data.get('ngram_threshold', data.get('ml_threshold')), DEFAULT_NGRAM_THRESHOLD)
    fuzzy_threshold = _coerce_float(data.get('fuzzy_threshold'), DEFAULT_FUZZY_THRESHOLD)
    use_ngram = _coerce_bool(data.get('use_ngram', data.get('use_ml')), True)

    # Extract optional country hint (2-3 letter code, e.g., "US", "UK", "ES")
    country_hint = data.get('country_hint')
    if isinstance(country_hint, str) and 2 <= len(country_hint) <= 3:
        country_hint = country_hint.upper()
    else:
        country_hint = None

    result = match_channel(channel_name, epg_candidates, ngram_threshold, fuzzy_threshold, use_ngram, country_hint)

    return jsonify({'match': result})


@app.route('/match-batch', methods=['POST'])
def match_batch_endpoint():
    """
    Match multiple channels against EPG candidates in batch.
    EPG TF-IDF vectors are computed once and reused -- much more efficient.

    POST body:
    {
        "channels": [
            {"id": 123, "name": "GO: CNN INT RAW", "country_hint": "US"},
            {"id": 456, "name": "IT: RAI 1 4K", "country_hint": "IT"}
        ],
        "epg_candidates": [
            {"id": 1, "name": "CNN International", "channel_id": "CNNI.us"},
            {"id": 2, "name": "Rai 1", "channel_id": "RaiUno.it"}
        ],
        "conservative": true,
        "use_ngram": true
    }

    Each channel may include an optional 'country_hint' (2-3 letter code).
    When provided, same-country EPG candidates get a small scoring bonus.

    @returns List of matched results (unmatched channels not included)
    """
    data = request.get_json(silent=True)
    if not data or not isinstance(data, dict):
        return jsonify({'error': 'JSON object body required'}), 400

    channels, err = _validate_list(data.get('channels'), 'channels', MAX_CHANNELS, dict)
    if err:
        return jsonify({'error': err}), 413 if 'max' in err else 400

    epg_candidates, err = _validate_list(data.get('epg_candidates'), 'epg_candidates', MAX_CANDIDATES, dict)
    if err:
        return jsonify({'error': err}), 413 if 'max' in err else 400

    conservative = _coerce_bool(data.get('conservative'), True)
    use_ngram = _coerce_bool(data.get('use_ngram', data.get('use_ml')), True)

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
