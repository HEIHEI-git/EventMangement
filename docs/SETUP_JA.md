# EventLink / Fairboard 導入手順

## 最初に

このサイトは **PHP + MySQL/MariaDB** で動作します。Tomcat・Java は不要です。PHP 8.3 以上、Composer、PDO MySQL・mbstring・GD・fileinfo が必要です。PHP の CLI と Web サーバーで使うバージョンが異なる場合があるため、両方を確認してください。

既存の `config.php`、DB、`storage/covers/`・既存の `uploads/` はバックアップしてください。**既存DBに新規用 `database.sql` を再インポートしないでください。**

## Windows / XAMPP

1. 作業ブランチのコードを `C:\xampp\htdocs\EventLink` に配置します。
2. XAMPP の MySQL を起動します。Apache で配信する場合は Apache も起動します。
3. 新規環境では phpMyAdmin で `eventlink` データベースを作り、`database.sql` をインポートします。既存環境は後述の移行を実行します。
4. `config.example.php` を `config.php` にコピーし、DBのホスト・ポート・名前・ユーザー・パスワードを設定します。
5. PowerShell で、実際の PHP 実行ファイルを使って鍵を生成します。

```powershell
cd C:\xampp\htdocs\EventLink
php -v
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
composer install --no-dev
```

生成された文字列を `config.php` の `app_key` に設定します。ここには毎回違う鍵を入れず、同じ環境の更新時は保存済みの鍵を引き継ぎます。PHP が PATH にない場合は、自分の PHP フォルダーの `php.exe` をフルパスで実行してください。

Apache で `http://localhost/EventLink/` を開く場合：

```php
'base_url' => 'http://localhost/EventLink',
'app_env' => 'development',
'session_secure' => false,
'mail_transport' => 'log',
```

XAMPP 付属 PHP が要件を満たさない場合は、対応する PHP を用意し、開発中はその PHP の組み込みサーバーでも動作確認できます。

```powershell
php -S 127.0.0.1:8080 router.php
```

この場合の `base_url` は `http://127.0.0.1:8080` です。Apache用のURLと混ぜないでください。MySQL は XAMPP のものを使用できます。

## Ubuntu

PHP 8.3 対応の環境で、必要なパッケージをインストールします。

```bash
sudo apt update
sudo apt install php-cli php-mysql php-mbstring php-gd php-xml php-curl composer mariadb-server
php -v
```

新規DBを管理用アカウントで作成して `database.sql` を読み込みます。実行用の専用DBユーザーも作成し、`config.php` に設定してください。コードのフォルダー内で：

```bash
cp config.example.php config.php
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
composer install --no-dev
php -S 127.0.0.1:8080 router.php
```

上で生成した鍵を `app_key` に設定してからブラウザで開きます。開発サーバーはローカル向けです。

## 既存DBの更新

バックアップ後、設定済みのDBに対して実行します。

```bash
php tools/migrate.php
```

不足しているカラム・テーブルと主催者プランを追加します。ユーザー・イベント・予約は保持します。繰り返し実行できます。`ALTER` / `CREATE` 権限が必要です。本番では移行用と通常実行用のDBアカウントを分けてください。移行用設定をWeb公開フォルダーの外に置き、次のように指定できます。

```bash
php tools/migrate.php /private/path/migration-config.php
```

## デモデータ

開発環境だけで実行できます。

```bash
php tools/seed_demo.php
```

次のアカウントとイベントを作成します。通常の実行では、既存アカウントのパスワードは変更しません。

| メールアドレス | パスワード |
|---|---|
| `admin@fairboard.test` | `AdminP@ssw0rd` |
| `admin2@fairboard.test` | `AdminP@ssw0rd` |
| `admin3@fairboard.test` | `AdminP@ssw0rd` |
| `admin4@fairboard.test` | `AdminP@ssw0rd` |
| `admin5@fairboard.test` | `AdminP@ssw0rd` |
| `admin6@fairboard.test` | `AdminP@ssw0rd` |
| `staff@fairboard.test` | `StaffP@ssw0rd` |
| `creator@fairboard.test` | `CreatorP@ssw0rd` |
| `user@fairboard.test` | `UserP@ssw0rd` |

コード更新後、既存の9つのデモアカウントにも上記のパスワードを設定する場合:

```bash
php tools/seed_demo.php --reset-passwords
```

対象の既存セッションとパスワード再設定リンクは失効します。MFA、権限、アカウント設定、既存イベントは保持します。メールアドレスと権限が対応しない場合は、アカウントの変更をすべて取り消して停止します。無効化されたアカウントの再有効化や、初回パスワード変更要求の解除は行いません。admin / staff の MFA 登録・確認は引き続き必要です。

