<?php
/****************************************************
 * INITIAL CONFIGURATION & ADMIN UPDATE HANDLING
 ****************************************************/
ini_set('display_errors', 1);
error_reporting(E_ALL);

$config_file = __DIR__ . '/config.json';
if (!file_exists($config_file)) {
    $default_config = [
        'app_id'                    => '8772483359504484',
        'app_secret'                => 'ec6ae8ae80a1bdbd7366933bce5a57816',
        'redirect_uri'              => 'https://bot26.bepretty-store.com/wa_manager.php',
        'embedded_signup_config_id' => '1241242264039675'
    ];
    file_put_contents($config_file, json_encode($default_config, JSON_PRETTY_PRINT));
}
$config = json_decode(file_get_contents($config_file), true);

// Handle admin config updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_config') {
    $new_config = [
        'app_id'                    => trim($_POST['app_id'] ?? ''),
        'app_secret'                => trim($_POST['app_secret'] ?? ''),
        'redirect_uri'              => trim($_POST['redirect_uri'] ?? ''),
        'embedded_signup_config_id' => trim($_POST['embedded_signup_config_id'] ?? '')
    ];
    file_put_contents($config_file, json_encode($new_config, JSON_PRETTY_PRINT));
    $uid = intval($_GET['user_id'] ?? 0);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?user_id=' . $uid . '&config_updated=1');
    exit;
}

define('APP_ID',                    $config['app_id']);
define('APP_SECRET',                $config['app_secret']);
define('REDIRECT_URI',              $config['redirect_uri']);
define('EMBEDDED_SIGNUP_CONFIG_ID', $config['embedded_signup_config_id']);

// Validate user_id
$userId = intval($_GET['user_id'] ?? 0);
if ($userId < 1) {
    die('Error: user_id parameter is required and must be a positive integer.');
}

/****************************************************
 * DATABASE CONNECTION
 ****************************************************/
try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=bot26;charset=utf8',
        'bot26', 'bot26',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    die('DB Connection failed: ' . $e->getMessage());
}

/****************************************************
 * HELPER FUNCTIONS
 ****************************************************/
function doGetRequest($url) {
    $resp = @file_get_contents($url);
    if ($resp === false) {
        return ['error' => true, 'message' => "GET failed: $url"];
    }
    return json_decode($resp, true);
}

function doPostRequest($url, $body, $headers = []) {
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", $headers),
            'content' => json_encode($body)
        ]
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) {
        global $http_response_header;
        $err = is_array($http_response_header) ? implode("\n", $http_response_header) : 'Unknown';
        return ['error' => true, 'message' => $err];
    }
    return json_decode($resp, true);
}

