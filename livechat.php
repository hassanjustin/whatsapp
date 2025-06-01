<?php
/* =========================================================================
   livechat.php – one-file WhatsApp live-chat dashboard
   ========================================================================= */

/* ---------- 1. DB connection ---------- */
$mysqli = new mysqli('localhost', 'bot26', 'bot26', 'bot26');
if ($mysqli->connect_error) die('DB error: '.$mysqli->connect_error);

/* ---------- 2. choose current WA account ---------- */
$account_id = $_GET['account_id'] ?? null;
$accounts   = $mysqli->query("SELECT * FROM wa_accounts")->fetch_all(MYSQLI_ASSOC);
if (!$account_id && $accounts) $account_id = $accounts[0]['phone_number_id'];

if ($account_id) {
    $st = $mysqli->prepare("SELECT * FROM wa_accounts WHERE phone_number_id=? LIMIT 1");
    $st->bind_param('s', $account_id); $st->execute();
    $waAcc = $st->get_result()->fetch_assoc(); $st->close();
    if (!$waAcc) die('No WhatsApp account found.');
    define('PHONE_ID',  $waAcc['phone_number_id']);
    define('ACCESS_TKN', $waAcc['access_token']);
} else { $waAcc = null; }

$user_id = $_GET['user_id'] ?? null;

/* ---------- 3. send a message ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && $user_id && $waAcc) {

    $type = $_POST['send_type'];            // text | image | video
    $body = $_POST['body'] ?? '';
    $file = $_FILES['media'] ?? null;

    /* 3-A.  for media: save locally + upload to /media */
    $mediaName = null; $mimeType = null; $mediaId = null;
    if ($type!=='text' && $file && $file['tmp_name']) {
        $mimeType  = $file['type'];
        $mediaName = time().'_'.basename($file['name']);

        /* local copy for preview inside chat */
        $dir = __DIR__.'/downloads/';
        if (!is_dir($dir)) mkdir($dir,0755,true);
        $localPath = $dir.$mediaName;
        move_uploaded_file($file['tmp_name'], $localPath);

        /* upload to WhatsApp media endpoint */
        $cFile = new CURLFile($localPath, $mimeType, $file['name']);
        $chUp  = curl_init("https://graph.facebook.com/v22.0/".PHONE_ID."/media?access_token=".ACCESS_TKN);
        curl_setopt_array($chUp,[
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => [
                'messaging_product' => 'whatsapp',
                'file'              => $cFile
            ],
        ]);
        $upResp = json_decode(curl_exec($chUp), true);
        curl_close($chUp);
        if (isset($upResp['id'])) $mediaId = $upResp['id'];   // success
    }

    /* 3-B. build final payload */
    $payload = ['messaging_product'=>'whatsapp','to'=>$user_id,'type'=>$type];
    if ($type==='text') {
        $payload['text'] = ['body'=>$body];
    } else {
        /* prefer id, fallback to public link (rare) */
        if ($mediaId)             $payload[$type] = ['id'=>$mediaId];
        else                      $payload[$type] = ['link'=>publicUrl($mediaName)];
        if ($body) $payload[$type]['caption'] = $body;
    }

    /* 3-C. call Graph API */
    $ch = curl_init("https://graph.facebook.com/v22.0/".PHONE_ID."/messages?access_token=".ACCESS_TKN);
    curl_setopt_array($ch,[CURLOPT_POST=>true,
                           CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
                           CURLOPT_POSTFIELDS=>json_encode($payload),
                           CURLOPT_RETURNTRANSFER=>true]);
    $apiResp = curl_exec($ch); curl_close($ch);

    /* 3-D. store in DB  (bind vars, not constants) */
    $ins = $mysqli->prepare("INSERT INTO message
         (wa_id,message_type,text_content,media_file,mime_type,created_at,number_id,send_type)
         VALUES (?,?,?,?,?,?,?,?)");
    $createdAt = date('Y-m-d H:i:s');
    $numberId  = PHONE_ID;
    $sendType  = 'sender';
    $ins->bind_param('ssssssss',$user_id,$type,$body,$mediaName,$mimeType,$createdAt,$numberId,$sendType);
    $ins->execute(); $ins->close();

    header('Location: '.$_SERVER['PHP_SELF'].'?account_id='.urlencode($account_id).'&user_id='.urlencode($user_id));
    exit;
}

