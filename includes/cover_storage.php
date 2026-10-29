<?php
declare(strict_types=1);
function coverStorageLock() {
    $dir=__DIR__.'/../storage/covers';
    if(!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir))throw new RuntimeException('Cannot create storage');
    $lock=fopen($dir.'/.write.lock','c');
    if(!$lock || !flock($lock,LOCK_EX|LOCK_NB))throw new DomainException('Another cover is being saved. Please try again.');
    chmod($dir.'/.write.lock',0600);return $lock;
}
/** Convert a verified, uploaded JPEG/PNG/WebP to a private JPEG. Caller holds coverStorageLock(). */
function storeUploadedCover(array $file): string {
    $tmp = $file['tmp_name'] ?? null;
    $size = $file['size'] ?? null;
    if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_int($size) ||
        $size < 1 || $size > 4 * 1024 * 1024 ||
        !is_string($tmp) || !is_uploaded_file($tmp)) {
        throw new DomainException('Choose a JPEG, PNG or WebP image up to 4 MB.');
    }
    $info = @getimagesize($tmp);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!$info || !in_array($mime, ['image/jpeg','image/png','image/webp'], true) ||
        $info[0] < 100 || $info[1] < 100 || $info[0] > 4096 || $info[1] > 4096) {
        throw new DomainException('Use a JPEG, PNG or WebP between 100 and 4096 pixels on each side.');
    }
    $bytes = file_get_contents($tmp);
    $source = $bytes === false ? false : @imagecreatefromstring($bytes);
    if (!$source) throw new DomainException('This image could not be read.');
    $ratio = min(1, 1600 / max($info[0], $info[1]));
    $width = (int) round($info[0] * $ratio);
    $height = (int) round($info[1] * $ratio);
    $destination = imagecreatetruecolor($width, $height);
    try {
        if (!imagecopyresampled($destination, $source, 0, 0, 0, 0, $width, $height, $info[0], $info[1])) {
            throw new RuntimeException('Cover resize failed.');
        }
        $dir = __DIR__ . '/../storage/covers/';
        $name = bin2hex(random_bytes(16)) . '.jpg';
        if (!imagejpeg($destination, $dir . $name, 88)) {
            if (is_file($dir . $name)) unlink($dir . $name);
            throw new RuntimeException('Cannot save cover image.');
        }
        chmod($dir . $name, 0600);
        return $name;
    } finally {
        imagedestroy($source);
        imagedestroy($destination);
    }
}

function checkCoverQuota(PDO $pdo, int $owner, ?string $incoming, ?string $replaced): void {
    global $config;
    if(!$incoming)return;
    $dir=__DIR__.'/../storage/covers/';$total=0;$owned=filesize($dir.$incoming);
    foreach(glob($dir.'*.jpg')?:[]as $path)$total+=filesize($path);
    $q=$pdo->prepare('SELECT image_path FROM events WHERE organiser_id=? AND image_path IS NOT NULL');$q->execute([$owner]);
    foreach($q->fetchAll(PDO::FETCH_COLUMN)as $name)if($name!==$replaced && preg_match('/^[a-f0-9]{32}\.jpg$/D',$name) && is_file($dir.$name))$owned+=filesize($dir.$name);
    if($total>(int)($config['cover_total_bytes']??536870912)||$owned>(int)($config['cover_owner_bytes']??52428800))throw new DomainException('Cover storage limit reached. Use a template or contact the administrator.');
}
/** Caller holds the shared storage lock. A filename may still be referenced by legacy data. */
function removeUnusedCover(PDO $pdo, ?string $name): void {
    if(!$name || !preg_match('/^[a-f0-9]{32}\.jpg$/D',$name))return;
    $q=$pdo->prepare('SELECT 1 FROM events WHERE image_path=? LIMIT 1');$q->execute([$name]);
    $path=__DIR__.'/../storage/covers/'.$name;
    if(!$q->fetchColumn() && is_file($path) && !unlink($path))error_log('Fairboard: cover cleanup failed');
}
