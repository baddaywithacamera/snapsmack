"""
GOBSMACKED Scanner — scanner.py
Python port of core/ste-style.php v1 (25-dimension stylometric vector).
Matches the PHP implementation exactly so vectors are interoperable.
"""

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import math
import re
from typing import Optional

# ─── Constants (must match PHP STE_STYLE_* defines) ───────────────────────────

MIN_WORDS  = 30
DIMENSIONS = 25

FUNCTION_WORDS = {
    'the','a','an','and','but','or','nor','if','as','at','by','for',
    'from','in','of','on','to','up','with','about','after','before',
    'into','out','over','so','than','until','when','where','while',
}

TRACKED_WORDS = ['the','and','but','or','i','you','it','that','this','just','really']

CONTRACTIONS = [
    "i'm","i've","i'd","i'll","you're","you've","you'd","you'll",
    "he's","she's","it's","we're","we've","we'd","we'll",
    "they're","they've","they'd","they'll","that's","what's",
    "don't","doesn't","didn't","can't","couldn't","won't","wouldn't",
    "isn't","aren't","wasn't","weren't","haven't","hasn't","hadn't",
    "there's","here's","let's","who's","how's","that'd","should've",
    "could've","would've","might've","must've",
]

FULL_FORMS = [
    'do not','does not','did not','cannot','could not','will not',
    'would not','is not','are not','was not','were not',
    'have not','has not','had not','i am','i have','i will',
    'they are','we are','you are','there is',
]

# ─── Tokenisers ───────────────────────────────────────────────────────────────

def _strip_html(text: str) -> str:
    return re.sub(r'<[^>]+>', ' ', text)

def _words(text: str) -> list[str]:
    """Split on whitespace; keep only tokens containing at least one letter/digit."""
    return [w for w in re.split(r'\s+', text.strip()) if re.search(r'[A-Za-z0-9]', w)]

def _sentences(text: str) -> list[str]:
    """Split on sentence-ending punctuation (rough but matches PHP behaviour)."""
    parts = re.split(r'(?<=[.!?])\s+', text.strip())
    return [p for p in parts if p.strip()]

# ─── Feature extractor ────────────────────────────────────────────────────────

def extract_vector(comment_texts: list[str]) -> Optional[list[float]]:
    """
    Compute a 25-element stylometric feature vector from a list of comment strings.
    Returns None if the combined text is below the minimum word threshold.
    Mirrors ste_style_extract() in PHP exactly.
    """
    cleaned = [re.sub(r'\s+', ' ', _strip_html(t).strip()) for t in comment_texts]
    full_text = ' '.join(t for t in cleaned if t)
    if not full_text:
        return None

    words      = _words(full_text)
    word_count = len(words)
    if word_count < MIN_WORDS:
        return None

    sentences  = _sentences(full_text)
    sent_count = max(1, len(sentences))
    lower_text  = full_text.lower()
    lower_words = [w.lower() for w in words]

    # 0. doc_len
    doc_len = min(1.0, math.log(word_count + 1) / math.log(500))

    # 1. avg_word_len
    avg_word_len = min(1.0, (sum(len(w) for w in words) / word_count) / 10.0)

    # 2. ttr
    ttr = len(set(lower_words)) / word_count

    # 3. func_word_ratio
    func_count = sum(1 for w in lower_words if w in FUNCTION_WORDS)
    func_word_ratio = func_count / word_count

    # 4. excl_rate
    excl_rate = min(1.0, full_text.count('!') / sent_count)

    # 5. quest_rate
    quest_rate = min(1.0, full_text.count('?') / sent_count)

    # 6. comma_per_word
    comma_per_word = min(1.0, (full_text.count(',') / word_count) * 10.0)

    # 7. ellipsis_rate
    ellipsis_rate = min(1.0, full_text.count('...') / sent_count)

    # 8. caps_i
    cap_i   = sum(1 for w in words if w == 'I')
    lower_i = sum(1 for w in words if w == 'i')
    total_i = cap_i + lower_i
    caps_i  = (cap_i / total_i) if total_i > 0 else 0.5

    # 9. all_caps_ratio
    all_caps = sum(
        1 for w in words
        if len(w) >= 2 and w == w.upper() and w.isalpha()
    )
    all_caps_ratio = min(1.0, all_caps / word_count)

    # 10. digit_ratio
    digit_ratio = min(1.0, sum(1 for w in words if w.isdigit()) / word_count)

    # 11. contraction_ratio
    cont_count  = sum(lower_text.count(c) for c in CONTRACTIONS)
    full_count  = sum(lower_text.count(f) for f in FULL_FORMS)
    total_forms = cont_count + full_count
    contraction_ratio = (cont_count / total_forms) if total_forms > 0 else 0.5

    # 12. avg_sent_len
    sent_lengths = [len(_words(s)) for s in sentences]
    avg_sent_len = min(1.0, (sum(sent_lengths) / sent_count) / 30.0)

    # 13. sent_len_var
    mean_len  = sum(sent_lengths) / sent_count
    variance  = sum((l - mean_len) ** 2 for l in sent_lengths) / sent_count
    sent_len_var = min(1.0, math.sqrt(variance) / 20.0)

    # 14–24. tracked word frequencies
    freq = {tw: 0 for tw in TRACKED_WORDS}
    for w in lower_words:
        if w in freq:
            freq[w] += 1
    tracked = [min(1.0, (freq[tw] / word_count) * 20.0) for tw in TRACKED_WORDS]

    vector = [
        round(doc_len,           4),
        round(avg_word_len,      4),
        round(ttr,               4),
        round(func_word_ratio,   4),
        round(excl_rate,         4),
        round(quest_rate,        4),
        round(comma_per_word,    4),
        round(ellipsis_rate,     4),
        round(caps_i,            4),
        round(all_caps_ratio,    4),
        round(digit_ratio,       4),
        round(contraction_ratio, 4),
        round(avg_sent_len,      4),
        round(sent_len_var,      4),
    ] + [round(v, 4) for v in tracked]

    return vector  # 25 elements

# ─── Similarity ──────────────────────────────────────────────────────────────

def cosine_similarity(a: list[float], b: list[float]) -> float:
    if len(a) != len(b) or not a:
        return 0.0
    dot  = sum(x * y for x, y in zip(a, b))
    mag_a = math.sqrt(sum(x * x for x in a))
    mag_b = math.sqrt(sum(x * x for x in b))
    if mag_a == 0.0 or mag_b == 0.0:
        return 0.0
    return round(dot / (mag_a * mag_b), 4)

def similarity_label(sim: float) -> str:
    if sim >= 0.85: return 'VERY HIGH'
    if sim >= 0.70: return 'HIGH'
    if sim >= 0.55: return 'MODERATE'
    return 'LOW'

def similarity_colour(sim: float) -> str:
    """Return a hex colour for use in the UI."""
    if sim >= 0.85: return '#FF3E3E'
    if sim >= 0.70: return '#D4872A'
    if sim >= 0.55: return '#D4D400'
    return '#4EC994'
# ===== SNAPSMACK EOF =====
