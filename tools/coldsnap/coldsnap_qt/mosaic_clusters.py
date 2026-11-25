"""Find uniform clusters in the browser's measured mosaic geometry."""

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the canonical Python EOF marker below.

_TOLERANCE = 2


def _near(a, b):
    return abs(a - b) <= _TOLERANCE


def _same_size(a, b):
    return _near(a['width'], b['width']) and _near(a['height'], b['height'])


def uniform_clusters(cells, gap=4):
    """Return non-overlapping 2×2 blocks and runs of at least three tiles."""
    rows = []
    for cell in sorted(cells, key=lambda c: (c['y'], c['x'])):
        if not rows or not _near(rows[-1][0]['y'], cell['y']):
            rows.append([])
        rows[-1].append(cell)

    max_gap = max(0, gap) + _TOLERANCE
    clusters = []
    used = set()

    def record(items):
        ids = {cell['id'] for cell in items}
        if ids.isdisjoint(used):
            clusters.append(sorted(items, key=lambda item: item['id']))
            used.update(ids)

    for row in rows:
        row.sort(key=lambda cell: cell['x'])
        run = []
        for cell in row:
            if run and (not _same_size(run[0], cell)
                        or cell['x'] - run[-1]['x'] - run[-1]['width'] > max_gap):
                if len(run) >= 3:
                    record(run)
                run = []
            run.append(cell)
        if len(run) >= 3:
            record(run)

    for upper, lower in zip(rows, rows[1:]):
        for a, b in zip(upper, upper[1:]):
            if not _same_size(a, b) or b['x'] - a['x'] - a['width'] > max_gap:
                continue
            if not -_TOLERANCE <= lower[0]['y'] - a['y'] - a['height'] <= max_gap:
                continue
            for c, d in zip(lower, lower[1:]):
                if (_near(a['x'], c['x']) and _near(b['x'], d['x'])
                        and _same_size(a, c) and _same_size(a, d)):
                    record([a, b, c, d])
                    break
    return clusters


def break_cluster_weights(identities, weight_for):
    """Choose contrasting sizes without changing the authored order."""
    identities = list(dict.fromkeys(identities))
    lead = next((identity for identity in identities if weight_for(identity) < 2), None)
    changes = {} if lead is None else {lead: max(1, weight_for(lead) + 1)}
    # Promoting only one photo in four equal tiles can leave a new row of
    # three equal tiles. Give the other end of that cluster a different size.
    if len(identities) >= 4 or not changes:
        other = next((identity for identity in reversed(identities)
                      if identity != lead and weight_for(identity) > -1), None)
        if other is not None:
            changes[other] = weight_for(other) - 1
    return changes


# ===== SNAPSMACK EOF =====
