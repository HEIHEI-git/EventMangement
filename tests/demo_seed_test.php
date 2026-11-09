<?php
// Run before the HTTP suites, against an empty, freshly imported *_test schema only.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$config=require __DIR__.'/../config.php';
if(getenv('EVENTLINK_TEST_ALLOW_MUTATION')!=='1'||($config['app_env']??'')!=='development'||!str_ends_with($config['db_name'],'_test')){
 fwrite(STDERR,"Refusing mutation outside an explicitly enabled development test database.\n");exit(1);
}
$pdo=new PDO('mysql:host='.$config['db_host'].';port='.$config['db_port'].';dbname='.$config['db_name'].';charset=utf8mb4',$config['db_user'],$config['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()||(int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn()){
 fwrite(STDERR,"Refusing a nonempty test database; import the fresh schema first.\n");exit(1);
}
$passwords=['user'=>'UserP@ssw0rd','creator'=>'CreatorP@ssw0rd','staff'=>'StaffP@ssw0rd','admin'=>'AdminP@ssw0rd'];
$expectedRoles=[];
foreach(array_keys($passwords) as $role){
 foreach(['','2','3','4','5','6'] as $suffix)$expectedRoles[$role.$suffix.'@fairboard.test']=$role;
}
$demoCount=count($expectedRoles);
$check=function(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";};
$seed=function(array $args=[],?string $override=null,bool $success=true):void{
 $env=getenv();unset($env['EVENTLINK_DEMO_PASSWORD']);if($override!==null)$env['EVENTLINK_DEMO_PASSWORD']=$override;
 $process=proc_open([PHP_BINARY,__DIR__.'/../tools/seed_demo.php',...$args],[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
 if(!is_resource($process))throw new RuntimeException('Cannot start demo seeder');
 $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
 if((proc_close($process)===0)!==$success)throw new RuntimeException($out.$err);
};
$users=fn()=>$pdo->query('SELECT * FROM users ORDER BY user_id')->fetchAll();
$events=fn()=>$pdo->query('SELECT * FROM events ORDER BY event_id')->fetchAll();
$plans=fn()=>$pdo->query('SELECT * FROM creator_plans ORDER BY user_id')->fetchAll();
$assignments=fn()=>$pdo->query('SELECT * FROM staff_assignments ORDER BY assignment_id')->fetchAll();
$checkAccounts=function()use($users,$expectedRoles,$passwords,$check,$demoCount):void{
 $actual=$users();$check(count($actual)===$demoCount,'seed creates all 24 demo accounts');
 foreach($actual as $row){
  $role=$expectedRoles[$row['email']]??null;
  $check($role!==null&&$row['role']===$role&&password_verify($passwords[$role],$row['password_hash']),$row['email'].' uses its documented password and role');
 }
};
$seed();$initialUsers=$users();$initialEvents=$events();
$checkAccounts();
$check(count($initialEvents)===9,'seed keeps the original nine sample events');
// Simulate upgrading the previous nine-account demo without changing its users.
$legacyUsers=array_slice($initialUsers,0,9);
$newIds=array_column(array_slice($initialUsers,9),'user_id');$marks=implode(',',array_fill(0,count($newIds),'?'));
$pdo->prepare("DELETE FROM staff_assignments WHERE staff_user_id IN ($marks)")->execute($newIds);
$pdo->prepare("DELETE FROM creator_plans WHERE user_id IN ($marks)")->execute($newIds);
$pdo->prepare("DELETE FROM users WHERE user_id IN ($marks)")->execute($newIds);
$seed();$initialUsers=$users();
$check(array_slice($initialUsers,0,9)===$legacyUsers&&$events()===$initialEvents,'upgrading the nine-account demo preserves existing credentials, IDs and events');
$checkAccounts();
$initialPlans=$plans();$initialAssignments=$assignments();
$check(count($initialPlans)===6&&count($initialAssignments)===6,'all six creators have a plan and an assigned staff account');
foreach(['','2','3','4','5','6'] as $suffix){
 $q=$pdo->prepare('SELECT p.organisation_name,p.plan_code FROM creator_plans p JOIN users u ON u.user_id=p.user_id WHERE u.email=?');$q->execute(['creator'.$suffix.'@fairboard.test']);$plan=$q->fetch();
 $check($plan&&$plan['plan_code']==='external_monthly'&&$plan['organisation_name']!==null,'creator'.$suffix.' has an organisation plan');
 $q=$pdo->prepare('SELECT c.email FROM staff_assignments a JOIN users s ON s.user_id=a.staff_user_id JOIN users c ON c.user_id=a.creator_user_id WHERE s.email=?');$q->execute(['staff'.$suffix.'@fairboard.test']);
 $check($q->fetchAll(PDO::FETCH_COLUMN)===['creator'.$suffix.'@fairboard.test'],'staff'.$suffix.' is assigned only to the matching creator');
}
$check((int)$initialUsers[3]['user_id']===4,'fresh admin ID remains compatible with the CI developer allowlist');
$seed(['--reset-password'],null,false);
$seed();
$check($users()===$initialUsers&&$events()===$initialEvents&&$plans()===$initialPlans&&$assignments()===$initialAssignments,'unknown options fail and ordinary reseeding preserves accounts and events');

$changedHash=password_hash('ChangedDemo!2026',PASSWORD_BCRYPT,['cost'=>12]);
$pdo->prepare('UPDATE users SET password_hash=?')->execute([$changedHash]);
$pdo->exec("UPDATE users SET name='Keep custom name' WHERE role='creator'");
$pdo->exec("UPDATE users SET is_active=0,deleted_at=NOW() WHERE role='user'");
$pdo->exec("UPDATE users SET must_change_password=1 WHERE role='staff'");
$pdo->prepare("INSERT INTO users(name,email,password_hash,role)VALUES('Keep other account','other@example.test',?,'user')")->execute([$changedHash]);
$otherId=(int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO user_mfa(user_id,secret_cipher,recovery_hashes)VALUES(4,'keep-existing-encrypted-secret','[]')");
$pdo->exec("INSERT INTO account_tokens(user_id,purpose,token_hash,expires_at)VALUES(4,'reset',REPEAT('a',64),DATE_ADD(NOW(),INTERVAL 1 DAY))");
$pdo->prepare("INSERT INTO account_tokens(user_id,purpose,token_hash,expires_at)VALUES(?,'reset',REPEAT('b',64),DATE_ADD(NOW(),INTERVAL 1 DAY))")->execute([$otherId]);
$before=$users();$mfa=$pdo->query('SELECT * FROM user_mfa')->fetchAll();
$seed();
$check($users()===$before,'ordinary reseeding preserves changed passwords and account settings');
$seed(['--reset-passwords']);$after=$users();
foreach(array_slice($after,0,$demoCount) as $i=>$row){
 $check(password_verify($passwords[$row['role']],$row['password_hash'])&&(int)$row['auth_version']===(int)$before[$i]['auth_version']+1,$row['email'].' password reset invalidates existing sessions');
 unset($row['password_hash'],$row['auth_version']);$old=$before[$i];unset($old['password_hash'],$old['auth_version']);
 $check($row===$old,$row['email'].' reset preserves account settings');
}
$check($after[$demoCount]===$before[$demoCount],'reset leaves non-demo accounts unchanged');
$check($pdo->query('SELECT * FROM user_mfa')->fetchAll()===$mfa,'reset retains the existing authenticator and recovery hashes');
$check((int)$pdo->query('SELECT COUNT(*) FROM account_tokens WHERE user_id=4')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM account_tokens')->fetchColumn()===1,'reset revokes only demo password-reset tokens');
$check($events()===$initialEvents&&$plans()===$initialPlans&&$assignments()===$initialAssignments,'reseeding and password resets preserve events, creator plans and staff assignments');

$pdo->exec("UPDATE users SET role='user' WHERE email='admin@fairboard.test'");$beforeMismatch=$users();
$seed(['--reset-passwords'],null,false);
$check($users()===$beforeMismatch,'role mismatch rolls back all demo password changes without promoting accounts');
$pdo->exec("UPDATE users SET role='admin' WHERE email='admin@fairboard.test'");
$seed(['--reset-passwords'],'Compatibility!2026');
foreach(array_slice($users(),0,$demoCount) as $row)$check(password_verify('Compatibility!2026',$row['password_hash']),$row['email'].' accepts the shared environment override');

// Leave the documented demo ready for the subsequent HTTP regressions.
$pdo->prepare('DELETE FROM users WHERE user_id=?')->execute([$otherId]);
$pdo->exec('DELETE FROM user_mfa WHERE user_id=4');
$restore=$pdo->prepare('UPDATE users SET name=?,is_active=?,deleted_at=?,must_change_password=? WHERE user_id=?');
foreach($initialUsers as $row)$restore->execute([$row['name'],$row['is_active'],$row['deleted_at'],$row['must_change_password'],$row['user_id']]);
$seed(['--reset-passwords']);
foreach($users() as $row)$check(password_verify($passwords[$row['role']],$row['password_hash']),$row['email'].' is ready for default-password HTTP tests');
