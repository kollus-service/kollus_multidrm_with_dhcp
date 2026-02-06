<?php
// (タイムゾーンをUTCに設定)
date_default_timezone_set('UTC');
// (CORSヘッダーを設定)
header('Access-Control-Allow-Origin: *');

// (Composerオートローダーを読み込み)
require __DIR__ . '/../vendor/autoload.php';
use Firebase\JWT\JWT;

/**
 * (設定キー - Kollus & Pallycon)
 */
define('INKA_ACCESS_KEY', 'INKA_ACCESS_KEY');
define('INKA_SITE_KEY', 'INKA_SITE_KEY');
define('INKA_SITE_ID', 'INKA_SITE_ID');
define('INKA_IV', 'INKA_IV');
define('KOLLUS_SECURITY_KEY', 'KOLLUS_SECURITY_KEY');
define('KOLLUS_CUSTOM_KEY', 'KOLLUS_CUSTOM_KEY');

// (テスト用ユーザー情報)
$clientUserId = 'CLIENT_USER_ID'; // Client User ID
$cid = 'CONTENTS_ID';             // Multi DRM Contents ID, Kollus Upload File Key
$mckey = 'MEDIA_CONTENT_KEY';     // Kollus MediaContentKey

// (ユーザーが選択したHDCPレベル、デフォルト値は0)
$hdcp_level = isset($_GET['hdcp_level']) ? (int) $_GET['hdcp_level'] : 0;

/**
 * (ブラウザ別ストリーミング方式を決定する関数)
 * @return array|null (DRMタイプとストリーミングタイプの配列、または null)
 */
function getStreamingType()
{
  // (ユーザーエージェントを取得)
  $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

  // (ユーザーエージェントが空の場合はnullを返す)
  if (!$userAgent) {
    return null;
  }

  // (対応ブラウザリスト)
  $browsers = ['CriOS', 'Edge', 'Edg', 'Firefox', 'Chrome', 'Safari', 'Opera', 'MSIE', 'Trident'];

  foreach ($browsers as $browser) {
    // (ブラウザが一致しない場合はスキップ)
    if (!preg_match("/{$browser}/", $userAgent)) {
      continue;
    }

    $result = null;
    switch ($browser) {
      // (IE/Edge: PlayReady + DASH)
      case "MSIE":
      case "Trident":
      case "Edg":
      case "Edge":
        $result = ["PlayReady", "dash"];
        break;
      // (Chrome/Firefox/Opera: Widevine + DASH)
      case "Chrome":
      case "Firefox":
      case "Opera":
        $result = ["Widevine", "dash"];
        break;
      // (Safari/iOS Chrome: FairPlay + HLS)
      case "Safari":
      case "CriOS":
        $result = ["FairPlay", "hls"];
        break;
      default:
        $result = null;
    }

    // (macOS Edge例外処理: Widevineを使用)
    if ($result && strpos($userAgent, "Macintosh") !== false && strpos($userAgent, "Edg") !== false) {
      $result = ["Widevine", "dash"];
    }

    return $result;
  }

  return null;
}

/**
 * (HDCPレベル別設定を返す関数)
 * @param int $hdcp_level (HDCPレベル: 0, 1, 2)
 * @return array (HDCP設定配列)
 */
function getHdcpSettings($hdcp_level)
{
  switch ($hdcp_level) {
    // (レベル0: HDCP なし - 最大互換性)
    case 0:
      return [
        'widevine_hdcp' => 'HDCP_NONE',
        'playready_hdcp' => 'HDCP_NONE',
        'fairplay_hdcp' => -1,
        'playready_level' => 150,  // (最小レベル)
        'disable_analog' => false,
        'label' => 'HDCP なし'
      ];
    // (レベル1: HDCP 1.4 - FHD保護)
    case 1:
      return [
        'widevine_hdcp' => 'HDCP_V1',
        'playready_hdcp' => 'HDCP_V1',
        'fairplay_hdcp' => 0,  // (HDCP Type 0)
        'playready_level' => 2000,  // (SL2000)
        'disable_analog' => false,
        'label' => 'HDCP 1.4'
      ];
    // (レベル2: HDCP 2.2 - 4K保護 + アナログ出力禁止)
    case 2:
      return [
        'widevine_hdcp' => 'HDCP_V2_2',
        'playready_hdcp' => 'HDCP_V2_2',
        'fairplay_hdcp' => 1,  // (HDCP Type 1)
        'playready_level' => 3000,  // (SL3000)
        'disable_analog' => true,
        'label' => 'HDCP 2.2'
      ];
    // (デフォルト: HDCP なし)
    default:
      return [
        'widevine_hdcp' => 'HDCP_NONE',
        'playready_hdcp' => 'HDCP_NONE',
        'fairplay_hdcp' => -1,
        'playready_level' => 150,
        'disable_analog' => false,
        'label' => 'HDCP なし'
      ];
  }
}

