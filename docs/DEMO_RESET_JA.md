# Fairboard / EventLink: デモ環境の全データ初期化

> **注意:** この手順は開発専用データベースを消去する破壊的操作です。実際の利用者・予約・イベントがある環境や外部公開中のサイトで実行しないでください。DBを停止・バックアップし、`config.php` の `app_env=development` と `db_name` を確認してから行います。

## 固定デモアカウント

| Role | Email | Password |
| --- | --- | --- |
| Admin | `admin@fairboard.test` | `AdminP@ssw0rd` |
| Staff | `staff@fairboard.test` | `StaffP@ssw0rd` |
| Creator | `creator@fairboard.test` | `CreatorP@ssw0rd` |
| User | `user@fairboard.test` | `UserP@ssw0rd` |

これらは `app_env=development` の `tools/seed_demo.php` / `tools/dome_seed.php` で**新しく作成したアカウント**の初期パスワードです。既存の同名アカウントは書き換えません。**初期値を再設定するには、まず下記のDB初期化を完了してください。** CIで旧来の一律パスワードを必要とする場合のみ `EVENTLINK_DEMO_PASSWORD` 環境変数を指定できます。

## 1. 環境の確認・バックアップ

```bash
cd /var/www/html/EventLink
# config.php の app_env / db_name を確認してください（認証情報は表示・共有しない）。
DB_NAME=eventlink  # config.php の db_name と同じ名前に変更
sudo mariadb-dump --single-transaction "$DB_NAME" > "$HOME/eventlink-before-reset-$(date +%Y%m%d-%H%M%S).sql"
```

必要なら `storage/` と `uploads/` も別途バックアップしてください。データベースの削除はその中の画像・メールファイルを削除しません。

## 2. DBの全業務データを削除

```bash
sudo mariadb "$DB_NAME"
```

MariaDBのプロンプトで以下を実行します。外部キーに従った削除順です。スキーマと `roles` マスターテーブルは保持します。

```sql
START TRANSACTION;

DELETE FROM notifications;
DELETE FROM account_tokens;
DELETE FROM auth_rate_limits;
DELETE FROM audit_log;
DELETE FROM creator_invoices;
DELETE FROM waiting_list;
DELETE FROM bookings;
DELETE FROM staff_assignments;
DELETE FROM creator_plans;
DELETE FROM events;
DELETE FROM user_mfa;
DELETE FROM users;

COMMIT;
```

`user_mfa` はユーザー削除時にカスケード削除されますが、MFAを確実に初期化する意図を明示するために入れています。過去のバージョンから残った追加テーブル（例：旧 `staff_invites`）は上記SQLの対象外です。**DELETE後にCOMMITすると元に戻せません。** エラーが発生した場合はCOMMITせずROLLBACKして原因を確認してください。

## 3. デモデータの再生成

MariaDBを `exit` で終了し、シェルで実行します。

```bash
php tools/dome_seed.php
```

または、同等の正規スクリプト:

```bash
php tools/seed_demo.php
```

両スクリプトは同じ処理です。デモアカウント4件・Creatorプラン・Staff割当て・サンプルイベント9件を作ります。既にあるアカウントや同タイトルのイベントは上書きしません。

## 4. 確認

```bash
sudo mariadb "$DB_NAME" -e "SELECT email, role, email_verified_at FROM users ORDER BY role; SELECT COUNT(*) AS events FROM events;"
composer test
```

Admin / Staffは**初回ログイン後にMFA登録**が必要です。実在環境やCloudflare公開サイトでは、これらの既知の固定パスワードを持つアカウントを使わないでください。テスト用アカウントは開発環境でのみ使用します。
