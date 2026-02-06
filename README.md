# Kollus Multi-DRM HDCP テストツール

Kollus プレイヤーと PallyCon DRM を使用した HDCP レベルテスト用 PHP サンプルです。

## 概要

このプロジェクトは、Widevine / PlayReady / FairPlay の 3 つの DRM システムにおける HDCP（High-bandwidth Digital Content Protection）レベルの動作を検証するためのテストツールです。

## 必要条件

- PHP 7.3 以上 または PHP 8.0 以上
- Composer
- OpenSSL 拡張機能

## インストール

```bash
# (依存関係をインストール)
composer install
```

## 実行方法

```bash
# (public ディレクトリに移動)
cd public

# (PHP 内蔵サーバーを起動)
php -S localhost:8000

# (ブラウザでアクセス)
# http://localhost:8000/daicolo.php
```

## HDCP レベル設定

URL パラメータで HDCP レベルを指定できます：

| レベル | URL | 説明 |
|--------|-----|------|
| 0 | `?hdcp_level=0` | HDCP なし（最大互換性） |
| 1 | `?hdcp_level=1` | HDCP 1.4（FHD 保護） |
| 2 | `?hdcp_level=2` | HDCP 2.2（4K 保護 + アナログ出力禁止） |

## ブラウザ別 DRM 対応

| ブラウザ | DRM タイプ | ストリーミング | HDCP 制御 |
|----------|-----------|---------------|----------|
| Chrome (Windows) | Widevine L1 | DASH | 可能 |
| Chrome (macOS) | Widevine L3 | DASH | 不可 |
| Edge (Windows) | PlayReady | DASH | 可能 |
| Safari (macOS/iOS) | FairPlay | HLS | 可能 |
| Firefox | Widevine | DASH | 可能 |

## ファイル構造

```
kollus_php_multidrm_sample/
├── composer.json        # (依存関係定義)
├── composer.lock        # (依存関係ロックファイル)
├── public/
│   └── daicolo.php      # (メインテストページ)
├── vendor/              # (Composer パッケージ)
│   └── firebase/        # (JWT ライブラリ)
└── README.md            # (このファイル)
```

## 設定項目

`daicolo.php` 内の以下の定数を環境に合わせて変更してください：

```php
// (PallyCon 設定)
define('INKA_ACCESS_KEY', 'your_access_key');
define('INKA_SITE_KEY', 'your_site_key');
define('INKA_SITE_ID', 'your_site_id');

// (Kollus 設定)
define('KOLLUS_SECURITY_KEY', 'your_security_key');
define('KOLLUS_CUSTOM_KEY', 'your_custom_key');
```

## テスト手順

1. 内蔵ディスプレイでページを開き、動画再生を確認
2. HDCP レベルを選択（0, 1, 2）
3. ブラウザウィンドウを HDMI 外部モニターに移動
4. 再生状態を確認（HDCP 非対応の場合は黒画面になります）

## トラブルシューティング

### 動画が再生されない場合

- ハードウェアアクセラレーションが有効か確認（`chrome://settings`）
- HTTPS 環境で実行しているか確認
- ブラウザの開発者ツール（F12）でエラーログを確認

### HDCP エラーが発生する場合

- モニターが選択した HDCP レベルに対応しているか確認
- ケーブル（HDMI）が HDCP 2.2 に対応しているか確認
- グラフィックドライバーを最新版に更新

## 依存ライブラリ

- [firebase/php-jwt](https://github.com/firebase/php-jwt) - JWT エンコード/デコード

## ライセンス

MIT License