/**
 * (PallyCon DRMペイロードを生成する関数)
 * @param string $clientUserId (クライアントユーザーID)
 * @param string $cid (コンテンツID)
 * @param int $hdcp_level (HDCPレベル)
 * @return string (Base64エンコードされたペイロード)
 */
function createInkaPayload($clientUserId, $cid, $hdcp_level)
{
  // (UTCタイムスタンプを生成)
  $timestamp = gmdate('Y-m-d\TH:i:s\Z');
  $streamingType = getStreamingType();

  // (getStreamingTypeがnullを返した場合はデフォルト値を使用)
  if (!$streamingType) {
    $streamingType = ["Widevine", "dash"];
  }

  $drmType = $streamingType[0];
  $hdcpSettings = getHdcpSettings($hdcp_level);

  // (PallyCon v2標準トークン構造)
  $tokenArr = array(
    'policy_version' => 2,  // (必須項目)

    // (再生ポリシー設定)
    'playback_policy' => array(
      'persistent' => false,  // (永続ライセンス無効)
      'license_duration' => 86400  // (ライセンス有効期間: 24時間)
    ),

    // (セキュリティポリシー - 3つのDRM全て設定)
    'security_policy' => array(
      array(
        'track_type' => 'ALL',  // (全トラックに適用)

        // (Widevine設定)
        'widevine' => array(
          'security_level' => 1,  // (セキュリティレベル)
          'required_hdcp_version' => $hdcpSettings['widevine_hdcp'],
          'required_cgms_flags' => 'CGMS_NONE',
          'disable_analog_output' => $hdcpSettings['disable_analog'],
          'hdcp_srm_rule' => 'HDCP_SRM_RULE_NONE',
          'override_device_revocation' => true
        ),

        // (PlayReady設定)
        'playready' => array(
          'security_level' => $hdcpSettings['playready_level'],
          'digital_video_protection_level' => 100,  // (デフォルト値)
          'analog_video_protection_level' => 100,
          'digital_audio_protection_level' => 100,
          'require_hdcp_type_1' => ($hdcp_level >= 2)  // (HDCP 2.2要求)
        ),

        // (FairPlay設定)
        'fairplay' => array(
          'hdcp_enforcement' => $hdcpSettings['fairplay_hdcp'],
          'allow_airplay' => false,  // (AirPlay無効)
          'allow_av_adapter' => false  // (AVアダプター無効)
        )
      )
    )
  );

  // (AES-256-CBC暗号化)
  $tokenJson = json_encode($tokenArr, JSON_UNESCAPED_SLASHES);
  $tokenEnc = base64_encode(
    openssl_encrypt(
      $tokenJson,
      'AES-256-CBC',
      INKA_SITE_KEY,
      OPENSSL_RAW_DATA,
      INKA_IV
    )
  );

  // (SHA256ハッシュを生成)
  $hashStr = INKA_ACCESS_KEY . $drmType . INKA_SITE_ID . $clientUserId . $cid . $tokenEnc . $timestamp;
  $hash = base64_encode(hash("sha256", $hashStr, true));

  // (PallyConペイロードを構築)
  $inka_payload = array(
    'drm_type' => $drmType,
    'site_id' => INKA_SITE_ID,
    'user_id' => $clientUserId,
    'cid' => $cid,
    'token' => $tokenEnc,  // (暗号化されたトークン)
    'timestamp' => $timestamp,
    'hash' => $hash
  );

  return base64_encode(json_encode($inka_payload, JSON_UNESCAPED_SLASHES));
}

