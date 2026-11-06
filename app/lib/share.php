<?php
declare(strict_types=1);

/*
 * Share links: a link to one version, for someone outside the team (a producer, a broadcaster, a composer).
 *
 * Opening the link asks for their name, then shows that version in the view the link was made with (Review, Script
 * or Playback). They can read the notes and, if the link allows it, add notes and replies under their name; those
 * carry the link's id, so the team sees they came from a guest. They see nothing else: no other version or film,
 * no people, no settings. Editors make links and can turn them off at any time.
 *
 * The token is the whole secret, so it's long and random; it's kept so the link can be copied again later.
 */

const SHARE_VIEWS = ['review' => 'Review', 'script' => 'Script', 'playback' => 'Playback'];

/** The actions a guest may use. Each one is also checked against the link's film and version in api.php. */
const GUEST_ACTIONS = [
    'GET state', 'GET notes', 'GET transcript', 'GET script', 'GET version.media', 'GET transcribe.status',
    'POST note.create', 'POST note.reply', 'POST note.edit',
];

/** A link that still works, or null. */
function share_by_token(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{40}$/', $token)) {
        return null;
    }
    $st = db()->prepare('SELECT s.*, v.label AS version_label, f.name AS film_name FROM shares s
        JOIN versions v ON v.id = s.version_id JOIN films f ON f.id = s.film_id
        WHERE s.token = ? AND s.revoked_at IS NULL AND (s.expires_at IS NULL OR s.expires_at > ?)');
    $st->execute([$token, time()]);
    return $st->fetch() ?: null;
}

/** The name the guest gave for this link in this browser, if any. */
function share_guest_name(array $share): ?string
{
    $n = $_SESSION['guests'][(int)$share['id']] ?? null;
    return is_string($n) && $n !== '' ? $n : null;
}

function share_set_guest_name(array $share, string $name): void
{
    $_SESSION['guests'][(int)$share['id']] = $name;
    db()->prepare('UPDATE shares SET uses = uses + 1, last_used_at = ? WHERE id = ?')->execute([now_iso(), $share['id']]);
}

/** A stand-in account for a guest, so the rest of the API can treat them like a reviewer (or viewer). */
function guest_account(array $share, string $name): array
{
    return ['id' => 0, 'name' => $name, 'email' => '', 'role' => (int)$share['can_comment'] ? 'reviewer' : 'viewer', 'status' => 'active',
        'last_login' => null, 'created_at' => $share['created_at'], 'token_hash' => null, 'token_expires' => null, 'token_kind' => null,
        'locked_until' => 0, 'guest' => true, 'share_id' => (int)$share['id']];
}

/** A link for the team's list (with the link itself, so it can be copied again). */
function share_public(array $s): array
{
    return ['id' => (int)$s['id'], 'link' => app_base_url() . '?share=' . $s['token'], 'view' => $s['view'], 'can_comment' => (bool)$s['can_comment'],
        'label' => $s['label'], 'created_by' => $s['created_by'], 'created_at' => $s['created_at'],
        'expires_at' => $s['expires_at'] ? gmdate('Y-m-d\TH:i:s\Z', (int)$s['expires_at']) : null,
        'last_used_at' => $s['last_used_at'], 'uses' => (int)$s['uses']];
}

/** A guest's name: what they type, tidied, up to 60 characters. */
function clean_guest_name(string $name): string
{
    $name = trim((string)preg_replace('/\s+/u', ' ', $name));
    return mb_substr($name, 0, 60);
}
