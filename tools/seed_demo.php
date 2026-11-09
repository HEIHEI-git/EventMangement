<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(array_diff(array_slice($argv,1),['--reset-passwords'])){fwrite(STDERR,"Usage: php tools/seed_demo.php [--reset-passwords]\n");exit(1);}
$resetPasswords=in_array('--reset-passwords',$argv,true);
$config=require __DIR__.'/../config.php';if(($config['app_env']??'')!=='development'){fwrite(STDERR,"Demo data is development-only.\n");exit(1);}
date_default_timezone_set($config['timezone']??'Asia/Hong_Kong');
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];if(!empty($config['db_ssl_ca'])){$options[PDO::MYSQL_ATTR_SSL_CA]=$config['db_ssl_ca'];$options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]=true;}
$pdo=new PDO('mysql:host='.$config['db_host'].';port='.($config['db_port']?:3306).';dbname='.$config['db_name'].';charset=utf8mb4',$config['db_user'],$config['db_pass'],$options);
$sharedPassword=getenv('EVENTLINK_DEMO_PASSWORD')?:null;
// Keep this order so fresh development databases retain the existing demo IDs.
$accounts=[
 'user'=>['Alex Guest','UserP@ssw0rd'],
 'creator'=>['North Campus Collective','CreatorP@ssw0rd'],
 'staff'=>['Jordan Staff','StaffP@ssw0rd'],
 'admin'=>['Fairboard Admin','AdminP@ssw0rd'],
 'admin2'=>['Fairboard Admin 2','AdminP@ssw0rd'],
 'admin3'=>['Fairboard Admin 3','AdminP@ssw0rd'],
 'admin4'=>['Fairboard Admin 4','AdminP@ssw0rd'],
 'admin5'=>['Fairboard Admin 5','AdminP@ssw0rd'],
 'admin6'=>['Fairboard Admin 6','AdminP@ssw0rd'],

];
$messages=[];
$pdo->beginTransaction();
try{
 foreach($accounts as $login=>[$name,$defaultPassword]){
  $role=in_array($login,['admin','admin2','admin3','admin4','admin5','admin6'],true)?'admin':$login;
  $email=$login.'@fairboard.test';
  $q=$pdo->prepare('SELECT user_id,role FROM users WHERE email=? FOR UPDATE');$q->execute([$email]);$existing=$q->fetch(PDO::FETCH_ASSOC);
  if($existing&&$existing['role']!==$role)throw new RuntimeException("Demo role mismatch for $email; no demo accounts were changed.");
  if($existing&&!$resetPasswords){$messages[]="$email already exists; unchanged";continue;}
  $password=$sharedPassword??$defaultPassword;$hash=password_hash($password,PASSWORD_BCRYPT,['cost'=>12]);
  if($existing){
   $pdo->prepare('UPDATE users SET password_hash=?,auth_version=auth_version+1 WHERE user_id=?')->execute([$hash,$existing['user_id']]);
   $pdo->prepare("DELETE FROM account_tokens WHERE user_id=? AND purpose='reset'")->execute([$existing['user_id']]);
   $messages[]="$email password reset; password: $password";
  }else{
   $pdo->prepare('INSERT INTO users(name,email,password_hash,role,email_verified_at) VALUES(?,?,?,?,NOW())')->execute([$name,$email,$hash,$role]);
   $messages[]="$email created; password: $password";
  }
 }
 $pdo->commit();
}catch(Throwable $e){
 if($pdo->inTransaction())$pdo->rollBack();
 throw $e;
}
echo implode("\n",$messages)."\n";
$uid=fn($role)=>(int)$pdo->query("SELECT user_id FROM users WHERE email=".$pdo->quote($role.'@fairboard.test'))->fetchColumn();$creator=$uid('creator');
$pdo->prepare("INSERT IGNORE INTO creator_plans(user_id,organisation_name,plan_code)VALUES(?,'North Campus Collective','external_monthly')")->execute([$creator]);
$pdo->prepare('INSERT IGNORE INTO staff_assignments(staff_user_id,creator_user_id)VALUES(?,?)')->execute([$uid('staff'),$creator]);
$events=[
 ['After Hours: Live Sessions','Music','concert','Student Union Hall',80,3,'A room full of good music and better company. Join student bands and guest artists for an intimate evening of live sets. Doors open 30 minutes early. Bring your friends and discover your next favourite sound.'],
 ['Cinema Under the Stars','Film','cinema','Harbour Rooftop',60,5,'A night for film lovers. Settle in for independent short films, a conversation with the filmmakers, and a skyline worth staying up for. Bring a light jacket; seating is provided.'],
 ['Colour Outside the Lines','Arts','gallery','The Creative Studio',24,7,'Make something just because. Explore colour, collage and printmaking in a relaxed guided workshop. All materials are included and no previous experience is needed.'],
 ['Build Something: Creative Tech','Technology','digital','Innovation Lab',35,9,'An afternoon of ideas and hands-on experiments. Meet other makers, try a small creative coding project and share what you build. Bring your laptop; beginners are welcome.'],
 ['The City After Dark','Community','city','Victoria Harbour',40,11,'See familiar streets from a new angle. A guided neighbourhood walk with local stories, independent makers and a stop for street food. Meet at the harbour steps.'],
 ['Take the Scenic Route','Outdoors','nature','Dragon’s Back Trail',20,13,'Fresh air, wide views and a slower morning. Join a small group for a gentle trail walk with time to take it all in. Bring water, comfortable shoes and sun protection.'],
 ['Sunday Sound Club','Music','concert','The Listening Room',30,16,'An easy Sunday with acoustic sets, shared playlists and new connections. Come for a song. Stay for the afternoon.'],
 ['Frame by Frame','Film','cinema','Lecture Theatre B',50,19,'Four short films. Four different worlds. Stay after the screening for an open discussion about what makes a story stay with us.'],
 ['A Little Room to Create','Arts','gallery','Library Workshop',18,22,'A focused creative session for anyone who wants to try something new. Make a small artwork to take home, with guidance from student artists.'],
];
foreach($events as [$title,$category,$art,$location,$cap,$days,$description]){
 $q=$pdo->prepare('SELECT 1 FROM events WHERE organiser_id=? AND title=?');$q->execute([$creator,$title]);if($q->fetchColumn())continue;
 $start=(new DateTimeImmutable('+'.$days.' days'))->setTime(19,0)->format('Y-m-d H:i:s');
 $pdo->prepare("INSERT INTO events(organiser_id,title,description,start_time,location,capacity,original_capacity,category,artwork,status)VALUES(?,?,?,?,?,?,?,?,?,'approved')")->execute([$creator,$title,$description,$start,$location,$cap,$cap,$category,$art]);
}
echo $resetPasswords
 ?"Development demo is ready. Demo passwords were set; existing MFA, account settings and events were preserved.\n"
 :"Development demo is ready. No existing accounts or events were overwritten.\n";
