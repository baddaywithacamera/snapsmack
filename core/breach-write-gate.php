<?php
declare(strict_types=1);

/**
 * SECAUDIT 064 — a breached site refuses API writes.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 *
 * THE GAP THIS CLOSES. smackback_is_breach() is defined in core/smackback.php
 * and called in exactly two places: core/smackback.php itself and
 * smack-admin.php:34. Measured across the whole API surface, NOT ONE WRITE
 * ENDPOINT CHECKS IT. A site can know it has been tampered with, say so on its
 * dashboard, and go on accepting posts, comments, mosaics, skin reinstalls and
 * enrollments through the API as if nothing had happened. multisite-api.php
 * does reference breach state, but only to report it (:784), auto-resolve an
 * update (:2039) and receive a spoke's report (:2149) — none is a content
 * write.
 *
 * WHERE THE GATE LIVES, AND WHY NOT IN THE AUTH LAYER. There is no single auth
 * chokepoint: core/api-auth.php covers gyss, ohsnap, smackpress and flkrfckr,
 * while tyswy, threeacross and smackthemup each validate Bearer themselves and
 * multisite has its own key scheme. But there IS a single point in front of
 * all of them — api.php, which sees every request and already refuses some
 * before any surface loads (snap_api_site_scope_check, SECAUDIT 054). So the
 * gate is two calls, not six: the front door here, and one action-aware call
 * inside multisite-api.php where the action is known.
 *
 * NOT A PDO OR WRITE INTERCEPTOR, DELIBERATELY. tyswy performs three writes
 * inside its own AUTHENTICATION path — the site uuid insert, last_used_at on
 * the key, and the rate-limit row — and all three are wrapped in try/catch
 * that swallows failure by design. A write-level gate would catch them, they
 * would fail exactly as written, silently, and a key's last_used_at would stop
 * updating with nothing in any log. A request-level refusal cannot do that.
 *
 * DEFAULT IS GATED. The front door gates every non-read route except the two
 * named exemptions, rather than gating a list of known write surfaces. A write
 * surface added next year is then refused on a breached site by default
 * instead of being forgotten, which is the failure mode a list produces.
 *
 * READS STAY OPEN. SMACK CENTRAL's job is to inform, and the hub's fleet
 * picture depends on reading a spoke's status. Locking reads blinds the thing
 * that tells the owner they have a problem.
 *
 * KNOWN LIMIT, RULED ON RATHER THAN OVERLOOKED. Because the refusal is emitted
 * only when an Authorization header is present, a caller holding any
 * well-formed fake Bearer can tell 423 from 401 and so learn that a site is in
 * breach. That is a breach oracle. The PM weighed it on 2026-11-11 and
 * accepted it: breach state is not otherwise public, a breached site is the
 * harder target anyway, and the alternative — moving the gate into each
 * surface's auth layer — costs six call sites and the single chokepoint. It is
 * recorded here so nobody "fixes" it later without knowing it was decided.
 */

/** The contract. Desktop tools key on the string, so it never changes. */
const SNAP_BREACH_GATE_STATUS = 423;
const SNAP_BREACH_GATE_CODE   = 'breach_write_lock';
const SNAP_BREACH_GATE_REASON =
    'This site has an unresolved file-integrity breach. Writes are refused '
    . 'until it is cleared. Reading still works, and so does a signed update.';

/**
 * Three states, not two.
 *
 * 'breach'  — the row says so. Refuse.
 * 'clear'   — the row is missing, empty or says anything else. Allow. An
 *             uninitialised site has never been checked; it is not breached.
 * 'unknown' — the read itself failed. Refuse.
 *
 * THE BRICKING CASE IS IMPLEMENTING 'unknown' AS 'clear'. smackback_is_breach()
 * returns false for a missing row AND would for an unreadable one if a caller
 * wrapped it in a try/catch — the two are indistinguishable through a bool.
 * They are opposite answers: a site that has never been checked must keep
 * working, and a site whose integrity state cannot be read must not be trusted
 * to accept writes. Collapsing them in the safe direction leaves the gate open
 * exactly when the database is the thing misbehaving.
 *
 * The counter-argument considered and rejected: "if snap_settings is
 * unreadable every write path is dead anyway, so refusing adds nothing." Not
 * reliably — a partial failure, a permissions change on one table, or a
 * replica lag can make this one SELECT fail while writes still land.
 */