/**
 * (Kollus JWTを生成する関数)
 * @param string $clientUserId (クライアントユーザーID)
 * @param string $mckey (メディアコンテンツキー)
 * @param string $cid (コンテンツID)
 * @param int $hdcp_level (HDCPレベル)
 * @return string (JWTトークン)
 */
function createKollusJWT($clientUserId, $mckey, $cid, $hdcp_level)
{
  $streamingType = getStreamingType();

  // (getStreamingTypeがnullを返した場合はデフォルト値を使用)
  if (!$streamingType) {
    $streamingType = ["Widevine", "dash"];
  }

  // (JWTペイロードを構築)
  $payload = array(
    'expt' => time() + 86400,  // (有効期限: 24時間後)
    'cuid' => $clientUserId,
    'mc' => array(
      array(
        'mckey' => $mckey,
        'drm_policy' => array(
          'kind' => 'inka',  // (PallyCon DRM)
          'streaming_type' => $streamingType[1],
          'data' => array(
            'license_url' => 'https://license.pallycon.com/ri/licenseManager.do',
            'certificate_url' => 'https://license.pallycon.com/ri/fpsKeyManager.do?siteId=' . INKA_SITE_ID,
            'custom_header' => array(
              'key' => 'pallycon-customdata-v2',
              'value' => createInkaPayload($clientUserId, $cid, $hdcp_level),
            )
          )
        )
      )
    ),
  );

  // (HS256アルゴリズムでJWTをエンコード)
  return JWT::encode($payload, KOLLUS_SECURITY_KEY, 'HS256');
}

// (デバッグ情報を生成)
$streamingType = getStreamingType();

// (getStreamingTypeがnullを返した場合はデフォルト値を使用)
if (!$streamingType) {
  $streamingType = ["Widevine", "dash"];
}

$hdcpSettings = getHdcpSettings($hdcp_level);

// (デバッグ情報配列を構築)
$debug_info = array(
  'drm_type' => $streamingType[0],
  'streaming_type' => $streamingType[1],
  'hdcp_level' => $hdcp_level,
  'hdcp_label' => $hdcpSettings['label'],
  'widevine_hdcp' => $hdcpSettings['widevine_hdcp'],
  'playready_hdcp' => $hdcpSettings['playready_hdcp'],
  'playready_level' => $hdcpSettings['playready_level'],
  'fairplay_hdcp' => $hdcpSettings['fairplay_hdcp'],
  'disable_analog' => $hdcpSettings['disable_analog'] ? 'Yes' : 'No'
);

