<?php
require __DIR__ . '/includes/bootstrap.php';
requireLogin();
if (!isRole('creator') && !isRole('staff')) {
    http_response_code(403);
    exit('You do not have permission to access this page.');
}
$event = null;
$errors = [];
$id = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = filter_var(postString('event_id', '0'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 4294967295]]);
    if ($id === false) {
        http_response_code(400);
        exit('Invalid identifier.');
    }
} elseif (isset($_GET['id'])) {
    $id = filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
    if ($id === false) {
        http_response_code(400);
        exit('Invalid identifier.');
    }
}
if ($id) {
    $statement = $pdo->prepare('SELECT * FROM events WHERE event_id=?');
    $statement->execute([$id]);
    $event = $statement->fetch();
    if (!$event || !canEditEvent($pdo, $event)) {
        http_response_code(403);
        exit('You cannot edit this event.');
    }
}
if (!$id && !isRole('creator')) {
    http_response_code(403);
    exit('Staff can edit an assigned event, not create a new one.');
}
$back = isRole('staff') ? 'staff.php' : 'dashboard.php';
$values = $event ?: [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireVerifiedEmail();
    enforceActionLimit($pdo, $id ? 'event-edit' : 'event-create', $id ? 30 : 10);
    $title = trim(postString('title'));
    $category = postString('category');
    $artworkChoice = postString('artwork');
    $uploadSelected = $artworkChoice === 'upload';
    // Keep the illustration identifier valid: "upload" is only a UI choice.
    $artwork = $uploadSelected ? ($event['artwork'] ?? categoryArtwork($category)) : $artworkChoice;
    $intent = postString('intent', 'submit');
    $socialUrl = trim(postString('social_url'));
    $description = trim(postString('description'));
    $start = trim(postString('start_time'));
    $location = trim(postString('location'));
    $capacityInput = postString('capacity');
    $capacity = filter_var($capacityInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
    $date = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $start) === 1
        ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $start) : false;
    $values = ['title' => $title, 'category' => $category, 'artwork' => $artwork, 'artwork_choice' => $artworkChoice,
        'description' => $description, 'start_time' => $start, 'location' => $location,
        'capacity' => $capacityInput, 'social_url' => $socialUrl];
    $hasUpload = isset($_FILES['cover']) && ($_FILES['cover']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if (!in_array($intent, ['submit', 'save_draft', 'save'], true)) $errors[] = 'Choose a valid save action.';
    if ($intent === 'save_draft' && !isRole('creator')) $errors[] = 'Only the creator can save a draft.';
    if (!validEventUrl($socialUrl)) $errors[] = 'Event link must be an HTTP or HTTPS URL of up to 500 characters.';
    if (!in_array($category, categories(), true) || !in_array($artwork, artworkChoices(), true) ||
        !in_array($artworkChoice, [...artworkChoices(), 'upload'], true)) $errors[] = 'Choose a valid category and artwork.';
    if ($uploadSelected && !$hasUpload && empty($event['image_path'])) {
        $errors[] = 'Choose an image to upload, or select an illustrated cover.';
    }
    if ($title === '' || !mb_check_encoding($title, 'UTF-8') || mb_strlen($title, 'UTF-8') > 150) $errors[] = 'Enter a title of up to 150 characters.';
    if ($description === '' || !mb_check_encoding($description, 'UTF-8') || strlen($description) > 65535) $errors[] = 'Enter a description of up to 65,535 bytes.';
    if (!$date || $date->format('Y-m-d\TH:i') !== $start || $date->getTimestamp() <= time()) $errors[] = 'Choose a valid future date and time.';
    if ($location === '' || !mb_check_encoding($location, 'UTF-8') || mb_strlen($location, 'UTF-8') > 200) $errors[] = 'Enter a location of up to 200 characters.';
    if ($capacity === false) $errors[] = 'Capacity must be between 1 and 100,000.';

    if (!$errors) {
        require_once __DIR__ . '/includes/cover_storage.php';
        $lock = null; $uploadedImage = null; $oldImage = null; $saved = false; $fatalError = null;
        try {
            if (($uploadSelected && $hasUpload) || ($event && !$uploadSelected && !empty($event['image_path']))) {
                $lock = coverStorageLock();
            }
            if ($uploadSelected && $hasUpload) $uploadedImage = storeUploadedCover($_FILES['cover']);
            if ($event) {
                $oldImage = withEventLock($pdo, $id, function ($lockedEvent) use (
                    $pdo, $id, $title, $description, $date, $location, $capacity,
                    $category, $artwork, $uploadSelected, $uploadedImage, $intent, $socialUrl
                ): ?string {
                    if (!$lockedEvent || !canEditEvent($pdo, $lockedEvent)) throw new BookingAccessDenied();
                    $info = eventCapacityInfo($pdo, $id);
                    if ($capacity < $info['bookings']) throw new CapacityBelowBookings();
                    $newImage = $uploadSelected ? ($uploadedImage ?? $lockedEvent['image_path']) : null;
                    if ($uploadSelected && !$newImage) throw new DomainException('Choose a cover image before saving.');
                    if ($uploadedImage) checkCoverQuota($pdo, (int)$lockedEvent['organiser_id'], $uploadedImage, $lockedEvent['image_path']);
                    $changed = $title !== $lockedEvent['title'] ||
                        $description !== $lockedEvent['description'] ||
                        $location !== $lockedEvent['location'] ||
                        $date->format('Y-m-d H:i:s') !== $lockedEvent['start_time'] ||
                        $socialUrl !== (string)($lockedEvent['social_url'] ?? '') ||
                        $category !== $lockedEvent['category'] ||
                        $artwork !== $lockedEvent['artwork'] ||
                        $newImage !== $lockedEvent['image_path'];
                    $status = $intent === 'save_draft' ? 'draft' :
                        (($changed || (in_array($lockedEvent['status'], ['draft','rejected','cancelled'], true) && isRole('creator')))
                            ? 'pending' : $lockedEvent['status']);
                    $stmt = $pdo->prepare('UPDATE events SET title=?,description=?,start_time=?,location=?,capacity=?,category=?,artwork=?,social_url=?,status=?,image_path=?,poster_path=NULL WHERE event_id=?');
                    $stmt->execute([$title, $description, $date->format('Y-m-d H:i:s'), $location,
                        $capacity, $category, $artwork, $socialUrl ?: null, $status, $newImage, $id]);
                    $updated = $lockedEvent; $updated['capacity'] = $capacity; $updated['status'] = $status;
                    promoteWaiting($pdo, $updated, max(0, $capacity - (int)$lockedEvent['capacity']));
                    if ($changed) {
                        $q = $pdo->prepare('SELECT user_id FROM bookings WHERE event_id=?');
                        $q->execute([$id]);
                        foreach ($q->fetchAll() as $u) {
                            notifyUser($pdo, (int)$u['user_id'],
                                'Event details changed and are awaiting approval: ' . $title, $id);
                        }
                    }
                    audit($pdo, 'event.edit', $id);
                    return $lockedEvent['image_path'];
                });
            } else {
                $pdo->beginTransaction();
                $q = $pdo->prepare("SELECT user_id FROM users WHERE user_id=? AND is_active=1 AND role='creator' AND email_verified_at IS NOT NULL FOR UPDATE");
                $q->execute([currentUser()['user_id']]);
                if (!$q->fetchColumn()) throw new BookingAccessDenied();
                if ($uploadedImage) checkCoverQuota($pdo, (int)currentUser()['user_id'], $uploadedImage, null);
                $stmt = $pdo->prepare("INSERT INTO events (organiser_id,title,description,start_time,location,capacity,original_capacity,category,artwork,social_url,status,image_path) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([
                    currentUser()['user_id'], $title, $description, $date->format('Y-m-d H:i:s'),
                    $location, $capacity, $capacity, $category, $artwork, $socialUrl ?: null,
                    $intent === 'save_draft' ? 'draft' : 'pending', $uploadedImage
                ]);
                audit($pdo, 'event.create', (int)$pdo->lastInsertId());
                $pdo->commit();
            }
            $saved = true;
            $uploadedImage = null;
            if ($lock && $oldImage) removeUnusedCover($pdo, $oldImage);
        } catch (CapacityBelowBookings $exception) {
            $errors[] = 'Capacity cannot be below confirmed bookings.';
        } catch (BookingAccessDenied $exception) {
            http_response_code(403);
            $errors[] = 'You cannot edit this event.';
        } catch (DomainException $exception) {
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            $fatalError = $exception;
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($uploadedImage) removeUnusedCover($pdo, $uploadedImage);
            if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        }
        if ($fatalError) serverError($fatalError);
        if ($saved) {
            flash($intent === 'save_draft' ? 'Draft saved.' : ($event ? 'Event updated.' : 'Event created and submitted for approval.'));
            redirect($back);
        }
    }
}
$pageTitle = $event ? 'Edit event' : 'Create event';
require __DIR__ . '/includes/header.php';
$startValue = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($values['start_time'] ?? '') : (isset($values['start_time']) ? date('Y-m-d\TH:i', strtotime($values['start_time'])) : '');
?>
<div class="form-panel"><p class="eyebrow">BRING PEOPLE TOGETHER</p><h1><?= e($pageTitle) ?>.</h1>
<?php if (!$event): ?><p class="notice">Save a private draft, or submit the event for administrator approval.</p><?php endif; ?>
<?php if ($event && isRole('staff')): ?><p class="notice">You are editing this event for the assigned creator.</p><?php endif; ?>
<form method="post" class="panel" enctype="multipart/form-data" data-event-cover-form>
  <?= csrfField() ?>
  <?php if ($event): ?><input type="hidden" name="event_id" value="<?= (int) $event['event_id'] ?>"><?php endif; ?>
  <?php foreach ($errors as $error): ?><p class="flash error"><?= e($error) ?></p><?php endforeach; ?>
  <label for="title">Event title</label><input id="title" name="title" required maxlength="150" value="<?= e($values['title'] ?? '') ?>">
  <label for="description">Description</label><textarea id="description" name="description" required><?= e($values['description'] ?? '') ?></textarea>
  <label for="start_time">Date and time</label><input id="start_time" type="datetime-local" name="start_time" required value="<?= e($startValue) ?>">
  <label for="location">Location</label><input id="location" name="location" required maxlength="200" value="<?= e($values['location'] ?? '') ?>">
  <label for="capacity">Capacity</label><input id="capacity" type="number" min="1" max="100000" name="capacity" required value="<?= e((string) ($values['capacity'] ?? '')) ?>">
  <label for="category">Category</label><select id="category" name="category"><?php foreach(categories() as $cat):?><option value="<?=e($cat)?>" <?=($values['category']??'Community')===$cat?'selected':''?>><?=e($cat)?></option><?php endforeach;?></select>
  <label for="social_url">Event website or social link (optional)</label><input id="social_url" type="url" name="social_url" maxlength="500" value="<?=e($values['social_url']??'')?>" placeholder="https://">
  <?php $selectedCover = $_SERVER['REQUEST_METHOD'] === 'POST'
      ? ($values['artwork_choice'] ?? 'city')
      : (!empty($event['image_path']) ? 'upload' : ($values['artwork'] ?? 'city')); ?>
  <label id="artwork-label">Choose cover artwork</label>
  <div class="artwork-options" role="group" aria-labelledby="artwork-label">
    <?php foreach (artworkChoices() as $art): if ($art === 'other') continue; ?>
      <label class="artwork-choice">
        <input type="radio" name="artwork" value="<?= e($art) ?>" <?= $selectedCover === $art ? 'checked' : '' ?>>
        <img src="<?= e(artworkPath($art)) ?>" alt="">
        <span><?= e(artworkLabel($art)) ?></span>
      </label>
    <?php endforeach; ?>
    <?php // Preserve older events that still use the Abstract illustration. ?>
    <?php if ($selectedCover === 'other'): ?>
      <input type="radio" name="artwork" value="other" checked hidden>
    <?php endif; ?>
    <div class="artwork-choice upload-artwork-choice">
      <input id="artwork-upload-radio" type="radio" name="artwork" value="upload" <?= $selectedCover === 'upload' ? 'checked' : '' ?> aria-label="Upload your own image">
      <button type="button" class="upload-artwork-trigger" data-artwork-upload-trigger aria-label="Choose an image to upload">
        <span class="upload-artwork-icon" data-artwork-upload-placeholder aria-hidden="true">
          <svg width="42" height="42" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <rect x="7" y="7" width="34" height="34" rx="7"/>
            <path d="M24 33V17m-7 7 7-7 7 7"/>
          </svg>
          <small>JPEG / PNG / WebP</small>
        </span>
        <img class="upload-artwork-image" data-artwork-upload-preview
             <?php if (!empty($event['image_path'])): ?>src="<?= e(eventArtwork($event)) ?>"<?php else: ?>hidden<?php endif; ?>
             alt="Uploaded cover preview">
        <span class="upload-artwork-hint" data-artwork-upload-hint>Choose image</span>
      </button>
      <span>Upload</span>
    </div>
  </div>
  <input id="artwork-file" name="cover" type="file" accept="image/jpeg,image/png,image/webp" hidden>
  <p class="fine-print" data-artwork-upload-feedback aria-live="polite">Choose an illustrated cover or use Upload to select your own image (JPEG, PNG or WebP, max 4 MB, 100–4096 pixels). Changes to event details are sent for approval.</p>
  <div class="form-actions"><?php if(isRole('creator')):?><button class="secondary" name="intent" value="save_draft">Save draft</button><button name="intent" value="submit">Submit for review</button><?php else:?><button name="intent" value="save">Save assigned event</button><?php endif;?><a class="button ghost" href="<?= e($back) ?>">Cancel</a></div>
</form></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