/* ---------- 4. AJAX: return messages JSON ---------- */
if (isset($_GET['fetch']) && $user_id && $account_id) {
    $st = $mysqli->prepare("SELECT * FROM message WHERE wa_id=? AND number_id=? ORDER BY id ASC");
    $st->bind_param('ss',$user_id,$account_id); $st->execute();
    echo json_encode($st->get_result()->fetch_all(MYSQLI_ASSOC));
    exit;
}

/* ---------- 5. helpers ---------- */
function publicUrl($fileName){
    $scheme = (!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
    return $scheme.'://'.$_SERVER['HTTP_HOST'].'/downloads/'.$fileName;
}

/* ---------- 6. sidebar lists ---------- */
$clients=[];
if($account_id){
    $st=$mysqli->prepare("SELECT DISTINCT wa_id FROM message WHERE number_id=?");
    $st->bind_param('s',$account_id);$st->execute();
    $clients=$st->get_result()->fetch_all(MYSQLI_ASSOC);$st->close();
}

/* ---------- convenience ---------- */
$self   = basename($_SERVER['PHP_SELF']);
$host   = $_SERVER['HTTP_HOST'];
$scheme = (!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8">
<title>Live Chat</title>
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500&display=swap" rel="stylesheet">
<style>
/* === same CSS as before (unchanged) === */
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Roboto,sans-serif;background:#f4f6fa;color:#333;overflow:hidden}
.container{display:grid;grid-template-columns:18% 18% 64%;height:100vh}
.sidebar{background:#fff;border-right:1px solid #e5e9ef;overflow-y:auto}
.sidebar h2{padding:14px 16px;font-size:17px;color:#4a8ae4;border-bottom:1px solid #e5e9ef}
.sidebar ul{list-style:none}
.sidebar li{padding:10px 16px;cursor:pointer;transition:.15s}
.sidebar li:hover{background:#f6f8fb}
.sidebar li.active{background:#4a8ae4;color:#fff}
.chat-panel{display:flex;flex-direction:column;background:#fff;overflow:hidden}
.chat-header{position:sticky;top:0;z-index:2;background:#4a8ae4;color:#fff;
             display:flex;justify-content:space-between;align-items:center;padding:12px 16px}
.chat-header button{background:#fff;color:#4a8ae4;border:none;padding:6px 14px;border-radius:18px;font-size:14px;cursor:pointer}
.chat-header button:hover{background:#f0f0f0}
.chat-body{flex:1;overflow-y:auto;padding:20px;background:#edf1f7;scroll-behavior:smooth}
.chat-body::-webkit-scrollbar{width:6px}.chat-body::-webkit-scrollbar-thumb{background:#c7cdd8;border-radius:3px}
.bubble{max-width:75%;padding:12px 16px;margin-bottom:18px;border-radius:10px;font-size:15px;
        line-height:1.5;position:relative;animation:fade .25s}
@keyframes fade{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.bubble.user{background:#fff;margin-right:auto;border-bottom-left-radius:0}
.bubble.you {background:#d1f2c9;margin-left:auto;border-bottom-right-radius:0}
.bubble .ts{font-size:11px;color:#7d8597;position:absolute;right:10px;bottom:-15px}
.composer{position:sticky;bottom:0;z-index:2;background:#fff;padding:10px 12px;border-top:1px solid #e5e9ef;
          display:flex;gap:8px;align-items:center}
.composer input[type=text]{flex:1;padding:9px 14px;border:1px solid #ccd2de;border-radius:18px;font-size:14px}
.composer button,.composer select{background:#4a8ae4;color:#fff;border:none;padding:9px 16px;border-radius:18px;font-size:14px;cursor:pointer}
.composer button:hover,.composer select:hover{background:#3973c6}
.composer label{cursor:pointer;font-size:22px;color:#4a8ae4}
.composer input[type=file]{display:none}
@media(max-width:768px){
 .container{grid-template-columns:100%}
 .sidebar{position:fixed;width:70%;left:-70%;transition:.3s;top:0;height:100vh;z-index:3}
 .sidebar.show{left:0;box-shadow:2px 0 8px rgba(0,0,0,.15)}
 .chat-header .menu-btn{display:inline-block}
}
</style>
</head>
<body>
<div class="container">
  <!-- Accounts -->
  <div id="accBar" class="sidebar">
    <h2>Accounts</h2><ul>
      <?php foreach($accounts as $acc): ?>
        <li class="<?= $acc['phone_number_id']==$account_id?'active':'' ?>"
            onclick="location.href='?account_id=<?=urlencode($acc['phone_number_id'])?>'">
          <?=htmlspecialchars($acc['phone_number_id'])?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <!-- Clients -->
  <div id="cliBar" class="sidebar">
    <h2>Clients</h2><ul>
      <?php foreach($clients as $c): ?>
        <li class="<?= $c['wa_id']==$user_id?'active':'' ?>"
            onclick="location.href='?account_id=<?=urlencode($account_id)?>&user_id=<?=urlencode($c['wa_id'])?>'">
          <?=htmlspecialchars($c['wa_id'])?>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <!-- Chat -->
  <div class="chat-panel">
    <div class="chat-header">
      <span>
        <button class="menu-btn" style="display:none" onclick="toggleBars()">☰</button>
        <?= $user_id ? "Chat: ".htmlspecialchars($account_id)." → ".htmlspecialchars($user_id) : 'Select client' ?>
      </span>
      <button onclick="loadMsgs()">Refresh</button>
    </div>

    <div id="chatBody" class="chat-body"></div>

    <?php if($user_id): ?>
      <form class="composer" method="POST" enctype="multipart/form-data"
            action="?account_id=<?=urlencode($account_id)?>&user_id=<?=urlencode($user_id)?>">
        <label>📎<input type="file" name="media" onchange="onFile(this)"></label>
        <input type="hidden" name="send_type" id="sendType" value="text">
        <input id="bodyInput" type="text" name="body" placeholder="Type your message…" autocomplete="off" required>
        <select name="template">
          <option value="">Templates</option>
          <option>Hello! How can I help?</option>
          <option>Thank you for reaching out.</option>
        </select>
        <button type="submit">Send</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<script>
const userId   = <?= json_encode($user_id) ?>;
const accountId= <?= json_encode($account_id) ?>;
const HOST     = <?= json_encode($host) ?>;
const SELF     = <?= json_encode($self) ?>;
const SCHEME   = <?= json_encode($scheme) ?>;
let   autoScroll = true;

/* -------- render bubble -------- */
function render(m){
  const who = m.send_type==='sender'?'you':'user';
  let media='';
  if(m.message_type==='text'){
    media = `<div>${m.text_content}</div>`;
  }else if(m.message_type==='image'){
    media = `<img src="${SCHEME}://${HOST}/downloads/${m.media_file}" style="max-width:200px;border-radius:6px">`;
  }else{
    media = `<video controls style="max-width:200px;border-radius:6px">
               <source src="${SCHEME}://${HOST}/downloads/${m.media_file}">
             </video>`;
  }
  return `<div class="bubble ${who}">
            <strong>${who==='user'?'User':'You'}:</strong><br>${media}
            <div class="ts">${new Date(m.created_at).toLocaleString()}</div>
          </div>`;
}

/* -------- fill chat -------- */
function fill(arr){
  const box=document.getElementById('chatBody');
  const nearBottom=box.scrollHeight-box.scrollTop-box.clientHeight<120;
  box.innerHTML=arr.map(render).join('');
  if(nearBottom||autoScroll) box.scrollTop=box.scrollHeight;
}

/* -------- fetch messages -------- */
async function loadMsgs(){
  if(!userId) return;
  try{
    const url=`${SCHEME}://${HOST}/${SELF}?fetch=1&account_id=${encodeURIComponent(accountId)}&user_id=${encodeURIComponent(userId)}`;
    const res=await fetch(url,{cache:'no-store'});
    if(!res.ok) throw new Error('HTTP '+res.status);
    const data=await res.json();
    fill(Array.isArray(data)?data:[]);
  }catch(e){
    console.error('Fetch failed:',e);
    document.getElementById('chatBody').innerHTML='<p style="color:#c00">Cannot load conversation.</p>';
  }
}
loadMsgs(); setInterval(loadMsgs,3000);

/* -------- UX helpers -------- */
document.getElementById('chatBody').addEventListener('scroll',e=>{
  autoScroll=e.target.scrollHeight-e.target.scrollTop-e.target.clientHeight<120;
});
function onFile(inp){
  const sendType=document.getElementById('sendType');
  const body     =document.getElementById('bodyInput');
  sendType.value = inp.files.length ?
          (inp.files[0].type.startsWith('video') ? 'video':'image') : 'text';
  if(sendType.value==='text'){
      body.required=true;  body.placeholder='Type your message…';
  }else{
      body.required=false; body.placeholder='Optional caption';
  }
}
function toggleBars(){
  document.getElementById('accBar').classList.toggle('show');
  document.getElementById('cliBar').classList.toggle('show');
}
</script>
</body>
</html>