// (Policy JSON - デバッグ用)
$debug_policy_array = array(
  'policy_version' => 2,
  'playback_policy' => array('persistent' => false, 'license_duration' => 86400),
  'security_policy' => array(
    array(
      'track_type' => 'ALL',
      'widevine' => array(
        'security_level' => 1,
        'required_hdcp_version' => $hdcpSettings['widevine_hdcp'],
        'required_cgms_flags' => 'CGMS_NONE',
        'disable_analog_output' => $hdcpSettings['disable_analog'],
        'hdcp_srm_rule' => 'HDCP_SRM_RULE_NONE'
      ),
      'playready' => array(
        'security_level' => $hdcpSettings['playready_level'],
        'digital_video_protection_level' => 100,
        'require_hdcp_type_1' => ($hdcp_level >= 2)
      ),
      'fairplay' => array(
        'hdcp_enforcement' => $hdcpSettings['fairplay_hdcp'],
        'allow_airplay' => false,
        'allow_av_adapter' => false
      )
    )
  )
);
$debug_info['policy_json'] = json_encode($debug_policy_array, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

// (JWTと最終URLを生成)
$jwt = createKollusJWT($clientUserId, $mckey, $cid, $hdcp_level);
$debug_info['jwt'] = $jwt;
$final_url = "https://v.jp.kollus.com/s?jwt=$jwt&custom_key=" . KOLLUS_CUSTOM_KEY . "&debug_mode=true&s=0&player_v4_ver=4.1.34-alpha.11";
$debug_info['final_url'] = $final_url;
?>

<!DOCTYPE html>
<html lang="ja">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;600;700&display=swap" rel="stylesheet">
  <title>Kollus HDCP Multi-DRM Test</title>
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Noto Sans JP', sans-serif;
      background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
      color: #e2e8f0;
      line-height: 1.6;
      padding: 2rem;
      min-height: 100vh;
    }

    .container {
      max-width: 1200px;
      margin: 0 auto;
    }

    .header {
      text-align: center;
      margin-bottom: 2rem;
      padding-bottom: 1rem;
      border-bottom: 2px solid rgba(6, 182, 212, 0.3);
    }

    .header h1 {
      font-size: 2.5rem;
      color: #06b6d4;
      margin-bottom: 0.5rem;
      text-shadow: 0 0 20px rgba(6, 182, 212, 0.3);
    }

    .header .subtitle {
      font-size: 1rem;
      color: #94a3b8;
    }

    .warning-banner {
      background: linear-gradient(135deg, rgba(239, 68, 68, 0.1) 0%, rgba(220, 38, 38, 0.1) 100%);
      border: 2px solid rgba(239, 68, 68, 0.3);
      border-radius: 8px;
      padding: 1.5rem;
      margin-bottom: 1.5rem;
      color: #fca5a5;
    }

    .warning-banner strong {
      color: #ef4444;
      font-size: 1.1rem;
    }

    .warning-banner ul {
      margin-top: 0.75rem;
      margin-left: 1.5rem;
      line-height: 1.8;
    }

    .glass-panel {
      background: rgba(30, 41, 59, 0.6);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(148, 163, 184, 0.2);
      border-radius: 12px;
      padding: 2rem;
      margin-bottom: 1.5rem;
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
    }

    .section-title {
      font-size: 1.25rem;
      font-weight: 700;
      color: #06b6d4;
      margin-bottom: 1rem;
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding-bottom: 0.5rem;
      border-bottom: 1px solid rgba(6, 182, 212, 0.2);
    }

    .section-title::before {
      content: '';
      width: 10px;
      height: 10px;
      background: #06b6d4;
      border-radius: 50%;
      box-shadow: 0 0 10px rgba(6, 182, 212, 0.5);
    }

    .hdcp-description {
      font-size: 0.95rem;
      color: #cbd5e1;
      margin-bottom: 1.5rem;
      line-height: 1.6;
    }

    .hdcp-group {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 1rem;
      margin-top: 1rem;
    }

    .hdcp-card {
      background: rgba(15, 23, 42, 0.5);
      border: 2px solid rgba(100, 116, 139, 0.3);
      padding: 1.5rem;
      border-radius: 12px;
      cursor: pointer;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      text-align: center;
      position: relative;
      overflow: hidden;
    }

    .hdcp-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, #06b6d4, #0891b2);
      opacity: 0;
      transition: opacity 0.3s;
    }

    .hdcp-card:hover {
      border-color: rgba(6, 182, 212, 0.5);
      transform: translateY(-4px);
      box-shadow: 0 12px 24px rgba(6, 182, 212, 0.15);
    }

    .hdcp-card.active {
      border-color: #06b6d4;
      background: rgba(6, 182, 212, 0.15);
      box-shadow: 0 0 30px rgba(6, 182, 212, 0.3);
    }

    .hdcp-card.active::before {
      opacity: 1;
    }

    .hdcp-card label {
      cursor: pointer;
      font-weight: 700;
      font-size: 1.1rem;
      display: block;
      margin-bottom: 0.75rem;
      color: #f1f5f9;
    }

    .hdcp-card .description {
      font-size: 0.85rem;
      color: #94a3b8;
      line-height: 1.4;
    }

    .hdcp-card.active .status-badge {
      display: inline-block;
      margin-top: 0.75rem;
      padding: 0.35rem 0.75rem;
      background: #06b6d4;
      color: #0f172a;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 700;
    }

    .player-wrapper {
      position: relative;
    }

    .player-container {
      background: #000;
      border-radius: 8px;
      overflow: hidden;
      aspect-ratio: 16/9;
      margin-top: 1rem;
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.6);
    }

    iframe {
      width: 100%;
      height: 100%;
      border: none;
    }

    .debug-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      gap: 1rem;
      margin-top: 1.5rem;
    }

    .debug-item {
      background: rgba(15, 23, 42, 0.6);
      padding: 1rem;
      border-radius: 8px;
      border-left: 3px solid #06b6d4;
    }

    .debug-label {
      font-size: 0.8rem;
      color: #64748b;
      margin-bottom: 0.5rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .debug-value {
      font-size: 1.1rem;
      font-weight: 600;
      color: #22c55e;
      font-family: monospace;
    }

    .code-block {
      background: rgba(15, 23, 42, 0.9);
      padding: 1.5rem;
      border-radius: 8px;
      margin-top: 1rem;
      border: 1px solid rgba(148, 163, 184, 0.2);
      overflow-x: auto;
    }

    .code-block pre {
      color: #22c55e;
      font-family: 'Courier New', monospace;
      font-size: 0.85rem;
      line-height: 1.6;
      white-space: pre-wrap;
      word-wrap: break-word;
    }

    textarea {
      width: 100%;
      min-height: 150px;
      background: rgba(15, 23, 42, 0.9);
      border: 1px solid rgba(148, 163, 184, 0.3);
      border-radius: 8px;
      color: #22c55e;
      font-family: 'Courier New', monospace;
      padding: 1rem;
      margin-top: 1rem;
      font-size: 0.9rem;
      resize: vertical;
    }

    textarea::placeholder {
      color: #475569;
    }

    .button-group {
      display: flex;
      gap: 0.75rem;
      margin-top: 1rem;
      flex-wrap: wrap;
    }

    .btn {
      padding: 0.75rem 1.5rem;
      background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
      color: #fff;
      border: none;
      border-radius: 8px;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.2s;
      box-shadow: 0 4px 12px rgba(6, 182, 212, 0.3);
    }

    .btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(6, 182, 212, 0.4);
    }

    .btn:active {
      transform: translateY(0);
    }

    .btn-secondary {
      background: linear-gradient(135deg, #64748b 0%, #475569 100%);
      box-shadow: 0 4px 12px rgba(100, 116, 139, 0.3);
    }

    .env-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.5rem 1rem;
      background: rgba(34, 197, 94, 0.1);
      border: 1px solid rgba(34, 197, 94, 0.3);
      border-radius: 20px;
      font-size: 0.85rem;
      margin-top: 1rem;
    }

    .env-badge::before {
      content: '●';
      color: #22c55e;
      font-size: 1.2rem;
    }
  </style>
