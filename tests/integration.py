"""Run ONLY against a dedicated *_test database and local app.
EVENTLINK_TEST_ALLOW_MUTATION=1 python3 tests/integration.py
Requires requests, PHP on PATH, seeded demo accounts with EVENTLINK_DEMO_PASSWORD.
"""
import os,re,json,time,subprocess,concurrent.futures,hashlib,base64,io
from pathlib import Path
import requests
if os.getenv('EVENTLINK_TEST_ALLOW_MUTATION')!='1':raise SystemExit('Set EVENTLINK_TEST_ALLOW_MUTATION=1 for the dedicated test database.')
ROOT=Path(__file__).resolve().parents[1];URL=os.getenv('EVENTLINK_TEST_URL','http://127.0.0.1:8080');PASSWORD='TestAccount!2026';DEMO=os.getenv('EVENTLINK_DEMO_PASSWORD','FairboardDemo!2026');checks=[]
def db(sql,params=()):
 r=subprocess.run(['php',str(ROOT/'tests/db_fixture.php')],input=json.dumps({'sql':sql,'params':list(params)}),text=True,capture_output=True,check=True)
 return json.loads(r.stdout)
def rows(sql,params=()):return db(sql,params)['rows']
def scalar(sql,params=()):return next(iter(rows(sql,params)[0].values()))
def check(test,name):
 if not test:raise AssertionError(name)
 checks.append(name);print('PASS',name,flush=True)
def token(r):
 m=re.search(r'name="csrf_token" value="([a-f0-9]+)"',r.text) or re.search(r'name="csrf-token" content="([a-f0-9]+)"',r.text)
 if not m:raise AssertionError('No CSRF token: '+r.url+' '+str(r.status_code)+' '+r.text[:150])
 return m.group(1)
def get(s,path):return s.get(URL+'/'+path,timeout=20)
def post(s,path,data=None,csrf=None,follow=False,files=None):
 data=dict(data or {});data['csrf_token']=csrf or token(get(s,'index.php'))
 return s.post(URL+'/'+path,data=data,files=files,allow_redirects=follow,timeout=30)
def recovery(uid):
 return json.loads(subprocess.run(['php',str(ROOT/'tests/db_fixture.php')],input=json.dumps({'mfa_recovery':uid}),text=True,capture_output=True,check=True).stdout)
def login(email,password=PASSWORD):
 uid=int(scalar('SELECT user_id FROM users WHERE email=?',[email]));role=scalar('SELECT role FROM users WHERE user_id=?',[uid]);code=recovery(uid) if role in ['admin','staff'] else None
 s=requests.Session();r=get(s,'login.php');old=s.cookies.get('FAIRBOARDSESSID');r=post(s,'login.php',{'email':email,'password':password},token(r));check(r.status_code==302,'login '+email);check(s.cookies.get('FAIRBOARDSESSID')!=old,'session ID rotates '+email)
 if code:check(post(s,'mfa_challenge.php',{'code':code}).status_code==302,'MFA '+email)
 return s
stamp=str(int(time.time()));suffix='@fairboard.test';hash_value=json.loads(subprocess.run(['php',str(ROOT/'tests/db_fixture.php')],input=json.dumps({'hash':PASSWORD}),text=True,capture_output=True,check=True).stdout)
def account(name,role='user'):
 email='it-'+stamp+'-'+name+suffix;uid=int(db('INSERT INTO users(name,email,password_hash,role,email_verified_at)VALUES(?,?,?,?,NOW())',[name,email,hash_value,role])['id']);return uid,email
ids=[]
def create(name,role='user'):
 v=account(name,role);ids.append(v[0]);return v