/****************************************************
 * EMBEDDED SIGNUP JSON FLOW
 ****************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    header('Content-Type: application/json');

    // Log incoming payload for debugging
    $raw = file_get_contents('php://input');
    file_put_contents(__DIR__ . '/wa_debug.log', date('c') . " USER={$userId} JSON-IN: {$raw}\n", FILE_APPEND);

    $pl = json_decode($raw, true);

    // Validate required parameters
    $missingParams = [];
    if (empty($pl['code'])) $missingParams[] = 'code';
    if (empty($pl['phone_number_id'])) $missingParams[] = 'phone_number_id';
    if (empty($pl['waba_id'])) $missingParams[] = 'waba_id';

    if (!empty($missingParams)) {
        $errorMessage = 'Missing parameters: ' . implode(', ', $missingParams);
        echo json_encode(['error' => $errorMessage]);
        exit;
    }

    $code = $pl['code'];
    $pid  = $pl['phone_number_id'];
    $waba = $pl['waba_id'];

    // Exchange code for short-lived token
    $url1 = "https://graph.facebook.com/v22.0/oauth/access_token?" . http_build_query([
        'client_id'     => APP_ID,
        'client_secret' => APP_SECRET,
        'redirect_uri'  => '',
        'code'          => $code
    ]);
    $t1 = doGetRequest($url1);
    if (!empty($t1['error'])) {
        echo json_encode(['error' => "Code exchange failed: {$t1['error']['message']}"]);
        exit;
    }
    $short = $t1['access_token'];

    // Exchange short-lived token for long-lived token
    $url2 = "https://graph.facebook.com/v22.0/oauth/access_token?" . http_build_query([
        'grant_type'        => 'fb_exchange_token',
        'client_id'         => APP_ID,
        'client_secret'     => APP_SECRET,
        'fb_exchange_token' => $short
    ]);
    $t2 = doGetRequest($url2);
    if (!empty($t2['error'])) {
        echo json_encode(['error' => "Long token failed: {$t2['error']['message']}"]);
        exit;
    }
    $long = $t2['access_token'];

    // Subscribe app
    $subUrl = "https://graph.facebook.com/v17.0/{$waba}/subscribed_apps";
    $ch = curl_init($subUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$long}"]);
    $subRes = curl_exec($ch);
    if (curl_errno($ch)) {
        echo json_encode(['error' => 'Subscribe error: ' . curl_error($ch)]);
        exit;
    }
    curl_close($ch);
    $subData = json_decode($subRes, true);

    // Fetch display phone number
    $pUrl = "https://graph.facebook.com/v22.0/{$waba}/phone_numbers?" . "fields=id,display_phone_number&access_token={$long}";
    $phones = doGetRequest($pUrl);
    $disp = '';
    if (!empty($phones['data'])) {
        foreach ($phones['data'] as $r) {
            if ($r['id'] === $pid) {
                $disp = $r['display_phone_number'];
                break;
            }
        }
    }

    // Store in database
    try {
        $ins = $pdo->prepare("
            INSERT INTO wa_accounts
              (uaer_id, business_account_id, phone_number_id, phone_number, access_token)
            VALUES
              (:uid, :w, :p, :n, :t)
        ");
        $ins->execute([
            ':uid' => $userId,
            ':w'   => $waba,
            ':p'   => $pid,
            ':n'   => $disp,
            ':t'   => $long
        ]);
        $rows = $ins->rowCount();
        if ($rows) {
            echo json_encode([
                'success'              => true,
                'subscription_status'  => $subData['success'] ?? false,
                'subscribe_response'   => $subData,
                'phone_number_id'      => $pid,
                'display_phone_number' => $disp,
                'business_id'          => $waba,
                'access_token'         => $long
            ]);
        } else {
            echo json_encode(['error' => 'DB insert returned 0 rows']);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => 'DB Insert Exception: ' . $e->getMessage()]);
    }
    exit;
}

/****************************************************
 * PIN VERIFICATION FORM
 ****************************************************/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_phone') {
    $pin = trim($_POST['pin'] ?? '');
    $row = $pdo->query("SELECT phone_number_id, access_token, phone_number, business_account_id FROM wa_accounts ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) die('No account found; run signup first.');

    $pid  = $row['phone_number_id'];
    $tok  = $row['access_token'];
    $disp = $row['phone_number'];
    $bus  = $row['business_account_id'];

    $regUrl = "https://graph.facebook.com/v22.0/{$pid}/register";
    $resp = doPostRequest(
        $regUrl,
        ['messaging_product' => 'whatsapp', 'pin' => $pin],
        ['Content-Type: application/json', 'Authorization: Bearer ' . $tok]
    );
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <title>PIN Verification Result</title>
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="p-5 bg-light">
      <div class="container">
        <div class="card">
          <div class="card-body">
            <?php if (!empty($resp['error'])): ?>
              <div class="alert alert-danger"><?=htmlspecialchars($resp['message'] ?? $resp['error'])?></div>
            <?php else: ?>
              <div class="alert alert-success">Phone verified!</div>
              <ul class="list-group">
                <li class="list-group-item"><strong>Business ID:</strong> <?=$bus?></li>
                <li class="list-group-item"><strong>Phone Number ID:</strong> <?=$pid?></li>
                <li class="list-group-item"><strong>WhatsApp Number:</strong> <?=$disp?></li>
              </ul>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </body>
    </html>
    <?php
    exit;
}

