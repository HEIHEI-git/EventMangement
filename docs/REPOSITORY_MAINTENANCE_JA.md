# 個人リポジトリの管理と復元

対象: [HEIHEI-git/EventMangement](https://github.com/HEIHEI-git/EventMangement)。整理基準日は 2026-11-02（香港時間）です。

## 使用するブランチ

| ブランチ | 用途 |
|---|---|
| `main` | 通常の導入・開発の基準。チーム側 `8d807029a2c70026f8115e6adbd8d476305187bf` までの履歴をマージし、個人側のテスト設定変更 `c2119e9c514d5ca51cc58e32e20647f571d602fe` を維持。 |
| `feature/demo-account-reset-20261102` | デモ初期化の未統合作業。通常の導入対象ではなく、別途レビュー・検証する候補。 |
| `archive/20261102` | 整理前の各ブランチの先端を親コミットとして保持する復元専用の履歴索引。アプリの導入先や main へのマージ対象にはしません。 |

QR の修正は `includes/tickets.php` と `tests/integration.py` に含まれます。php-qrcode 6 の SVG レンダラーを指定し、XML 宣言を含む SVG 出力を受け入れます。

固定デモアカウントの PR #20 は未マージで閉じられています。その元のコミットも archive に保存します。現行 `main` の `tools/seed_demo.php` は、[セットアップガイド](SETUP_JA.md#デモデータ) の役割別デモパスワードを使用します。通常は既存アカウントを保持し、`--reset-passwords` を付けた場合だけ24件のデモアカウントのパスワードを再設定します。`EVENTLINK_DEMO_PASSWORD` による共通値の上書きにも対応します。

## ブランチ整理のルール

- 完了した作業や旧案は専用 archive ブランチに履歴を保存し、普段のブランチ一覧から外します。archive 自体は保存し続けてください。
- 下記の一覧は整理前のリモート参照です。未マージの旧案も SHA から復元できます。古いブランチがすべて main にマージされたという意味ではありません。
- 削除は archive の作成とリモートでの検証後に実施します。取得時から先端が変わったブランチは削除せず、再確認します。
- 新しい作業は最新の `main` から短期間のブランチを作り、PR を作成して CI を確認します。
- チーム側の `main` を取り込む場合はマージで履歴を維持し、個人側の変更をリセットで消さないでください。

## CI の実行範囲

自動実行は `main` への push と `main` を対象にした PR です。同じ PR 更新に対する push と PR の重複実行を避けます。Actions の `Run workflow` から手動実行もできます。

既存の Composer 検証・依存監査・PHP/JavaScript 構文検査・PHP テスト・DB マイグレーション・画像保存・防御および HTTP 統合テストは維持します。Playwright のブラウザ操作テストはこの CI には含まれません。実行方法は [README の Tests](../README.md#tests) を参照してください。

CI の合否は対象コミットの [Actions](https://github.com/HEIHEI-git/EventMangement/actions) で確認してください。以前の失敗履歴は調査記録として残ります。

## 整理前の参照一覧

`archive/20261102` の `branches.json` にも同じ元の参照と SHA を記録します。archive は Git の履歴の保存であり、DB・アップロード・ローカル設定のバックアップではありません。

| 元の参照 | コミット | 扱い |
|---|---|---|
| `debug/qr-ticket-response-20261030` | `00c3ad2cbd62cbc013d773a84562bd64e1f4d517` | 専用 archive ブランチへ退避してブランチ一覧から整理 |
| `feature/demo-account-reset-20261102` | `dfa782653ce3e3a23894666c03d5c8c33a5dac4c` | 作業ブランチとして維持（未統合） |
| `feature/event-cover-upload-clean-20261030` | `7530528f7b96dcac622a8bb3c580e2cd19aa146c` | 専用 archive ブランチへ退避してブランチ一覧から整理 |
| `feature/event-form-upload-cover-20261030` | `f61b88d23891b69df069492439ce317ea01e5cfb` | 専用 archive ブランチへ退避してブランチ一覧から整理 |
| `feature/fixed-demo-accounts-20261102` | `c2119e9c514d5ca51cc58e32e20647f571d602fe` | 専用 archive ブランチへ退避してブランチ一覧から整理 |
| `feature/security-defense-20261029` | `e5eb2d0c8e6355752dd8a1ed4d64b510c7f0f343` | 専用 archive ブランチへ退避してブランチ一覧から整理 |
| `fix/consistent-artwork-gallery-20261030` | `f679974df5cce977c5e8bfc6e9e798a7142280a2` | 専用 archive ブランチへ退避してブランチ一覧から整理 |
| `fix/defense-integration-svg-test-20261030` | `1e514026837151635d370dd515e792aeda838835` | 専用 archive ブランチへ退避してブランチ一覧から整理 |
| `fix/delete-confirm-keyword-style-20261030` | `225e52f424ffbc6194ab5e8cf992e98630f39d7c` | 専用 archive ブランチへ退避してブランチ一覧から整理 |
| `fix/delete-confirm-label-highlight-20261030` | `1f3382512eceb281d53e225367dd0f4ae1885135` | 専用 archive ブランチへ退避してブランチ一覧から整理 |
| `fix/form-recovery-20261030` | `f59c58e17112636c864939383aa1f9872f11bd68` | 専用 archive ブランチへ退避してブランチ一覧から整理 |
| `fix/no-default-cover-selection-20261030` | `1f03bd4eb25cd10a23f6a762cff742321357e550` | 専用 archive ブランチへ退避してブランチ一覧から整理 |
| `fix/qr-ticket-ci-20261030` | `171bd014fe8538006200d780fe91ae1c202c4e51` | 専用 archive ブランチへ退避してブランチ一覧から整理 |
| `fix/upload-card-alignment-20261030` | `06b8343a8386cb8165395a945169c87c6c2d5150` | 専用 archive ブランチへ退避してブランチ一覧から整理 |
| `fix/upload-icon-formats-vertical-20261030` | `99c45bce87ce8238f763a450d5dc1ce36090d296` | 専用 archive ブランチへ退避してブランチ一覧から整理 |
| `fix/upload-tile-reference-style-20261030` | `aeb4c76cc7953c1367a5e966c275a68f0231c9ea` | 専用 archive ブランチへ退避してブランチ一覧から整理 |
| `main` | `c2119e9c514d5ca51cc58e32e20647f571d602fe` | main の整理前スナップショット |
| `revert/pr12-error-highlight-keep-pr13-label-20261030` | `833e1ec2ec3b20b9dc31be2f4eba12768efabe25` | 専用 archive ブランチへ退避してブランチ一覧から整理 |
| `security/email-verification-hardening-20261030` | `4094be2e6e56bd3dddea9122ee6a520586066a27` | 専用 archive ブランチへ退避してブランチ一覧から整理 |
| `test/cover-crop-regression-20261030` | `4094fa8a38624e16bf01ca1c5fb35d241ef34339` | 専用 archive ブランチへ退避してブランチ一覧から整理 |
| `ui/admin-deleted-account-separation-20261030` | `de519ec998240b19a0530956aba6987cd80eb533` | 専用 archive ブランチへ退避してブランチ一覧から整理 |
| `ui/delete-confirmation-placeholder-20261030-v2` | `0658ef44ff401bf078d1bdd225949db523578d8b` | 専用 archive ブランチへ退避してブランチ一覧から整理 |
| `ui/delete-confirmation-placeholder-20261030` | `9e6d03a2dcadc258ec915673793df6dbb23e1fe9` | 専用 archive ブランチへ退避してブランチ一覧から整理 |
| `PR #20 の元の先端` | `03865f2da82694513852a5c07ecf712b3e083594` | 未マージで閉じた提案の保存 |

## 古い作業を復元する

例えば QR 修正ブランチの当時の状態を確認する場合:

```bash
git fetch origin archive/20261102
git switch -c recovery/qr-ticket 171bd014fe8538006200d780fe91ae1c202c4e51
```

復元用の新しいローカルブランチを作成するので、`main` の履歴は変わりません。別の作業を復元する場合は表の SHA と未使用のブランチ名に置き換えてください。シャロー取得ではなく、親の履歴も取得してください。

通常の状態へ戻る場合:

```bash
git switch main
git fetch origin --prune
git pull --ff-only origin main
```

未コミットの作業がある場合は先にコミットまたは退避してください。`--ff-only` が停止した場合はローカル独自コミットがないか確認し、強制リセットで解消しないでください。

## Ubuntu の既存環境

整理後も利用するブランチは `main` です。`git fetch origin --prune` で削除済みリモートブランチの表示を整理できます。更新時は [導入手順](SETUP_JA.md) に従い、既存の `config.php`、DB、保存画像を維持してください。このリポジトリ整理そのものは稼働中の DB やアカウントを変更しません。