try:
 db('DELETE FROM auth_rate_limits')
 guest=requests.Session();r=get(guest,'index.php');check(r.status_code==200 and 'Less scrolling.' in r.text,'public discovery renders');check("script-src 'self'" in r.headers.get('Content-Security-Policy',''),'strict self-hosted CSP');check(r.headers.get('X-Content-Type-Options')=='nosniff','nosniff header');check('HttpOnly' in r.headers.get('Set-Cookie','') and 'SameSite=Lax' in r.headers.get('Set-Cookie',''),'cookie security flags')
 for path in ['config.php','security_test_results.json','database.sql','database/release_tables.sql','tools/migrate.php','tests/db_fixture.php','storage/mail/example.txt','includes/security.php','.git/config','composer.lock']:
  check(get(guest,path).status_code==404,'private file blocked '+path)
 for path in ['mfa.php','mfa_challenge.php','reauth.php','delete_account.php','accept_staff.php','change_initial_password.php','login.php','register.php','booking_action.php','cancel_booking.php','leave_waitlist.php','event_form.php','admin_action.php','organiser_action.php','checkin.php','ticket_qr.php','scan.php','account.php','notifications.php','billing.php','developer.php','forgot_password.php','reset_password.php','verify_email.php','photo.php?id=1','logout.php']:
  check(guest.post(URL+'/'+path,data={'csrf_token':'bad'},timeout=20).status_code==403,'CSRF enforced '+path)
 check(get(guest,'index.php?q=%27%20OR%201=1--&category=Film').status_code==200,'search parameterized');check(get(guest,'index.php?q[]=a').status_code==400,'array query rejected')
 creator_id,creator_email=create('creator','creator');staff_id,staff_email=create('staff','staff');u1_id,u1_email=create('one');u2_id,u2_email=create('two');u3_id,u3_email=create('three');unassigned_id,unassigned_email=create('unassigned','staff')
 db("INSERT INTO creator_plans(user_id,organisation_name,plan_code)VALUES(?,'Integration Society','external_monthly')",[creator_id]);db('INSERT INTO staff_assignments(staff_user_id,creator_user_id)VALUES(?,?)',[staff_id,creator_id])
 creator=login(creator_email);staff=login(staff_email);u1=login(u1_email);u2=login(u2_email);u3=login(u3_email);unassigned=login(unassigned_email)
 check(get(u1,'admin.php').status_code==403,'user cannot access admin');check(get(u1,'developer.php').status_code==404,'user cannot access developer explorer')
 # Registration and verification, with a real generated private development email.
 email='registration-'+stamp+'@stu.vtc.edu.hk';r=post(guest,'register.php',{'name':'New Creator','email':email,'password':PASSWORD,'password_confirm':PASSWORD,'role':'creator','organisation':'New Society'});check(r.status_code==302,'creator registration succeeds');reg_id=int(scalar('SELECT user_id FROM users WHERE email=?',[email]));ids.append(reg_id);check(scalar('SELECT COUNT(*) FROM creator_plans WHERE user_id=?',[reg_id])==1,'creator plan created atomically');check(scalar('SELECT is_school_email FROM users WHERE user_id=?',[reg_id])==1,'school domain detected');
 def mailtoken(address,purpose):
  for f in sorted((ROOT/'storage/mail').glob('*.txt'),key=lambda x:x.stat().st_mtime,reverse=True):
   text=f.read_text()
   if address in text and purpose in text:return re.search(r'token=([a-f0-9]{64})',text).group(1)
  raise AssertionError('Missing development email')
 verify=mailtoken(email,'verify_email.php');get(guest,'verify_email.php?token='+verify);check(not scalar('SELECT email_verified_at FROM users WHERE user_id=?',[reg_id]),'GET verification link has no side effect');r=post(guest,'verify_email.php',{'token':verify});check('Email verified.' in r.text,'verification token consumed');check(scalar('SELECT school_email_verified FROM users WHERE user_id=?',[reg_id])==1,'school ownership verified');check('invalid, expired or already used' in post(guest,'verify_email.php',{'token':verify}).text,'verification replay rejected')
 r=post(guest,'register.php',{'name':'Bad','email':'forged-'+stamp+suffix,'password':PASSWORD,'password_confirm':PASSWORD,'role':'admin'});check('valid account type' in r.text and scalar('SELECT COUNT(*) FROM users WHERE email=?',['forged-'+stamp+suffix])==0,'public admin registration rejected');r=post(guest,'register.php',{'name':'Bad','email':'weak-'+stamp+suffix,'password':'weak','password_confirm':'different','role':'user'});check('Passwords do not match' in r.text and 'at least 12' in r.text,'password validation and confirmation')
 def event(title,capacity=1,minutes=60):return int(db("INSERT INTO events(organiser_id,title,description,start_time,location,capacity,category,artwork,status)VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL ? MINUTE),'Test Hall',?,'Music','concert','approved')",[creator_id,title,'Integration event description',minutes,capacity])['id'])
 eid=event('Concurrency '+stamp)
 check(get(unassigned,'attendees.php?event_id='+str(eid)).status_code==403,'unassigned staff attendee access denied');check(get(unassigned,'event_form.php?id='+str(eid)).status_code==403,'unassigned staff edit denied');check(get(staff,'attendees.php?event_id='+str(eid)).status_code==200,'assigned staff attendee access allowed')
 t1=token(get(u1,'event.php?id='+str(eid)));t2=token(get(u2,'event.php?id='+str(eid)))
 with concurrent.futures.ThreadPoolExecutor() as ex:
  list(ex.map(lambda x:post(x[0],'booking_action.php',{'event_id':eid,'action':'book'},x[1]),[(u1,t1),(u2,t2)]))
 check(scalar('SELECT COUNT(*) FROM bookings WHERE event_id=?',[eid])==1,'concurrent last-seat booking cannot overbook');winner=int(scalar('SELECT user_id FROM bookings WHERE event_id=?',[eid]));ws,ls=(u1,u2) if winner==u1_id else (u2,u1);loser=u2_id if winner==u1_id else u1_id;bid=int(scalar('SELECT booking_id FROM bookings WHERE event_id=?',[eid]));
 check(int(scalar("SELECT subject_id FROM audit_log WHERE actor_id=? AND action='booking.create' ORDER BY audit_id DESC LIMIT 1",[winner]))==bid,'booking audit records exact booking ID');check(post(ls,'cancel_booking.php',{'booking_id':bid}).status_code==403,'cannot cancel another user booking');check(post(ls,'ticket_qr.php',{'booking_id':bid}).status_code==404,'cannot obtain another user ticket');post(ls,'booking_action.php',{'event_id':eid,'action':'wait'});post(ls,'booking_action.php',{'event_id':eid,'action':'wait'});check(scalar('SELECT COUNT(*) FROM waiting_list WHERE event_id=?',[eid])==1,'waitlist duplicate prevented');post(ws,'cancel_booking.php',{'booking_id':bid});check(int(scalar('SELECT user_id FROM bookings WHERE event_id=?',[eid]))==loser,'cancellation promotes FIFO attendee');check(scalar('SELECT COUNT(*) FROM waiting_list WHERE event_id=?',[eid])==0,'promotion removes waitlist entry');check(post(ws,'cancel_booking.php',{'booking_id':bid}).status_code==403,'duplicate cancellation rejected');check('A place is yours' in get(ls,'notifications.php').text,'promotion notification delivered')
 past=event('Past '+stamp,3,-60);post(u1,'booking_action.php',{'event_id':past,'action':'book'});check(scalar('SELECT COUNT(*) FROM bookings WHERE event_id=?',[past])==0,'past event booking rejected')
 qid=event('QR '+stamp,4);post(u1,'booking_action.php',{'event_id':qid,'action':'book'});post(u2,'booking_action.php',{'event_id':qid,'action':'book'});qb=int(scalar('SELECT booking_id FROM bookings WHERE event_id=? AND user_id=?',[qid,u1_id]));r=post(u1,'ticket_qr.php',{'booking_id':qb});check(r.status_code==200 and 'no-store' in r.headers['Cache-Control'],'private uncached rotating QR issued');data=r.json();check(data['expires_at']-data['server_time']<=30 and data['image'].startswith('data:image/svg+xml;base64,'),'QR expiry and image present');check(post(unassigned,'scan.php',{'token':data['token']}).status_code==403,'unassigned staff cannot redeem valid ticket');tamper=data['token'][:-1]+('0' if data['token'][-1]!='0' else '1');check('Expired or invalid' in post(staff,'scan.php',{'token':tamper}).text,'tampered ticket rejected')
 # Race two independent authorised sessions on the same ticket.
 staff2=login(staff_email);code=post(u1,'ticket_qr.php',{'booking_id':qb}).json()['token'];st1=token(get(staff,'scan.php'));st2=token(get(staff2,'scan.php'))
 with concurrent.futures.ThreadPoolExecutor() as ex:results=list(ex.map(lambda x:post(x[0],'scan.php',{'token':code},x[1]),[(staff,st1),(staff2,st2)]))
 check(sum('Welcome in!' in x.text for x in results)==1 and sum('Already checked in.' in x.text for x in results)==1,'concurrent QR redemption accepted exactly once');check(post(u1,'ticket_qr.php',{'booking_id':qb}).status_code==409,'used ticket cannot issue new QR')
 qb2=int(scalar('SELECT booking_id FROM bookings WHERE event_id=? AND user_id=?',[qid,u2_id]));old=post(u2,'ticket_qr.php',{'booking_id':qb2}).json();delay=old['expires_at']-old['server_time']+1;print('Waiting',delay,'seconds for real QR expiry',flush=True);time.sleep(delay);check('Expired or invalid' in post(staff,'scan.php',{'token':old['token']}).text,'real expired QR rejected');new=post(u2,'ticket_qr.php',{'booking_id':qb2}).json();check(new['token']!=old['token'],'real QR rotates across thirty-second boundary');check('Welcome in!' in post(staff,'scan.php',{'token':new['token']}).text,'fresh rotated QR accepted')
 # Password reset revokes a previously authenticated session.
 r=post(guest,'forgot_password.php',{'email':u3_email});check('If an active account matches' in r.text,'password-reset response is generic');raw=mailtoken(u3_email,'reset_password.php');check(scalar('SELECT token_hash FROM account_tokens WHERE user_id=? AND purpose=?',[u3_id,'reset'])==hashlib.sha256(raw.encode()).hexdigest(),'only reset-token hash stored');newpass='DifferentPass!2026';r=post(guest,'reset_password.php',{'token':raw,'password':newpass,'password_confirm':newpass});check(r.status_code==302,'password reset accepted');check('login.php' in get(u3,'account.php').url,'old sessions revoked after reset');check('invalid, expired or already used' in post(guest,'reset_password.php',{'token':raw,'password':newpass,'password_confirm':newpass}).text,'reset token replay rejected');u3=login(u3_email,newpass)
 # Real read-only developer view, gated separately from normal admin.
 admin=login('admin@fairboard.test',DEMO);r=get(admin,'developer.php');check('Confirm your password' in r.text,'developer requires reauthentication');r=post(admin,'developer.php',{'password':DEMO,'code':recovery(int(scalar("SELECT user_id FROM users WHERE email='admin@fairboard.test'")))});check(r.status_code==302,'developer reauthentication succeeds');r=get(admin,'developer.php?table=users');check('Table structure' in r.text and 'password_hash' in r.text,'developer schema includes hidden column metadata');check(hash_value not in r.text and u1_email in r.text,'developer rows show authorized email but hide password hashes');check(get(admin,'developer.php?table=users%60%20UNION%20SELECT').status_code==400,'developer table injection rejected')
 # Admin queue and fixed-price offline ledger.
 r=post(creator,'event_form.php',{'title':'Created '+stamp,'description':'Created by integration suite','start_time':time.strftime('%Y-%m-%dT19:00',time.gmtime(time.time()+86400*30)),'location':'Campus','capacity':'20','category':'Film','artwork':'cinema'});check(r.status_code==302,'creator submits complete event form');ce=int(scalar('SELECT event_id FROM events WHERE title=?',['Created '+stamp]));check(scalar('SELECT status FROM events WHERE event_id=?',[ce])=='pending','new event awaits moderation');check(get(guest,'event.php?id='+str(ce)).status_code==404,'pending event hidden from public');post(admin,'admin_action.php',{'action':'approve','event_id':ce});check(scalar('SELECT status FROM events WHERE event_id=?',[ce])=='approved','admin approves event');
 post(creator,'billing.php',{'action':'request_invoice','amount_cents':'1'});post(creator,'billing.php',{'action':'request_invoice'});invoices=rows('SELECT * FROM creator_invoices WHERE user_id=?',[creator_id]);check(len(invoices)==1 and int(invoices[0]['amount_cents'])==8000,'monthly invoice is idempotent and price is server controlled');post(admin,'billing.php',{'action':'record_payment','invoice_id':invoices[0]['invoice_id'],'reference':'TEST-RECEIPT'});check(scalar('SELECT status FROM creator_invoices WHERE invoice_id=?',[invoices[0]['invoice_id']])=='paid','admin records offline payment');check(post(creator,'billing.php',{'action':'record_payment','invoice_id':invoices[0]['invoice_id'],'reference':'FORGED'}).status_code==403,'creator cannot forge payment receipt')
 # Safe image handling.
 r=post(creator,'photo.php?id='+str(ce),{'artwork':'cinema'},files={'cover':('bad.svg',b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>','image/svg+xml')});check('JPEG, PNG or WebP' in r.text,'SVG upload rejected');
 from PIL import Image
 out=io.BytesIO();Image.new('RGB',(160,160),(230,100,130)).save(out,format='PNG');r=post(creator,'photo.php?id='+str(ce),{'artwork':'cinema'},files={'cover':('safe.png',out.getvalue(),'image/png')});check(r.status_code==302,'safe image uploaded');image=scalar('SELECT image_path FROM events WHERE event_id=?',[ce]);r=get(guest,'media.php?image='+image);check(r.status_code==200 and r.content[:3]==b'\xff\xd8\xff','uploaded image re-encoded to JPEG');check(get(guest,'media.php?image=../../config.php').status_code==404,'image path traversal denied')

 # Create-event upload tile: validation, private JPEG storage, and switching back to a template.
 form_html=get(creator,'event_form.php').text
 check('data-artwork-upload-trigger' in form_html and 'enctype="multipart/form-data"' in form_html,
       'create event has interactive upload tile')
 custom={'title':'Upload Cover '+stamp,'description':'Creator upload integration test',
         'start_time':time.strftime('%Y-%m-%dT19:00',time.gmtime(time.time()+86400*30)),
         'location':'Campus','capacity':'8','category':'Other','artwork':'upload','intent':'save_draft'}
 check('Choose an image to upload' in post(creator,'event_form.php',custom).text,
       'upload choice requires a file on a new event')
 r=post(creator,'event_form.php',custom,
        files={'cover':('unsafe.svg',b'<svg xmlns="http://www.w3.org/2000/svg"/>','image/svg+xml')})
 check('JPEG, PNG or WebP' in r.text,'event creation rejects SVG uploads')
 r=post(creator,'event_form.php',custom,
        files={'cover':('cover.png',out.getvalue(),'image/png')})
 check(r.status_code==302,'creator creates a draft with uploaded cover')
 uploaded_event=int(scalar('SELECT event_id FROM events WHERE title=?',['Upload Cover '+stamp]))
 upload_record=rows('SELECT artwork,image_path,status FROM events WHERE event_id=?',[uploaded_event])[0]
 check(upload_record['artwork']=='other' and upload_record['image_path'].endswith('.jpg') and upload_record['status']=='draft',
       'uploaded image persists while fallback artwork stays valid')
 check(get(guest,'media.php?image='+upload_record['image_path']).status_code==404,
       'private draft cover is not public')
 check(get(creator,'media.php?image='+upload_record['image_path']).content[:3]==b'\xff\xd8\xff',
       'creator reads safely re-encoded JPEG')
 change={**custom,'event_id':uploaded_event,'artwork':'gallery'}
 check(post(creator,'event_form.php',change).status_code==302,'creator switches uploaded cover to template')
 check(scalar('SELECT image_path FROM events WHERE event_id=?',[uploaded_event]) is None,
       'switching to illustration clears uploaded cover')
 check(not (ROOT/'storage/covers'/upload_record['image_path']).exists(),
       'unused original upload is removed')
 # Preserve features added concurrently on the team branch.
 check(get(admin,'scan.php').status_code==403,'admin cannot perform staff admission work')
 check(get(admin,'attendees.php?event_id='+str(qid)).status_code==403,'admin cannot access working guest list')
 r=get(guest,'index.php?low_data=1');check('<img ' not in r.text,'low-data discovery omits image elements')
 check(get(guest,'index.php?date=today').status_code==200,'legacy today filter remains supported')
 check(get(guest,'index.php?date=weekend').status_code==200,'legacy weekend filter remains supported')
 draft_payload={'title':'Draft '+stamp,'description':'Private drafting','start_time':time.strftime('%Y-%m-%dT19:00',time.gmtime(time.time()+86400*30)),'location':'Studio','capacity':'100','category':'Learning','artwork':'digital','intent':'save_draft','social_url':'https://example.org/event'}
 post(creator,'event_form.php',draft_payload);draft=int(scalar('SELECT event_id FROM events WHERE title=?',['Draft '+stamp]));check(scalar('SELECT status FROM events WHERE event_id=?',[draft])=='draft','creator saves a private draft');check(get(guest,'event.php?id='+str(draft)).status_code==404,'draft hidden from public')
 draft_payload.update({'event_id':draft,'intent':'submit'});post(creator,'event_form.php?id='+str(draft),draft_payload);check(scalar('SELECT status FROM events WHERE event_id=?',[draft])=='pending','draft submits for review')
 post(admin,'admin_action.php',{'action':'approve','event_id':draft});check('https://example.org/event' in get(guest,'event.php?id='+str(draft)).text,'approved social link displayed safely')
 draft_payload['social_url']='javascript:alert(1)';check('HTTP or HTTPS URL' in post(creator,'event_form.php?id='+str(draft),draft_payload).text,'unsafe social link rejected')
 # The eleventh automatic promotion remains blocked even after capacity grows.
 budget=event('Lifetime refill '+stamp,120);db('UPDATE events SET original_capacity=100,refill_count=9 WHERE event_id=?',[budget])
 for uid in [u1_id,u2_id]:db('INSERT INTO bookings(event_id,user_id)VALUES(?,?)',[budget,uid])
 for uid in [u3_id,reg_id]:db('INSERT INTO waiting_list(event_id,user_id)VALUES(?,?)',[budget,uid])
 # A registered creator cannot be promoted as an attendee; use another test attendee for FIFO successor.
 db('DELETE FROM waiting_list WHERE event_id=? AND user_id=?',[budget,reg_id]);later,later_email=create('later');db('INSERT INTO waiting_list(event_id,user_id)VALUES(?,?)',[budget,later])
 first_bid=int(scalar('SELECT booking_id FROM bookings WHERE event_id=? AND user_id=?',[budget,u1_id]));post(u1,'cancel_booking.php',{'booking_id':first_bid});check(scalar('SELECT refill_count FROM events WHERE event_id=?',[budget])==10,'tenth automatic promotion counted')
 second_bid=int(scalar('SELECT booking_id FROM bookings WHERE event_id=? AND user_id=?',[budget,u2_id]));post(u2,'cancel_booking.php',{'booking_id':second_bid});check(scalar('SELECT refill_count FROM events WHERE event_id=?',[budget])==10 and scalar('SELECT COUNT(*) FROM waiting_list WHERE event_id=? AND user_id=?',[budget,later])==1,'eleventh automatic promotion blocked after capacity increase')
 legacy_name=('b'*32)+'.png';legacy_file=ROOT/'uploads'/legacy_name;Image.new('RGB',(160,160),(100,150,200)).save(legacy_file)
 try:
  db('UPDATE events SET poster_path=?,image_path=NULL WHERE event_id=?',['uploads/'+legacy_name,ce]);check(get(guest,'media.php?poster='+legacy_name).status_code==200,'existing team poster remains readable through controlled media route');check(get(guest,'uploads/'+legacy_name).status_code==404,'legacy uploads cannot be requested directly')
 finally:legacy_file.unlink(missing_ok=True)
 # Remaining creator/admin lifecycle and migration-sensitive fields.
 post(admin,'admin_action.php',{'action':'approve_society','user_id':reg_id,'reference':'TEST-SOCIETY-EVIDENCE'})
 check(scalar('SELECT plan_code FROM creator_plans WHERE user_id=?',[reg_id])=='verified_society','verified school society approved separately by admin')
 check(scalar('SELECT verification_reference FROM creator_plans WHERE user_id=?',[reg_id])=='TEST-SOCIETY-EVIDENCE','society evidence reference preserved')
 reg=login(email);post(reg,'billing.php',{'action':'request_invoice'});check(scalar('SELECT amount_cents FROM creator_invoices WHERE user_id=?',[reg_id])==0,'approved society invoice is waived')
 prior=rows('SELECT * FROM events WHERE event_id=?',[ce])[0];payload={'event_id':ce,'title':'Revised '+stamp,'description':prior['description'],'start_time':str(prior['start_time']).replace(' ','T')[:16],'location':prior['location'],'capacity':prior['capacity'],'category':prior['category'],'artwork':prior['artwork']}
 check(post(creator,'event_form.php?id='+str(ce),payload).status_code==302,'creator edits existing event')
 check(scalar('SELECT status FROM events WHERE event_id=?',[ce])=='pending','material edit requires reapproval');post(admin,'admin_action.php',{'action':'approve','event_id':ce})
 prior=rows('SELECT * FROM events WHERE event_id=?',[qid])[0];payload={'event_id':qid,'title':prior['title'],'description':prior['description'],'start_time':str(prior['start_time']).replace(' ','T')[:16],'location':prior['location'],'capacity':1,'category':prior['category'],'artwork':prior['artwork']}
 check('Capacity cannot be below confirmed bookings' in post(staff,'event_form.php?id='+str(qid),payload).text,'assigned staff cannot shrink below booked seats')
 refill=event('Refill '+stamp,10);fillers=[create('filler-'+str(i))[0] for i in range(10)]
 for uid in fillers:db('INSERT INTO bookings(event_id,user_id)VALUES(?,?)',[refill,uid])
 post(u1,'booking_action.php',{'event_id':refill,'action':'wait'});post(u2,'booking_action.php',{'event_id':refill,'action':'wait'})
 post(creator,'organiser_action.php',{'event_id':refill,'action':'add_places','add_places':2});check(scalar('SELECT capacity FROM events WHERE event_id=?',[refill])==12,'capacity can grow without resetting original allowance')
 check(scalar('SELECT COUNT(*) FROM bookings WHERE event_id=?',[refill])==11,'capacity growth promotes only within lifetime allowance');check(scalar('SELECT COUNT(*) FROM bookings WHERE event_id=? AND user_id=?',[refill,u1_id])==1,'refill respects FIFO order')
 post(u2,'leave_waitlist.php',{'event_id':refill});check(scalar('SELECT COUNT(*) FROM waiting_list WHERE event_id=?',[refill])==0,'user can leave own waiting list')
 # A disabled attendee's still-current signed ticket must not be admitted.
 did,demail=create('disabled');disabled=login(demail);de=event('Disabled account ticket '+stamp,2);post(disabled,'booking_action.php',{'event_id':de,'action':'book'});dbid=int(scalar('SELECT booking_id FROM bookings WHERE event_id=?',[de]));dtoken=post(disabled,'ticket_qr.php',{'booking_id':dbid}).json()['token'];post(admin,'admin_action.php',{'action':'set_active','user_id':did,'active':'0'});check('login.php' in get(disabled,'account.php').url,'disabled account session invalidated');check('no longer exists' in post(staff,'scan.php',{'token':dtoken}).text,'disabled account cannot redeem a previously issued token')
 cancel=event('Cancelled '+stamp,2);post(u1,'booking_action.php',{'event_id':cancel,'action':'book'});cb=int(scalar('SELECT booking_id FROM bookings WHERE event_id=?',[cancel]));post(creator,'organiser_action.php',{'event_id':cancel,'action':'cancel'});check(scalar('SELECT status FROM events WHERE event_id=?',[cancel])=='cancelled','creator cancellation preserves cancelled state');check(post(u1,'ticket_qr.php',{'booking_id':cb}).status_code==409,'cancelled event stops ticket issue');check('Event cancelled:' in get(u1,'notifications.php').text,'event cancellation notifies attendees');check(get(u1,'event.php?id='+str(cancel)).status_code==200,'booked attendee can still view cancellation details')
 # Expiry and cookie scope are enforced by the server, independent of the browser.
 def session_state(s,expire=''):
  request={'session_id':s.cookies.get('FAIRBOARDSESSID'),'expire':expire}
  return json.loads(subprocess.run(['php',str(ROOT/'tests/db_fixture.php')],input=json.dumps(request),text=True,capture_output=True,check=True).stdout)
 idle=login(u1_email);session_state(idle,'idle');check('login.php' in get(idle,'account.php').url,'idle session expires')
 absolute=login(u1_email);session_state(absolute,'absolute');check('login.php' in get(absolute,'account.php').url,'absolute session expires despite recent activity')
 poll_csrf=token(get(u1,'index.php'));before=session_state(u1)['last_activity'];post(u1,'ticket_qr.php',{'booking_id':cb},poll_csrf);check(session_state(u1)['last_activity']==before,'QR polling does not extend idle deadline')
 # Header includes must not overwrite pagination/search state on authenticated pages.
 more=[event('Paging '+stamp+' '+str(i),1) for i in range(14)]
 r=get(u1,'index.php?q=Paging&page=2');check(r.status_code==200 and 'Page 2 of 2' in r.text and 'value="Paging"' in r.text,'authenticated search pagination preserves state')
 r=get(admin,'developer.php?table=audit_log&page=2');check(r.status_code==200 and 'Rows · page 2' in r.text,'developer pagination preserves numeric state')
 # Persistent per-account throttling survives a new PHP session.
 db('DELETE FROM auth_rate_limits');attack=requests.Session();a=token(get(attack,'login.php'))
 for i in range(5):post(attack,'login.php',{'email':u1_email,'password':'wrong'},a)
 attack2=requests.Session();r=post(attack2,'login.php',{'email':u1_email,'password':PASSWORD});check(r.status_code==429 and int(r.headers['Retry-After'])>0,'login throttling persists across sessions')
 # Revoking a staff assignment takes effect on next request.
 post(admin,'admin_action.php',{'action':'unassign_staff','staff_id':staff_id,'creator_id':creator_id});check(get(staff,'attendees.php?event_id='+str(qid)).status_code==403,'staff revocation effective immediately')
 print(json.dumps({'passed':len(checks),'failed':0,'checks':checks},indent=2));(ROOT/'test-results').mkdir(exist_ok=True);(ROOT/'test-results/integration.json').write_text(json.dumps({'passed':len(checks),'failed':0,'checks':checks},indent=2))
finally:
 # All mutations target explicitly created fixture IDs; demo accounts remain for UI checks.
 if ids:
  marks=','.join('?' for _ in ids)
  for table,field in [('staff_assignments','staff_user_id'),('staff_assignments','creator_user_id'),('creator_invoices','user_id'),('creator_plans','user_id'),('events','organiser_id')]:db(f'DELETE FROM {table} WHERE {field} IN ({marks})',ids)
  db(f'DELETE FROM users WHERE user_id IN ({marks})',ids)
 db('DELETE FROM auth_rate_limits')