`EVENTLINK_DEMO_PASSWORD` に空でない値を設定している場合は、9アカウントすべてでその値を使用します。表の値を使う場合は、この環境変数を解除してください。公開されたデモ用の認証情報なので、開発環境だけで利用してください。

## テスト中にMFAを一時停止する

この切り替えに対応したコードへ更新後、既存の `config.php` の配列に次を設定します。`config.example.php` の初期値は `true` です。既存の `config.php` を上書きする必要はありません。

```php
'app_env' => 'development',
'mfa_enabled' => false, // 一時停止。再開時は true に戻す
```

`false` は引用符を付けないPHPの真偽値です。停止中は全ロールでログイン・再認証時のMFAコード確認と admin / staff のMFA登録必須を省略します。パスワード確認、初回パスワード変更、CSRF・権限チェックは継続します。MFA設定画面での登録・解除・リカバリーコード再生成も停止します。

登録済みの認証シークレットとリカバリーコードは保持されます。設定変更後にログアウトしてログインし直してください。すでにコード入力画面を開いている場合もログイン画面からやり直します。`true` に戻すと元のMFA要件が復帰し、停止中に利用した登録済みアカウントのセッションは再ログインを求められます。未登録の admin / staff は登録画面へ進みます。DB移行や認証アプリの再登録は不要です。

この一時停止は `app_env=development` だけで有効です。本番・環境未指定・設定項目省略時はMFAが有効になります。テスト終了後は `mfa_enabled` を `true` に戻してください。

## 開発者用「SQL」ボタン

`config.php` で次を指定します。

```php
'app_env' => 'development',
'enable_developer_tools' => true,
'developer_user_ids' => [4], // 実際に許可する admin の user_id に変更
```

対象の管理者でログインすると `SQL DEV` が表示されます。パスワードの再確認後、10分間テーブル構造・件数・行の一覧を確認できます。パスワード、メールアドレス、トークンなどの機密値は行に表示しません。本番モードではこの機能は無効になります。

## QRチケットと受付

1. Userアカウントでイベントを予約し、「View ticket」を開きます。
2. 入場用QRが30秒の区切りで自動更新されます。スクリーンショットの古いQRは失効します。
3. 主催者または担当スタッフが「Scanner」を開いて読み取り、受付を確定します。
4. 二重受付、期限切れ、改ざん、無関係なスタッフによる受付は拒否されます。

受付可能時間の初期設定は開始2時間前〜開始12時間後です。`checkin_before_seconds` / `checkin_after_seconds` で変更できます。スマートフォンのカメラには HTTPS が必要です。カメラが使えない場合はコード貼り付け・担当者の手動受付が利用できます。印刷ポスターのQRはイベント案内用で、入場用とは役割が異なります。

スマートフォンで使うとき、`base_url` の `localhost` はスマートフォン自身を指します。実際に到達できる HTTPS のURLに設定してください。

## メールと請求

開発中の `mail_transport=log` では、確認メール・再設定メールを `storage/mail/` のテキストファイルで確認できます。Webからは取得できません。

実際にメールを届けるには `mail_transport=mail`、有効な `mail_from`、PHPのメール送信設定／メールサーバーが必要です。この接続情報はコードには含まれていません。

外部主催者の月額請求は HK$80。学校メールの所有確認後、管理者が団体として承認すると免除対象になります。支払いは**別途受け取ったオフライン決済を管理者が領収参照番号とともに記録する方式**です。オンラインカード決済は接続していません。

## 公開前の確認

`app_env=production` に変更し、HTTPS、Secure Cookie、HSTS、実メール配送、DB権限を設定します。開発者画面は無効化してください。Apacheでは `.htaccess` が実際に有効であることを確認し、Nginxでは `nginx.example.conf` の非公開パス制限を反映してください。アプリケーションがWeb経由で `config.php` やSQL、トークンメール、テストコードを返さないことを確認します。

## チーム最新版との互換性

2026年10月27日のチーム側UI更新も取り込んでいます。下書き・キャンセル状態、社交リンク、既存ポスター、カテゴリ、元定員と補位回数を引き継ぎます。候補者の自動繰り上げはイベント全体で元定員の10%（切り捨て、定員10未満では1人）までです。定員を増やしても回数はリセットしません。公開ページには低データ表示と今日／週末フィルターもあります。

受付は主催者・担当スタッフの仕事とし、管理者は管理画面で審査・権限設定を行います。旧ポスターは `media.php` 経由で表示され、`uploads/` への直接アクセスは拒否します。