function snap_breach_write_state(PDO $pdo): string
{
    try {
        $st = $pdo->prepare(
            "SELECT setting_val FROM snap_settings WHERE setting_key = 'smackback_status'"
        );
        $st->execute();
        $value = $st->fetchColumn();
    } catch (Throwable $e) {
        error_log('SECAUDIT 064: breach state unreadable, refusing writes — ' . $e->getMessage());
        return 'unknown';
    }
    return ($value === 'breach') ? 'breach' : 'clear';
}

/** A state that must refuse. 'clear' is the only one that may write. */
function snap_breach_gate_refuses(string $state): bool
{
    return $state !== 'clear';
}

/**
 * Is this request carrying an Authorization header at all?
 *
 * The three-way lookup is not belt and braces: nginx and PHP-FPM strip
 * HTTP_AUTHORIZATION, which is why multisite-api.php:75-78 already falls back
 * to getallheaders(). A gate reading only $_SERVER['HTTP_AUTHORIZATION'] would
 * see no key on those hosts and take the wrong branch — staying silent exactly
 * where the fleet actually runs.
 */
function snap_breach_gate_authorization_present(?array $server = null): bool
{
    $server = $server ?? $_SERVER;
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
        if (trim((string) ($server[$key] ?? '')) !== '') return true;
    }
    if (function_exists('getallheaders')) {
        foreach ((array) getallheaders() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0
                && trim((string) $value) !== '') {
                return true;
            }
        }
    }
    return false;
}

/** A method that only reads. Reads are never gated. */
function snap_breach_gate_is_read(string $method): bool
{
    return in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true);
}

/**
 * Front-door exemptions, by route prefix.
 *
 * 'multisite' is NOT exempt from the gate — it is exempt from THIS gate,
 * because its own call inside multisite-api.php knows the action and this one
 * does not. Gating it here would refuse the signed update and a spoke's breach
 * report, which must both keep working on a breached site.
 *
 * 'stats' is the public, unauthenticated dwell beacon. It writes no content,
 * it must always answer 204 so navigator.sendBeacon() does not block page
 * unload, and the only value it touches is one a visitor can already inflate
 * for their own hit by design (SS-1083). Gating it would add a refusal path to
 * a route that cannot carry one, for nothing.
 */
function snap_breach_gate_front_door_exempt(string $route): bool
{
    foreach (['multisite', 'stats'] as $prefix) {
        if (strpos($route, $prefix) === 0) return true;
    }
    if (snap_breach_gate_desktop_auth_exempt($route)) return true;
    return false;
}

/**
 * desktop-auth, split by action. PM ruling, 2026-11-11.
 *
 * EXEMPT status and renew. They share one branch at core/desktop-auth.php:218
 * and together are the KEEP-ALIVE PATH for a device that is already trusted.
 * `status` is the call a running installation makes on its own, and inside the
 * last 30 days of the term it renews it (:223). Gating it does not merely
 * block a status check: the auto-renewal never fires, the device ages through
 * its term and its 14-day grace, and the signed entitlement lapses --
 * capabilities snap_slapper_full and lewk_again_full with it. A device already
 * near term end expires within DAYS of a breach starting, and SNAP SLAPPER has
 * been the only image source since 2026-08-28. A gate that takes the owner's
 * editing tool away while they are repairing their site is the same mistake as
 * gating run-crons, in a different place.
 *
 * GATED: activate and enroll. Those add a NEW device to a site known to be
 * tampered with, which is what an attacker would want and not something the
 * owner needs mid-breach.
 *
 * THE HONEST COST of exempting, recorded rather than glossed: a holder of
 * valid device credentials on a breached site can still bump last_used_at,
 * last_ip, the device metadata and the term. That is a trusted-device write,
 * not new access, and it was weighed against the editing tool going dark.
 *
 * LOWERCASED, because the dispatcher does (:131-132). Without it a request to
 * /desktop-auth/STATUS would be refused here and treated as `status` there --
 * the gate and the code it guards disagreeing about the same request.
 */
function snap_breach_gate_desktop_auth_exempt(string $route): bool
{
    if (strpos($route, 'desktop-auth') !== 0) return false;
    $action = strtolower(basename(trim($route, '/')));
    return in_array($action, ['status', 'renew'], true);
}

