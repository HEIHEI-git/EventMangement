"""Exercise MFA pause/resume in a temporary app copy using synthetic test accounts."""
import base64
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time

import requests

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BIN', 'php')
PASSWORD = 'MfaToggleTest!2026'


def main():
    if os.environ.get('EVENTLINK_TEST_ALLOW_MUTATION') != '1':
        raise SystemExit('Dedicated test opt-in required')
    config = json.loads(subprocess.run(
        [PHP, '-r', 'echo json_encode(require $argv[1]);', str(ROOT / 'config.php')],
        capture_output=True, text=True, check=True).stdout)
    if config.get('app_env') != 'development' or not config.get('db_name', '').endswith('_test'):
        raise SystemExit('Refusing non-test database')

    ids, cases = [], []
    checks = 0

    def fixture(data):
        result = subprocess.run([PHP, str(ROOT / 'tests/db_fixture.php')],
                                input=json.dumps(data), capture_output=True, text=True, check=True)
        return json.loads(result.stdout)

    def db(query, params=()):
        return fixture({'sql': query, 'params': list(params)})

    def check(ok, label):
        nonlocal checks
        assert ok, label
        checks += 1
        print('PASS', label, flush=True)

    password_hash = fixture({'hash': PASSWORD})

    def account(role, enrolled=False, temporary=False):
        email = 'mfa-toggle-' + os.urandom(6).hex() + '@fairboard.test'
        uid = int(db('INSERT INTO users(name,email,password_hash,role,must_change_password) VALUES(?,?,?,?,?)',
                     ['MFA toggle tester', email, password_hash, role, int(temporary)])['id'])
        ids.append(uid)
        code = fixture({'mfa_recovery': uid}) if enrolled else None
        return uid, email, code

    try:
        with tempfile.TemporaryDirectory(prefix='eventlink-mfa-') as directory:
            temp = Path(directory)
            app = temp / 'app'
            shutil.copytree(ROOT, app, ignore=shutil.ignore_patterns(
                '.git', 'config.php', 'test-results', '__pycache__', '*.mov'))
            sessions = temp / 'sessions'
            sessions.mkdir()
            with socket.socket() as listener:
                listener.bind(('127.0.0.1', 0))
                port = listener.getsockname()[1]
            url = f'http://127.0.0.1:{port}'
            config.update(base_url=url, session_secure=False)

            def toggle(enabled):
                config['mfa_enabled'] = enabled
                payload = base64.b64encode(json.dumps(config).encode()).decode()
                replacement = app / 'config.next.php'
                replacement.write_text("<?php return json_decode(base64_decode('" + payload + "'), true);")
                replacement.replace(app / 'config.php')

            def client():
                session = requests.Session()
                session.trust_env = False
                return session

            def get(session, path):
                return session.get(url + '/' + path, timeout=15, allow_redirects=False)

            def post(session, path, data):
                page = session.get(url + '/index.php', timeout=15)
                match = re.search(r'name="csrf-token" content="([a-f0-9]{64})"', page.text)
                assert match, (page.status_code, page.url)
                return session.post(url + '/' + path,
                                    data={**data, 'csrf_token': match.group(1)},
                                    timeout=15, allow_redirects=False)

            def login(email):
                session = client()
                response = post(session, 'login.php', {'email': email, 'password': PASSWORD})
                return session, response

            toggle(True)
            with (temp / 'server.log').open('w+') as log:
                server = subprocess.Popen([PHP, '-d', 'opcache.enable_cli=0', '-d',
                    f'session.save_path={sessions}', '-S', f'127.0.0.1:{port}', '-t', str(app)],
                    stdout=log, stderr=log, env={k: v for k, v in os.environ.items()
                                               if k != 'PHP_CLI_SERVER_WORKERS'})
                try:
                    for _ in range(100):
                        try:
                            if get(client(), 'login.php').status_code == 200:
                                break
                        except requests.ConnectionError:
                            pass
                        time.sleep(0.05)
                    else:
                        raise RuntimeError('PHP test server did not start')

                    uid, email, code = account('user', enrolled=True)
                    pending, response = login(email)
                    check(response.headers.get('Location') == 'mfa_challenge.php', 'ON requires an enrolled second factor')
                    verified, response = login(email)
                    check(post(verified, 'mfa_challenge.php', {'code': code}).status_code == 302,
                          'ON accepts a valid second factor')
                    toggle(False)
                    check(get(verified, 'account.php').status_code == 200, 'an already verified session works while paused')
                    check(get(pending, 'mfa_challenge.php').headers.get('Location') == 'login.php',
                          'OFF returns pending challenges to password login')
                    check(get(pending, 'account.php').headers.get('Location', '').startswith('login.php'),
                          'clearing a challenge does not create a login')
                    check(post(pending, 'mfa_challenge.php', {'code': 'invalid'}).headers.get('Location') == 'login.php',
                          'direct challenge POST cannot create a login while paused')
                    wrong = post(pending, 'login.php', {'email': email, 'password': 'wrong'})
                    check(wrong.status_code == 200 and 'Invalid email or password' in wrong.text,
                          'OFF still requires the correct password')
                    check(pending.post(url + '/login.php', data={'email': email, 'password': PASSWORD},
                                       timeout=15).status_code == 403, 'OFF still requires CSRF')

                    for role, home in [('admin', 'admin.php'), ('staff', 'staff.php'),
                                       ('creator', 'dashboard.php'), ('user', 'index.php')]:
                        for enrolled in (False, True):
                            uid, email, code = account(role, enrolled)
                            session, response = login(email)
                            label = role + (' enrolled' if enrolled else ' unenrolled')
                            check(response.status_code == 302 and response.headers.get('Location') == home,
                                  'OFF password login: ' + label)
                            check(get(session, home).status_code == 200, 'OFF protected page: ' + label)
                            page = get(session, 'account.php')
                            check('MFA is temporarily paused' in page.text and 'id="mfa-code"' not in page.text,
                                  'OFF account UI: ' + label)
                            check(get(session, 'mfa.php').headers.get('Location') == 'account.php',
                                  'OFF setup page redirects: ' + label)
                            before = db('SELECT * FROM user_mfa WHERE user_id=?', [uid])['rows']
                            for action in ('start', 'enable', 'disable', 'recovery'):
                                check(post(session, 'mfa.php', {'action': action, 'password': PASSWORD,
                                                               'code': code or '123456'}).status_code == 403,
                                      'OFF blocks MFA ' + action + ': ' + label)
                            failed = post(session, 'reauth.php', {'password': 'wrong'})
                            check(failed.status_code == 200 and 'incorrect' in failed.text,
                                  'OFF reauthentication rejects wrong password: ' + label)
                            check(post(session, 'reauth.php', {'password': PASSWORD}).status_code == 302,
                                  'OFF reauthentication needs no code: ' + label)
                            check(db('SELECT * FROM user_mfa WHERE user_id=?', [uid])['rows'] == before,
                                  'OFF preserves secret, counter and recovery codes: ' + label)
                            if role != 'admin':
                                check(get(session, 'admin.php').status_code == 403, 'OFF retains role checks: ' + label)
                            cases.append((session, role, home, email, code, enrolled))

                    uid, email, _ = account('staff', temporary=True)
                    staff, response = login(email)
                    check(response.headers.get('Location') == 'change_initial_password.php',
                          'OFF retains initial password replacement')
                    check(get(staff, 'staff.php').headers.get('Location') == 'change_initial_password.php',
                          'OFF cannot bypass initial password replacement')
                    response = post(staff, 'change_initial_password.php', {'current_password': PASSWORD,
                        'password': 'ChangedMfaTest!2026', 'password_confirm': 'ChangedMfaTest!2026'})
                    check(response.headers.get('Location') == 'staff.php' and get(staff, 'staff.php').status_code == 200,
                          'OFF password replacement proceeds without enrollment')

                    toggle(True)
                    check(get(verified, 'account.php').headers.get('Location') == 'login.php',
                          'resume clears MFA proof from sessions used during the pause')
                    for session, role, home, email, code, enrolled in cases:
                        response = get(session, home)
                        if enrolled:
                            check(response.headers.get('Location') == 'login.php', 'resume rejects password-only session: ' + role)
                            restored, response = login(email)
                            check(response.headers.get('Location') == 'mfa_challenge.php', 'resume requires second factor: ' + role)
                            check(post(restored, 'mfa_challenge.php', {'code': code}).status_code == 302,
                                  'resume accepts preserved recovery code: ' + role)
                            check(get(restored, home).status_code == 200, 'resume allows verified session: ' + role)
                        elif role in ('admin', 'staff'):
                            check(response.headers.get('Location') == 'mfa.php', 'resume restores mandatory enrollment: ' + role)
                        else:
                            check(response.status_code == 200, 'resume keeps optional enrollment: ' + role)
                    print(f'{checks} MFA toggle HTTP assertions passed.', flush=True)
                finally:
                    server.terminate()
                    try:
                        server.wait(timeout=5)
                    except subprocess.TimeoutExpired:
                        server.kill()
                        server.wait()
    finally:
        for uid in ids:
            db('DELETE FROM users WHERE user_id=?', [uid])


if __name__ == '__main__':
    main()
