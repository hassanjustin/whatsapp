<?php
// === DB CONNECTION ===
$mysqli = new mysqli('localhost', 'bot26', 'bot26', 'bot26');
if ($mysqli->connect_error) {
    die("Database connection failed: " . $mysqli->connect_error);
}

// === Step 1: Webhook Verification ===
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $verify_token = '0';
    if ($_GET['hub_verify_token'] === $verify_token) {
        echo $_GET['hub_challenge'];
        exit;
    } else {
        echo 'Invalid verify token';
        exit;
    }
}

// === Step 2: Handle Webhook POST Request ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json = file_get_contents('php://input');
    file_put_contents('log.json', $json);

    $data = json_decode($json, true);
    $message = $data['entry'][0]['changes'][0]['value']['messages'][0] ?? null;

    if ($message) {
        $from = $message['from'];
        $type = $message['type'];
        $number_id = $data['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] ?? '';
        $send_type = isset($message['from']) ? 'receiver' : 'sender'; // Most incoming messages have 'from'

        // === Handle Text Messages ===
        if ($type === 'text') {
            $text = $message['text']['body'] ?? '';
            file_put_contents('messages.txt', "$from: $text\n", FILE_APPEND);

            $stmt = $mysqli->prepare("INSERT INTO message (wa_id, message_type, text_content, number_id, send_type) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $from, $type, $text, $number_id, $send_type);
            $stmt->execute();

            if ($stmt->error) {
                file_put_contents('messages.txt', "❌ TEXT INSERT ERROR: " . $stmt->error . "\n", FILE_APPEND);
            }

            $stmt->close();
        }

        // === Handle Media Messages ===
        elseif (in_array($type, ['image', 'video', 'document', 'audio'])) {
            $mediaId = $message[$type]['id'];
            $mimeType = $message[$type]['mime_type'] ?? 'application/octet-stream';
            $ext = explode('/', $mimeType)[1] ?? 'bin';
            $filename = "media_$mediaId.$ext";

            downloadMediaFromWhatsApp($mediaId, $filename, $number_id);
            file_put_contents('messages.txt', "$from: sent $type -> $filename\n", FILE_APPEND);

            $stmt = $mysqli->prepare("INSERT INTO message (wa_id, message_type, media_id, media_type, media_file, mime_type, number_id, send_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssss", $from, $type, $mediaId, $type, $filename, $mimeType, $number_id, $send_type);
            $stmt->execute();

            if ($stmt->error) {
                file_put_contents('messages.txt', "❌ MEDIA INSERT ERROR: " . $stmt->error . "\n", FILE_APPEND);
            }

            $stmt->close();
        }
    }

    http_response_code(200);
    echo "EVENT_RECEIVED";
    $mysqli->close();
    exit;
}

// === Step 3: Download Media Function ===
function downloadMediaFromWhatsApp($mediaId, $filename, $phone_number_id) {
    global $mysqli;

    $stmt = $mysqli->prepare("SELECT access_token FROM wa_accounts WHERE phone_number_id = ?");
    $stmt->bind_param("s", $phone_number_id);
    $stmt->execute();
    $stmt->bind_result($accessToken);
    $stmt->fetch();
    $stmt->close();

    if (!$accessToken) {
        file_put_contents('messages.txt', "❌ Access token not found for phone ID $phone_number_id\n", FILE_APPEND);
        return;
    }

    $url = "https://graph.facebook.com/v19.0/$mediaId";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $accessToken"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);

    $response = json_decode($result, true);
    $mediaUrl = $response['url'] ?? null;

    if (!$mediaUrl) {
        file_put_contents('messages.txt', "❌ Failed to get media URL for $mediaId\n", FILE_APPEND);
        return;
    }

    $ch = curl_init($mediaUrl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $accessToken"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $mediaData = curl_exec($ch);
    curl_close($ch);

    if (!is_dir('downloads')) {
        mkdir('downloads', 0755, true);
    }

    if ($mediaData) {
        file_put_contents("downloads/$filename", $mediaData);
    } else {
        file_put_contents('messages.txt', "❌ Failed to download media for $mediaId\n", FILE_APPEND);
    }
}
?>
