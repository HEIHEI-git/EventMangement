<?php
require_once __DIR__ . '/role_access.php';
function e(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function isLoggedIn(): bool { return isset($_SESSION['user']); }
function currentUser(): ?array { return $_SESSION['user'] ?? null; }
function isRole(string $role): bool { return isLoggedIn() && currentUser()['role'] === $role; }
function requireLogin(): void { if (!isLoggedIn()) { flash('Please log in to continue.', 'error'); redirect('login.php' . (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'scan.php' ? '?next=scan.php' : '')); } }
function requireRole(string $role): void { requireLogin(); if (!isRole($role)) { securityEvent('access.role_denied'); http_response_code(403); exit('You do not have permission to access this page.'); } }
function redirect(string $path): never { header('Location: ' . $path); exit; }
function flash(string $message, string $type = 'success'): void { $_SESSION['flash'][] = ['message' => $message, 'type' => $type]; }
function showFlashes(): void { foreach ($_SESSION['flash'] ?? [] as $item) { echo '<div class="flash ' . e($item['type']) . '" role="status">' . e($item['message']) . '</div>'; } unset($_SESSION['flash']); }
function eventIsAccessible(array $event): bool { return $event['status'] === 'approved' || isRole('admin') || (isRole('creator') && (int) currentUser()['user_id'] === (int) $event['organiser_id']); }
function eventCapacityInfo(PDO $pdo, int $eventId): array {
    $statement = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE event_id = ?');
    $statement->execute([$eventId]); $bookings = (int) $statement->fetchColumn();
    $statement = $pdo->prepare('SELECT COUNT(*) FROM waiting_list WHERE event_id = ?');
    $statement->execute([$eventId]); $waiting = (int) $statement->fetchColumn();
    return ['bookings' => $bookings, 'waiting' => $waiting];
}
function isEventOwnerOrAdmin(array $event): bool { return isRole('admin') || (isRole('creator') && (int) currentUser()['user_id'] === (int) $event['organiser_id']); }
function staffAssistsCreator(PDO $pdo, int $creatorId): bool {
    if (!isRole('staff')) {
        return false;
    }
    $statement = $pdo->prepare('SELECT 1 FROM staff_assignments WHERE staff_user_id = ? AND creator_user_id = ?');
    $statement->execute([(int) currentUser()['user_id'], $creatorId]);
    return (bool) $statement->fetchColumn();
}
function canEditEvent(PDO $pdo, array $event): bool {
    if (!isLoggedIn()) return false;
    // Recheck inside the event transaction so a concurrent deletion cannot revive an event.
    $q=$pdo->prepare('SELECT role FROM users WHERE user_id=? AND is_active=1 AND auth_version=?'.($pdo->inTransaction()?' FOR UPDATE':''));
    $q->execute([currentUser()['user_id'], $_SESSION['auth_version'] ?? 0]);
    if ($q->fetchColumn() !== currentUser()['role']) return false;
    if (isRole('creator') && (int) currentUser()['user_id'] === (int) $event['organiser_id']) {
        return true;
    }
    return staffAssistsCreator($pdo, (int) $event['organiser_id']);
}
function safeEventNext(mixed $value): ?string {
    if (is_string($value) && preg_match('/^event\.php\?id=([1-9][0-9]*)$/', $value, $match)) {
        return 'event.php?id=' . $match[1];
    }
    return $value === 'scan.php' ? 'scan.php' : null;
}
function loginRedirectFor(array $user, ?string $next): string {
    if (!empty($user['must_change_password'])) return 'change_initial_password.php';
    if ($next === 'scan.php' && in_array($user['role'] ?? '', ['creator','staff'], true)) return $next;
    if (($user['role'] ?? '') === 'user' && $next) {
        return $next;
    }
    return roleHome((string) ($user['role'] ?? 'user'));
}
function planFeeLabel(?string $plan): string {
    if ($plan === 'verified_society') {
        return 'Verified society · no monthly fee';
    }
    if ($plan === 'external_monthly') {
        return 'External group · HK$80 a month';
    }
    return '';
}
function posterTone(string $title): int { return (int) (abs(crc32($title)) % 6); }
function eventCardSql(): string {
    return 'SELECT e.event_id, e.organiser_id, e.title, e.description, e.start_time, e.location, e.capacity, e.status, e.category, e.artwork, e.image_path, e.original_capacity, e.refill_count, e.poster_path, e.social_url, u.name AS organiser_name, p.organisation_name, p.plan_code, (SELECT COUNT(*) FROM bookings b WHERE b.event_id = e.event_id) AS booking_count, (SELECT COUNT(*) FROM waiting_list w WHERE w.event_id = e.event_id) AS waiting_count FROM events e JOIN users u ON u.user_id = e.organiser_id LEFT JOIN creator_plans p ON p.user_id = e.organiser_id';
}
function placesLeft(array $event): int { return max(0, (int) $event['capacity'] - (int) $event['booking_count']); }
function organisationName(array $event): string {
    $name = trim((string) ($event['organisation_name'] ?? ''));
    return $name !== '' ? $name : (string) $event['organiser_name'];
}

function canManageAttendees(PDO $pdo, array $event): bool {
    return canEditEvent($pdo,$event);
}
function categories(): array { return ['Music','Film','Arts','Technology','Community','Outdoors','Learning','Sports','Social','Other']; }
function artworkChoices(): array { return ['concert','cinema','gallery','digital','city','nature','learning','sports','social','other']; }
/** Default illustration retained in the database when a custom cover is uploaded. */
function categoryArtwork(string $category): string {
    return match ($category) {
        'Music' => 'concert',
        'Film' => 'cinema',
        'Arts' => 'gallery',
        'Technology' => 'digital',
        'Community' => 'city',
        'Outdoors' => 'nature',
        'Learning' => 'learning',
        'Sports' => 'sports',
        'Social' => 'social',
        'Other' => 'other',
        default => 'city',
    };
}
function artworkLabel(string $art): string {
    return match ($art) {
        'concert' => 'Concert',
        'cinema' => 'Cinema',
        'gallery' => 'Gallery',
        'digital' => 'Digital',
        'city' => 'City',
        'nature' => 'Nature',
        'learning' => 'Study',
        'sports' => 'Court',
        'social' => 'Gathering',
        'other' => 'Abstract',
        default => 'Concert',
    };
}
function eventArtwork(array $event): string {
    if (!empty($event['image_path'])) return 'media.php?image=' . rawurlencode($event['image_path']);
    if (!empty($event['poster_path']) && preg_match('~^uploads/([a-f0-9]{32}\.(?:jpg|png|webp))$~D',$event['poster_path'],$m)) return 'media.php?poster='.rawurlencode($m[1]);
    $art = $event['artwork'] ?? 'concert';
    return artworkPath($art);
}
function validEventUrl(string $url): bool { return $url==='' || (strlen($url)<=500 && filter_var($url,FILTER_VALIDATE_URL) && in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['http','https'],true)); }
function notifyUser(PDO $pdo, int $userId, string $message, ?int $eventId = null): void {
    $pdo->prepare('INSERT INTO notifications (user_id,message,event_id) VALUES (?,?,?)')->execute([$userId,$message,$eventId]);
}
function schoolEmail(string $email): bool {
    global $config;
    $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
    return in_array($domain, array_map('strtolower', $config['school_email_domains'] ?? []), true);
}

function artworkPath(string $art): string {
    if(!in_array($art,artworkChoices(),true))$art='concert';
    return 'assets/art/'.$art.'.svg';
}