</head>

<body>
  <div class="container">
    <div class="header">
      <h1>Kollus Multi-DRM HDCP Test</h1>
      <p class="subtitle">Widevine / PlayReady / FairPlay 統合テスト環境</p>
      <div class="env-badge">
        Current Environment: <?php echo $debug_info['drm_type']; ?> (<?php echo $debug_info['streaming_type']; ?>)
      </div>
    </div>

    <div class="warning-banner">
      <strong>テスト前の重要な注意事項</strong>
      <ul>
        <li><strong>Chrome/Firefox:</strong> ハードウェアアクセラレーションを有効にしてください（chrome://settings）</li>
        <li><strong>HDCP Level 1以上:</strong> HDCP非対応モニターでは再生できません</li>
        <li><strong>macOS Chrome:</strong> Widevine L3のみ対応 → HDCP制御不可</li>
        <li><strong>Windows Edge:</strong> PlayReady DRM適用 → HDCP制御可能</li>
      </ul>
    </div>

    <div class="glass-panel">
      <h2 class="section-title">HDCP レベル設定</h2>
      <p class="hdcp-description">
        テストしたいHDCP保護レベルを選択してください。<br>
        選択すると、該当するDRM（Widevine/PlayReady/FairPlay）に自動的に設定が適用されます。
      </p>

      <div class="hdcp-group">
        <div class="hdcp-card <?php echo $hdcp_level == 0 ? 'active' : ''; ?>" onclick="updateHdcp(0)">
          <label>HDCP なし</label>
          <div class="description">
            すべてのモニター対応<br>
            最大互換性
          </div>
          <?php if ($hdcp_level == 0): ?>
            <div class="status-badge">適用中</div>
          <?php endif; ?>
        </div>

        <div class="hdcp-card <?php echo $hdcp_level == 1 ? 'active' : ''; ?>" onclick="updateHdcp(1)">
          <label>HDCP 1.4</label>
          <div class="description">
            FHD保護<br>
            一般的なセキュリティ
          </div>
          <?php if ($hdcp_level == 1): ?>
            <div class="status-badge">適用中</div>
          <?php endif; ?>
        </div>

        <div class="hdcp-card <?php echo $hdcp_level == 2 ? 'active' : ''; ?>" onclick="updateHdcp(2)">
          <label>HDCP 2.2</label>
          <div class="description">
            4K保護 + アナログ出力禁止<br>
            最高セキュリティ
          </div>
          <?php if ($hdcp_level == 2): ?>
            <div class="status-badge">適用中</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="glass-panel">
      <h2 class="section-title">プレイヤーテスト</h2>
      <p style="font-size: 0.9rem; color: #cbd5e1; margin-bottom: 1rem;">
        <strong>テスト手順:</strong><br>
        1. 内蔵ディスプレイで再生を確認<br>
        2. ブラウザウィンドウをHDMI外部モニターに移動<br>
        3. 再生が継続するか確認（HDCP非対応なら黒画面）
      </p>

      <div class="player-wrapper">
        <div class="player-container">
          <iframe id="playerFrame" src="<?php echo $final_url; ?>"
            allow="encrypted-media; fullscreen; autoplay"></iframe>
        </div>
      </div>
    </div>

    <div class="glass-panel">
      <h2 class="section-title">現在のDRM設定</h2>

      <div class="debug-grid">
        <div class="debug-item">
          <div class="debug-label">DRM Type</div>
          <div class="debug-value"><?php echo $debug_info['drm_type']; ?></div>
        </div>

        <div class="debug-item">
          <div class="debug-label">Streaming</div>
          <div class="debug-value"><?php echo $debug_info['streaming_type']; ?></div>
        </div>

        <div class="debug-item">
          <div class="debug-label">HDCP Level</div>
          <div class="debug-value"><?php echo $debug_info['hdcp_level']; ?></div>
        </div>

        <div class="debug-item">
          <div class="debug-label">HDCP Label</div>
          <div class="debug-value"><?php echo $debug_info['hdcp_label']; ?></div>
        </div>
      </div>

      <div class="debug-grid" style="margin-top: 1rem;">
        <div class="debug-item">
          <div class="debug-label">Widevine HDCP</div>
          <div class="debug-value"><?php echo $debug_info['widevine_hdcp']; ?></div>
        </div>

        <div class="debug-item">
          <div class="debug-label">PlayReady HDCP</div>
          <div class="debug-value"><?php echo $debug_info['playready_hdcp']; ?></div>
        </div>

        <div class="debug-item">
          <div class="debug-label">PlayReady Level</div>
          <div class="debug-value">SL<?php echo $debug_info['playready_level']; ?></div>
        </div>

        <div class="debug-item">
          <div class="debug-label">FairPlay HDCP</div>
          <div class="debug-value"><?php echo $debug_info['fairplay_hdcp']; ?></div>
        </div>

        <div class="debug-item">
          <div class="debug-label">Analog Output</div>
          <div class="debug-value"><?php echo $debug_info['disable_analog']; ?></div>
        </div>
      </div>

      <div class="code-block">
        <div style="color: #f59e0b; font-weight: bold; margin-bottom: 0.5rem;">
          Policy JSON (暗号化前):
        </div>
        <pre><?php echo htmlspecialchars($debug_info['policy_json']); ?></pre>
      </div>
    </div>

    <div class="glass-panel">
      <h2 class="section-title">デバッグ & トラブルシューティング</h2>
      <p style="font-size: 0.9rem; color: #cbd5e1;">
        再生に問題がある場合、F12キーで開発者ツールを開き、<br>
        <strong>Console</strong>タブのエラーメッセージを下のテキストエリアに貼り付けてください。
      </p>

      <textarea id="consoleLogs" placeholder="開発者ツール（F12）→ Console タブのログをここに貼り付けてください..."></textarea>

      <div class="button-group">
        <button class="btn" onclick="downloadLog()">
          ログをダウンロード
        </button>
        <button class="btn btn-secondary" onclick="copySystemInfo()">
          システム情報をコピー
        </button>
        <button class="btn btn-secondary" onclick="showJWT()">
          JWT表示
        </button>
      </div>
    </div>
  </div>

  <script>
    // (HDCPレベル変更関数)
    function updateHdcp(level) {
      const url = new URL(window.location.href);
      url.searchParams.set('hdcp_level', level);
      window.location.href = url.href;
    }

    // (システム情報収集関数)
    function getSystemInfo() {
      return `
========================================
    KOLLUS HDCP TEST REPORT
========================================

Date: ${new Date().toISOString()}
HDCP Level: <?php echo $debug_info['hdcp_level']; ?> (<?php echo $debug_info['hdcp_label']; ?>)

----------------------------------------
DRM Configuration
----------------------------------------
DRM Type:         <?php echo $debug_info['drm_type']; ?>
Streaming:        <?php echo $debug_info['streaming_type']; ?>

Widevine Settings:
  HDCP Version:   <?php echo $debug_info['widevine_hdcp']; ?>
  Security Lvl:   1

PlayReady Settings:
  HDCP Version:   <?php echo $debug_info['playready_hdcp']; ?>
  Security Lvl:   SL<?php echo $debug_info['playready_level']; ?>
  HDCP Type 1:    <?php echo ($hdcp_level >= 2) ? 'Required' : 'Not Required'; ?>

FairPlay Settings:
  HDCP Enforce:   <?php echo $debug_info['fairplay_hdcp']; ?>
  AirPlay:        Disabled
  AV Adapter:     Disabled

Output Protection:
  Analog Output:  <?php echo $debug_info['disable_analog']; ?>

----------------------------------------
Browser Environment
----------------------------------------
User Agent: ${navigator.userAgent}
Screen:     ${screen.width}x${screen.height}
Color:      ${screen.colorDepth}-bit
DPR:        ${window.devicePixelRatio}
Secure:     ${window.isSecureContext}

----------------------------------------
Console Logs
----------------------------------------
`;
    }

    // (ログダウンロード関数)
    function downloadLog() {
      const logs = document.getElementById('consoleLogs').value;
      const content = getSystemInfo() + logs;
      const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `kollus_hdcp${<?php echo $hdcp_level; ?>}_${Date.now()}.txt`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      window.URL.revokeObjectURL(url);
    }

    // (システム情報コピー関数)
    function copySystemInfo() {
      const info = getSystemInfo();
      navigator.clipboard.writeText(info).then(() => {
        alert('システム情報をクリップボードにコピーしました');
      }).catch(err => {
        console.error('Copy failed:', err);
        alert('コピーに失敗しました。手動でテキストエリアからコピーしてください。');
      });
    }

    // (JWT表示関数)
    function showJWT() {
      const jwt = '<?php echo $debug_info['jwt']; ?>';
      const textarea = document.getElementById('consoleLogs');
      textarea.value = '=== KOLLUS JWT ===\n' + jwt + '\n\n' +
        '=== JWT Decoded (Header.Payload) ===\n' +
        'ヘッダーとペイロードをjwt.ioで確認してください:\n' +
        'https://jwt.io/#debugger-io?token=' + encodeURIComponent(jwt);
      alert('JWTをテキストエリアに出力しました');
    }

    // (自動エラーキャプチャ)
    window.addEventListener('error', (e) => {
      const logs = document.getElementById('consoleLogs');
      const timestamp = new Date().toISOString();
      logs.value += `\n[${timestamp}] [ERROR] ${e.message}\n  at ${e.filename}:${e.lineno}:${e.colno}`;
    });

    // (DRM機能チェック)
    console.group('%cDRM Capability Check', 'color: #06b6d4; font-size: 16px; font-weight: bold;');

    // (Widevine L1/L3チェック)
    if (navigator.requestMediaKeySystemAccess) {
      // (L1テスト)
      navigator.requestMediaKeySystemAccess('com.widevine.alpha', [{
        initDataTypes: ['cenc'],
        videoCapabilities: [{
          contentType: 'video/mp4; codecs="avc1.42E01E"',
          robustness: 'HW_SECURE_ALL'
        }]
      }])
        .then(() => {
          console.log('%cWidevine L1 Supported', 'color: #22c55e; font-weight: bold;');
          console.log('%c  -> HDCP control AVAILABLE', 'color: #22c55e;');
        })
        .catch(() => {
          console.log('%cWidevine L3 Only', 'color: #fbbf24; font-weight: bold;');
          console.log('%c  -> HDCP control NOT AVAILABLE', 'color: #fbbf24;');

          // (L3チェック)
          navigator.requestMediaKeySystemAccess('com.widevine.alpha', [{
            initDataTypes: ['cenc'],
            videoCapabilities: [{
              contentType: 'video/mp4; codecs="avc1.42E01E"',
              robustness: 'SW_SECURE_CRYPTO'
            }]
          }])
            .then(() => console.log('%cWidevine L3 Confirmed', 'color: #22c55e;'))
            .catch(() => console.log('%cWidevine Not Supported', 'color: #ef4444;'));
        });

      // (PlayReadyチェック)
      navigator.requestMediaKeySystemAccess('com.microsoft.playready', [{
        initDataTypes: ['cenc'],
        videoCapabilities: [{
          contentType: 'video/mp4; codecs="avc1.42E01E"'
        }]
      }])
        .then(() => {
          console.log('%cPlayReady Supported', 'color: #22c55e; font-weight: bold;');
          console.log('%c  -> HDCP control AVAILABLE', 'color: #22c55e;');
        })
        .catch(() => {
          console.log('%cPlayReady Not Supported', 'color: #64748b;');
        });

      // (FairPlayチェック)
      if (window.WebKitMediaKeys) {
        console.log('%cFairPlay Supported', 'color: #22c55e; font-weight: bold;');
        console.log('%c  -> HDCP control AVAILABLE', 'color: #22c55e;');
      } else {
        console.log('%cFairPlay Not Supported', 'color: #64748b;');
      }
    }

    console.groupEnd();

    // (現在の設定を出力)
    console.group('%cCurrent HDCP Configuration', 'color: #06b6d4; font-size: 16px; font-weight: bold;');
    console.log('%cHDCP Level:', 'color: #f59e0b; font-weight: bold;', <?php echo $debug_info['hdcp_level']; ?>);
    console.log('%cDRM Type:', 'color: #f59e0b; font-weight: bold;', '<?php echo $debug_info['drm_type']; ?>');
    console.log('%cWidevine HDCP:', 'color: #f59e0b; font-weight: bold;', '<?php echo $debug_info['widevine_hdcp']; ?>');
    console.log('%cPlayReady HDCP:', 'color: #f59e0b; font-weight: bold;', '<?php echo $debug_info['playready_hdcp']; ?>');
    console.log('%cPlayReady Level:', 'color: #f59e0b; font-weight: bold;', 'SL<?php echo $debug_info['playready_level']; ?>');
    console.log('%cFairPlay HDCP:', 'color: #f59e0b; font-weight: bold;', <?php echo $debug_info['fairplay_hdcp']; ?>);
    console.log('%cAnalog Output:', 'color: #f59e0b; font-weight: bold;', '<?php echo $debug_info['disable_analog']; ?>');
    console.groupEnd();

    // (Policy JSONを出力)
    console.group('%cPolicy JSON (暗号化前)', 'color: #22c55e; font-size: 14px; font-weight: bold;');
    console.log(<?php echo $debug_info['policy_json']; ?>);
    console.groupEnd();

    // (初期ログ)
    console.log('%c=======================================================', 'color: #06b6d4;');
    console.log('%cHDCP Test Started', 'color: #06b6d4; font-size: 16px; font-weight: bold;');
    console.log('%c=======================================================', 'color: #06b6d4;');
  </script>
</body>

</html>