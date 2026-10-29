<?php
require __DIR__.'/includes/bootstrap.php';requireLogin();$id=queryId();$q=$pdo->prepare('SELECT * FROM events WHERE event_id=?');$q->execute([$id]);$event=$q->fetch();if(!$event||!canEditEvent($pdo,$event))abortRequest(403,'You cannot change this event artwork.');$error='';require_once __DIR__.'/includes/cover_storage.php';
if($_SERVER['REQUEST_METHOD']==='POST'){
 $retry=consumeAuthLimits($pdo,[authBucketKey('artwork-user',(string)currentUser()['user_id'])=>20]);if($retry){header('Retry-After: '.$retry);abortRequest(429,'Too many artwork changes. Try again later.');}
 $art=postString('artwork');if(!in_array($art,artworkChoices(),true))abortRequest(400,'Choose valid artwork.');$image=null;$lock=null;
 try{
  $lock=coverStorageLock();
  if(isset($_FILES['cover'])&&($_FILES['cover']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
   $image=storeUploadedCover($_FILES['cover']);
  }
  $old=withEventLock($pdo,$id,function($locked)use($pdo,$id,$art,$image){if(!$locked||!canEditEvent($pdo,$locked))throw new BookingAccessDenied();checkCoverQuota($pdo,(int)$locked['organiser_id'],$image,$locked['image_path']);$pdo->prepare('UPDATE events SET artwork=?,image_path=?,poster_path=NULL WHERE event_id=?')->execute([$art,$image,$id]);audit($pdo,'event.artwork',$id);return $locked['image_path'];});
  $image=null;removeUnusedCover($pdo,$old);flock($lock,LOCK_UN);fclose($lock);$lock=null;
  flash('Cover saved. The event page and printed poster now use this artwork.');redirect('photo.php?id='.$id);
 }catch(DomainException $e){$error=$e->getMessage();}catch(BookingAccessDenied $e){$error='Access changed.';http_response_code(403);}finally{if($image)removeUnusedCover($pdo,$image);if($lock){flock($lock,LOCK_UN);fclose($lock);}}
}
$pageTitle='Cover studio';require __DIR__.'/includes/header.php';?>
<div class="page-head"><div><p class="eyebrow">SET THE SCENE</p><h1>Cover studio.</h1><p class="muted"><?=e($event['title'])?></p></div><a class="button secondary" href="event_form.php?id=<?=$id?>">Back to event</a></div><div class="two-column"><section><img class="artwork-preview" src="<?=e(eventArtwork($event))?>" alt="Current event artwork"><p class="fine-print">Your cover appears on the event page, discovery cards and posters.</p></section><section class="panel"><h2>Give your event a look.</h2><?php if($error):?><p class="flash error"><?=e($error)?></p><?php endif;?><form method="post" enctype="multipart/form-data"><?=csrfField()?><label>Choose an original illustrated cover</label><div class="artwork-options"><?php foreach(artworkChoices()as $art):?><label class="artwork-choice"><input type="radio" name="artwork" value="<?=e($art)?>" <?=$event['artwork']===$art?'checked':''?>><img src="<?=e(artworkPath($art))?>" alt=""><span><?=e(artworkLabel($art))?></span></label><?php endforeach;?></div><label for="cover">Or upload your own image</label><input id="cover" name="cover" type="file" accept="image/jpeg,image/png,image/webp"><p class="fine-print">JPEG, PNG or WebP · 4 MB maximum · 100–4096 px. Use an image you have permission to share. Saving without an upload switches back to the selected illustration.</p><button class="wide">Save cover</button></form></section></div>
<?php require __DIR__.'/includes/footer.php';?>