// Dashboard
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>WhatsApp Embedded Signup Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="py-5 bg-light">
  <div class="container">
    <!-- Configuration Panel -->
    <div class="card mb-4">
      <div class="card-body">
        <h5>Configuration</h5>
        <?php if (isset($_GET['config_updated'])): ?>
          <div class="alert alert-success">Configuration saved!</div>
        <?php endif; ?>
        <form method="POST">
          <input type="hidden" name="action" value="update_config">
          <div class="mb-3">
            <label>App ID</label>
            <input name="app_id" class="form-control" required value="<?=htmlspecialchars(APP_ID)?>">
          </div>
          <div class="mb-3">
            <label>App Secret</label>
            <input name="app_secret" class="form-control" required value="<?=htmlspecialchars(APP_SECRET)?>">
          </div>
          <div class="mb-3">
            <label>Redirect URI</label>
            <input name="redirect_uri" class="form-control" required value="<?=htmlspecialchars(REDIRECT_URI)?>">
          </div>
          <div class="mb-3">
            <label>Embedded Signup Config ID</label>
            <input name="embedded_signup_config_id" class="form-control" required value="<?=htmlspecialchars(EMBEDDED_SIGNUP_CONFIG_ID)?>">
          </div>
          <button class="btn btn-primary">Save</button>
        </form>
      </div>
    </div>

    <!-- Embedded Signup Button -->
    <div class="card mb-4">
      <div class="card-body text-center">
        <button id="startBtn" class="btn btn-success btn-lg">Start WhatsApp Embedded Signup</button>
      </div>
    </div>

    <!-- PIN Verification -->
    <div class="card">
      <div class="card-body">
        <h5>Verify PIN</h5>
        <form method="POST" class="row gx-2">
          <input type="hidden" name="action" value="verify_phone">
          <div class="col-auto">
            <input name="pin" class="form-control" placeholder="6-digit PIN" required>
          </div>
          <div class="col-auto">
            <button class="btn btn-primary">Verify</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Facebook SDK + JS -->
  <script async defer crossorigin="anonymous" src="https://connect.facebook.net/en_US/sdk.js"></script>
  <script>
    const APP_ID = '<?=APP_ID?>';
    const EMBEDDED_SIGNUP_CONFIG_ID = '<?=EMBEDDED_SIGNUP_CONFIG_ID?>';
    const userId = <?= $userId ?>;
    const endpoint = window.location.pathname + '?user_id=' + userId;

    window.fbAsyncInit = function() {
        FB.init({ appId: APP_ID, version: 'v22.0', xfbml: true, autoLogAppEvents: true });

        document.getElementById('startBtn').addEventListener('click', () => {
            FB.login(resp => {
                if (resp.authResponse && resp.authResponse.code) {
                    window.lastFBAuthCode = resp.authResponse.code;
                } else {
                    alert('Authentication failed or was canceled.');
                }
            }, {
                config_id: EMBEDDED_SIGNUP_CONFIG_ID,
                response_type: 'code',
                override_default_response_type: true,
                extras: { setup: {}, featureType: '', sessionInfoVersion: '2' }
            });
        });
    };

    window.addEventListener('message', e => {
        if (!['https://www.facebook.com', 'https://web.facebook.com'].includes(e.origin)) return;
        let d;
        try {
            d = JSON.parse(e.data);
        } catch {
            return;
        }
        if (d.type === 'WA_EMBEDDED_SIGNUP' && d.event === 'FINISH') {
            const code = window.lastFBAuthCode;
            const phone_number_id = d.data.phone_number_id;
            const waba_id = d.data.waba_id;

            // Validate required parameters
            if (!code || !phone_number_id || !waba_id) {
                let missingParams = [];
                if (!code) missingParams.push('code');
                if (!phone_number_id) missingParams.push('phone_number_id');
                if (!waba_id) missingParams.push('waba_id');
                alert('Missing required data: ' + missingParams.join(', '));
                return;
            }

            fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ code, phone_number_id, waba_id })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('✅ Saved to DB!\nBusiness ID: ' + data.business_id);
                } else {
                    alert('❌ Error: ' + (data.error || 'unknown'));
                }
            })
            .catch(err => {
                alert('🚨 Fetch error: ' + err);
            });
        }
    });
  </script>
</body>
</html>