/**
 * Multisite actions that must still work while a site is breached.
 *
 * updates/*        — THE WAY OUT. A signed update is how the owner repairs a
 *                    breached install, so the gate must never stand in front
 *                    of it. The auto-resolve at multisite-api.php:2038-2041 is
 *                    conditional on a verified package AND the zip being
 *                    present; that conditionality is untouched here, so a
 *                    vanished package still cannot clear a breach.
 * smackback/report — THE ALARM. A breached spoke must still be able to tell
 *                    the hub, and the hub must still receive reports when the
 *                    hub itself is locked down. It writes hub-side node rows
 *                    only; a spoke's own state lives in its own snap_settings,
 *                    so a hub cannot set this gate on a spoke.
 * run-crons        — THE HOUSEKEEPING, and the one that could take the fleet
 *                    down. tests/multisite-endpoint-role-contract.php:143
 *                    records it as "the LAST ungated write (SS-1055)" and says
 *                    plainly that CRONOMETER drives it, which key it presents
 *                    is UNCONFIRMED, and "a role gate in the wrong place takes
 *                    fleet cron down". CRONOMETER has been blind for 34+ days
 *                    on this fleet before. On a breached site cron is what
 *                    still delivers, still backs up and still heartbeats — the
 *                    housekeeping you least want silenced on the site that is
 *                    in trouble. It writes tick timestamps to snap_settings.
 *                    Exempting it leaves that one write open; gating it risks
 *                    stopping the fleet's cron. The PM weighed both on
 *                    2026-11-11 and ruled: exempt, named explicitly.
 *
 * It is also METHOD-AGNOSTIC — multisite-api.php:541 is a bare
 * `$resource === 'run-crons'` with no method check — so a method-based gate
 * would miss it entirely. Named here rather than relied on to be a POST.
 */
function snap_breach_gate_multisite_exempt(string $resource, string $sub_action): bool
{
    if ($resource === 'run-crons') return true;
    // RESOURCE-WIDE on purpose, and the consequence is stated rather than
    // discovered later: a future updates/* sub-action is exempt the day it
    // is added, without anyone revisiting this list. That is the right
    // default for the one path that repairs a breached install, but it is a
    // standing permission, not an oversight. Today the resource holds only
    // status, trigger, finalize and track.
    if ($resource === 'updates') return true;
    if ($resource === 'smackback' && $sub_action === 'report') return true;
    return false;
}

/** Emit the refusal and stop. Shape matches tyswy_error()'s envelope. */
function snap_breach_gate_refuse(): void
{
    if (!headers_sent()) {
        http_response_code(SNAP_BREACH_GATE_STATUS);
        header('Content-Type: application/json');
    }
    echo json_encode([
        'ok'    => false,
        'error' => [
            'code'      => SNAP_BREACH_GATE_CODE,
            'message'   => SNAP_BREACH_GATE_REASON,
            'retryable' => true,
        ],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Decide, without emitting anything. Pure, so the whole decision can be
 * tested without a request, a database or a site.
 */
function snap_breach_gate_decides_refusal(
    string $state,
    string $method,
    bool $authorization_present,
    bool $exempt
): bool {
    if (snap_breach_gate_is_read($method)) return false;
    if ($exempt) return false;
    if (!$authorization_present) return false;   // the caller learns nothing new
    return snap_breach_gate_refuses($state);
}

/** The front door. Called from api.php before any surface is required. */
function snap_breach_write_gate(PDO $pdo, string $route, string $method): void
{
    if (snap_breach_gate_is_read($method)) return;
    if (snap_breach_gate_front_door_exempt($route)) return;
    if (!snap_breach_gate_authorization_present()) return;
    if (snap_breach_gate_refuses(snap_breach_write_state($pdo))) {
        snap_breach_gate_refuse();
    }
}

/** The multisite call. Called once the action is parsed. */
function snap_breach_write_gate_multisite(
    PDO $pdo,
    string $resource,
    string $sub_action,
    string $method
): void {
    if (snap_breach_gate_is_read($method)) return;
    if (snap_breach_gate_multisite_exempt($resource, $sub_action)) return;
    if (!snap_breach_gate_authorization_present()) return;
    if (snap_breach_gate_refuses(snap_breach_write_state($pdo))) {
        snap_breach_gate_refuse();
    }
}

// ===== SNAPSMACK EOF =====
