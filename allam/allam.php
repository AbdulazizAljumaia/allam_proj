<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'vendor/autoload.php';

// بدء الجلسة إذا لم تكن قد بدأت بالفعل
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// تضمين الملفات الضرورية بناءً على نظام التشغيل
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    include $_SERVER['DOCUMENT_ROOT'] . '/usr/Sher/shera.php';
} else {
    include '/usr/Sher/shera.php';
}

// مفاتيح API وبيانات اتصال Pinecone
$pinecone_api_key = "xxxx";
$index_host = "xxx.pinecone.io";

// دالة لاسترجاع العناصر المشابهة من Pinecone
function search_similar_items($api_key, $index_host, $text) {
    // الخطوة 1: توليد embedding للنص
    $embed_url = "https://api.pinecone.io/embed";
    $headers = [
        "Api-Key: $api_key",
        "Content-Type: application/json",
        "X-Pinecone-API-Version: 2024-10"
    ];
    $embed_data = [
        "model" => "multilingual-e5-large",
        "parameters" => [
            "input_type" => "query",
            "truncate" => "END"
        ],
        "inputs" => [
            ["text" => $text]
        ]
    ];

    $ch = curl_init($embed_url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($embed_data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $embed_response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('خطأ في توليد embedding: ' . curl_error($ch));
        curl_close($ch);
        return null;
    }
    curl_close($ch);

    $embed_result = json_decode($embed_response, true);
    $embedding = $embed_result["data"][0]["values"] ?? null;

    if ($embedding === null) {
        error_log("فشل في استرجاع قيم embedding.");
        return null;
    }

    // الخطوة 2: استعلام Pinecone باستخدام embedding المولدة
    $query_url = "https://$index_host/query";
    $query_data = [
        "namespace" => "ns1",
        "vector" => $embedding,
        "topK" => 3,
        "includeValues" => true,
        "includeMetadata" => true
    ];

    $ch = curl_init($query_url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($query_data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $query_response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('خطأ في استعلام Pinecone: ' . curl_error($ch));
        curl_close($ch);
        return null;
    }
    curl_close($ch);

    $query_result = json_decode($query_response, true);

    // استرجاع النصوص السياقية
    if (isset($query_result['matches'])) {
        $context_texts = implode("\n", array_map(function($match) {
            return $match['metadata']['text'] ?? "";
        }, $query_result['matches']));
        return $context_texts;
    } else {
        error_log("لم يتم العثور على مطابقات في Pinecone.");
        return null;
    }
}

// دالة للتحقق من التعارض بين النص والسياق باستخدام OpenAI
function check_conflict_with_rag($text, $rag_context) {
    $api_key = "xxxxx"; // مفتاح OpenAI API
    $api_url = "https://api.openai.com/v1/chat/completions";

    $headers = array(
        "Content-Type: application/json",
        "Authorization: Bearer " . $api_key
    );

    // نص الاستعلام للتحقق من التعارض
    $prompt = "السياق من RAG: \"$rag_context\"\n\nالنص: \"$text\"\n\nهل النص أعلاه يتعارض مع هذه اللوائخ أجب بـ 'نعم' أو 'لا'.";

    $data = array(
        "messages" => array(
            array("role" => "system", "content" => "أنت مساعد يتحقق من التعارض بين النصوص والسياقات المقدمة."),
            array("role" => "user", "content" => $prompt)
        ),
        "model" => "gpt-3.5-turbo",
        "max_tokens" => 10,
        "temperature" => 0
    );

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        error_log('خطأ CURL أثناء التحقق من التعارض: ' . $error_msg);
        curl_close($ch);
        echo "خطأ CURL أثناء التحقق من التعارض: " . $error_msg;
        return false;
    }

    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // إخراج تصحيح الأخطاء
    error_log("استجابة API OpenAI للتحقق من التعارض: $response");

    $response_data = json_decode($response, true);

    if ($http_status != 200) {
        if (isset($response_data['error'])) {
            $error_message = $response_data['error']['message'];
            error_log("خطأ من API OpenAI للتحقق من التعارض: $error_message");
            echo "خطأ من API OpenAI للتحقق من التعارض: $error_message";
        } else {
            error_log("خطأ غير معروف من API OpenAI للتحقق من التعارض. الحالة HTTP: $http_status");
            echo "خطأ غير معروف من API OpenAI للتحقق من التعارض.";
        }
        return false;
    }

    if (isset($response_data['choices'][0]['message']['content'])) {
        $answer = strtolower(trim($response_data['choices'][0]['message']['content']));
        return $answer === 'نعم';
    } else {
        error_log('استجابة غير متوقعة من API OpenAI للتحقق من التعارض: ' . $response);
        echo "استجابة غير متوقعة من API OpenAI للتحقق من التعارض.";
        return false;
    }
}

// دالة لتوليد الاستجابة باستخدام نموذج IBM Watsonx Allam
function generateAllamResponse($prompt) {
    $api_key = "xxxxxxxx"; // مفتاح IBM Watsonx API
    $project_id = "xxxxxxxx"; // استبدل بمعرف المشروع الفعلي
    $model_id = "sdaia/allam-1-13b-instruct";

    // نقطة النهاية API لنموذج Allam
    $api_url = "https://eu-de.ml.cloud.ibm.com/v1/projects/$project_id/models/$model_id/generate";

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
    ];

    $data = [
        'prompt' => $prompt,
        'decoding_method' => 'greedy',
        'max_new_tokens' => 200,
        'repetition_penalty' => 2
    ];

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);

    if(curl_errno($ch)){
        $error_msg = curl_error($ch);
        error_log('خطأ CURL في استجابة Allam: ' . $error_msg);
        echo "خطأ CURL في استجابة Allam: " . $error_msg;
        curl_close($ch);
        return '';
    }

    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // إخراج تصحيح الأخطاء
    error_log("استجابة API IBM Watsonx Allam: $response");

    $response_data = json_decode($response, true);

    if ($http_status != 200) {
        if (isset($response_data['error'])) {
            $error_message = $response_data['error']['message'];
            error_log("خطأ من API IBM Watsonx Allam: $error_message");
            echo "خطأ من API IBM Watsonx Allam: $error_message";
        } else {
            error_log("خطأ غير معروف من API IBM Watsonx Allam. الحالة HTTP: $http_status");
            echo "خطأ غير معروف من API IBM Watsonx Allam.";
        }
        return '';
    }

    if (isset($response_data['generated_text'])) {
        return $response_data['generated_text'];
    } else {
        error_log('استجابة غير متوقعة من API IBM Watsonx Allam: ' . $response);
        echo "استجابة غير متوقعة من API IBM Watsonx Allam.";
        return '';
    }
}

// دالة لتوليد استجابة OpenAI (لا تزال مستخدمة في بعض الأجزاء)
function generateOpenAiResponse($text, $character) {
    $api_key = "xxxx"; // مفتاح OpenAI API
    $api_url = "https://api.openai.com/v1/chat/completions";

    $headers = array(
        "Content-Type: application/json",
        "Authorization: Bearer " . $api_key
    );

    $data = array(
        "messages" => array(
            array("role" => "system", "content" => $character . $text),
        ),
        "model" => "gpt-3.5-turbo"
    );

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        error_log('خطأ CURL في استجابة OpenAI: ' . $error_msg);
        curl_close($ch);
        echo "خطأ CURL في استجابة OpenAI: " . $error_msg;
        return '';
    }

    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // إخراج تصحيح الأخطاء
    error_log("استجابة API OpenAI: $response");

    $response_data = json_decode($response, true);

    if ($http_status != 200) {
        if (isset($response_data['error'])) {
            $error_message = $response_data['error']['message'];
            error_log("خطأ من API OpenAI: $error_message");
            echo "خطأ من API OpenAI: $error_message";
        } else {
            error_log("خطأ غير معروف من API OpenAI. الحالة HTTP: $http_status");
            echo "خطأ غير معروف من API OpenAI.";
        }
        return '';
    }

    if (isset($response_data['choices'][0]['message']['content'])) {
        return $response_data['choices'][0]['message']['content'];
    } else {
        error_log('استجابة غير متوقعة من API OpenAI: ' . $response);
        echo "استجابة غير متوقعة من API OpenAI.";
        return '';
    }
}

// دالة لترجمة استجابة OpenAI (إذا لزم الأمر)
function TranslateOpenAiResponse($text) {
    $api_key = "xxxxxx-"; // مفتاح OpenAI API
    $api_url = "https://api.openai.com/v1/chat/completions";

    $headers = array(
        "Content-Type: application/json",
        "Authorization: Bearer " . $api_key
    );

    $data = array(
        "messages" => array(
            array("role" => "system", "content" =>  'انظر إلى هذا النص -> ' . $text . '. الآن، رتب الكلمات الصعبة واحدة تلو الأخرى مع ترجمتها الصحيحة إلى العربية هكذا: الكلمة الإنجليزية = الكلمة العربية. حدها بـ 5 كلمات مترجمة كحد أقصى. '),
        ),
        "model" => "gpt-3.5-turbo"
    );

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        error_log('خطأ CURL في استجابة الترجمة: ' . $error_msg);
        curl_close($ch);
        echo "خطأ CURL في استجابة الترجمة: " . $error_msg;
        return '';
    }

    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // إخراج تصحيح الأخطاء
    error_log("استجابة API OpenAI للترجمة: $response");

    $response_data = json_decode($response, true);

    if ($http_status != 200) {
        if (isset($response_data['error'])) {
            $error_message = $response_data['error']['message'];
            error_log("خطأ من API OpenAI للترجمة: $error_message");
            echo "خطأ من API OpenAI للترجمة: $error_message";
        } else {
            error_log("خطأ غير معروف من API OpenAI للترجمة. الحالة HTTP: $http_status");
            echo "خطأ غير معروف من API OpenAI للترجمة.";
        }
        return '';
    }

    if (isset($response_data['choices'][0]['message']['content'])) {
        return $response_data['choices'][0]['message']['content'];
    } else {
        error_log('استجابة غير متوقعة من API OpenAI للترجمة: ' . $response);
        echo "استجابة غير متوقعة من API OpenAI للترجمة.";
        return '';
    }
}

// دالة لتحويل النص إلى صوت باستخدام ElevenLabs API
function play_text_as_sound($text, $voice_id) {
    $api_key = 'xxxxxx'; // مفتاح ElevenLabs API
    $api_url = 'https://api.elevenlabs.io/v1/text-to-speech/' . $voice_id . '/stream';

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'xi-api-key: ' . $api_key,
    ];

    $data = [
        'text' => $text,
        'model_id' => 'eleven_multilingual_v2',
        'voice_settings' => [
            'stability' => 0.5,
            'similarity_boost' => 0.8,
            'style' => 0.0,
            'use_speaker_boost' => true,
        ],
    ];

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));

    // فتح مقبض ملف لكتابة الاستجابة
    $recordingsFolder = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . 'home' . DIRECTORY_SEPARATOR . 'recordings';
    $highestFolder = '';
    $highestNumber = -1;

    // العثور على المجلد بأعلى رقم
    $folders = glob($recordingsFolder . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
    foreach ($folders as $folder) {
        $folderName = basename($folder);
        if (is_numeric($folderName) && $folderName > $highestNumber) {
            $highestNumber = $folderName;
            $highestFolder = $folder;
        }
    }

    // التحقق من عدد الملفات داخل المجلد الأعلى
    if ($highestFolder && is_dir($highestFolder)) {
        $folderContents = glob($highestFolder . DIRECTORY_SEPARATOR . '*');
        if (count($folderContents) < 10) {
            $outputFolder = $highestFolder;
        } else {
            $newFolderNumber = $highestNumber + 1;
            $outputFolder = $recordingsFolder . DIRECTORY_SEPARATOR . $newFolderNumber;
            if (!is_dir($outputFolder)) {
                mkdir($outputFolder, 0777, true);
            }
        }
    } else {
        $outputFolder = $recordingsFolder . DIRECTORY_SEPARATOR . '1';
        if (!is_dir($outputFolder)) {
            mkdir($outputFolder, 0777, true);
        }
    }

    // إنشاء اسم ملف فريد
    $fileName = uniqid('audio_teacher', true) . '.mp3';
    $convertedAudioFilePath = $outputFolder . DIRECTORY_SEPARATOR . $fileName;

    $fp = fopen($convertedAudioFilePath, 'w');
    if (!$fp) {
        error_log('فشل في فتح الملف للكتابة: ' . $convertedAudioFilePath);
        echo "فشل في فتح الملف للكتابة.";
        return '';
    }

    // تعيين خيار CURLOPT_FILE لكتابة الاستجابة إلى الملف
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $response = curl_exec($ch);

    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        error_log('خطأ CURL في استجابة ElevenLabs: ' . $error_msg);
        fclose($fp);
        echo "خطأ CURL في استجابة ElevenLabs: " . $error_msg;
        return '';
    }

    curl_close($ch);
    fclose($fp);

    // إخراج تصحيح الأخطاء
    error_log("حالة استجابة API ElevenLabs: $http_status");

    if ($http_status != 200) {
        // قراءة رسالة الخطأ من الملف
        $error_response = file_get_contents($convertedAudioFilePath);
        error_log("API ElevenLabs أعادت حالة HTTP $http_status");
        error_log("رسالة الخطأ: $error_response");
        echo "خطأ من API ElevenLabs: $error_response";
        // حذف الملف لأنه ليس صوتًا صالحًا
        unlink($convertedAudioFilePath);
        return '';
    }

    return $convertedAudioFilePath;
}

// دالة لتفريغ الصوت باستخدام OpenAI Whisper API
function transcribeAudio($audioFilePath, $language = 'ar') { // تغيير اللغة إلى العربية
    $api_key = "xxxxxxx"; // مفتاح OpenAI API
    $api_url = "https://api.openai.com/v1/audio/transcriptions";

    $post_fields = [
        'file' => new CURLFile($audioFilePath),
        'model' => 'whisper-1',
        'language' => $language
    ];

    $headers = [
        'Authorization: Bearer ' . $api_key
    ];

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);

    if(curl_errno($ch)){
        $error_msg = curl_error($ch);
        error_log('خطأ CURL في تفريغ الصوت: ' . $error_msg);
        echo "خطأ CURL في تفريغ الصوت: " . $error_msg;
        curl_close($ch);
        return '';
    }

    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // إخراج تصحيح الأخطاء
    error_log("استجابة API OpenAI Whisper: $response");

    $response_data = json_decode($response, true);

    if ($http_status != 200) {
        if (isset($response_data['error'])) {
            $error_message = $response_data['error']['message'];
            error_log("خطأ من API OpenAI Whisper: $error_message");
            echo "خطأ من API OpenAI Whisper: $error_message";
        } else {
            error_log("خطأ غير معروف من API OpenAI Whisper. الحالة HTTP: $http_status");
            echo "خطأ غير معروف من API OpenAI Whisper.";
        }
        return '';
    }

    if (isset($response_data['text'])) {
        return $response_data['text'];
    } else {
        // تسجيل الاستجابة غير المتوقعة
        error_log('استجابة غير متوقعة من API OpenAI Whisper: ' . $response);
        echo "استجابة غير متوقعة من API OpenAI Whisper.";
        return '';
    }
}

// دالة لتوليد معرف عشوائي للردود
function randimreplyid($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
        
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
        
    return $randomString;
}

// دالة للحصول على الوقت الحالي في السعودية
function getCurrentSaudiTime()
{
    date_default_timezone_set('Asia/Riyadh');
    return date('Y-m-d H:i:s');
}

// بدء الكود الرئيسي
$expired_outstanding = getCurrentSaudiTime();

if (isset($_SESSION['phone']) && isset($_SESSION['password'])) {

    // استعلام SQL لاختيار السجلات من جدول 'details' مع شرط إضافي لـ ID
    $sql = "SELECT * FROM details WHERE (until_active < :currentDate OR usages <= 0) AND ID = :userID";

    // تحضير وتنفيذ استعلام SELECT
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':currentDate', $expired_outstanding);
    $stmt->bindParam(':userID', $_SESSION['ID']);
    $stmt->execute();

    // جلب الصفوف التي تطابق الشروط
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($rows) {
        echo '<!DOCTYPE html>
        <!-- كود HTML لرسالة التنبيه -->
        <html lang="ar">
        <head>
          <meta charset="UTF-8">
          <title>رسالة تنبيه</title>
          <!-- أنماط CSS الخاصة بك -->
        </head>
        <body>
          <!-- محتوى رسالة التنبيه الخاصة بك -->
        </body>
        </html>
        ';
    } else {
        // المستخدم لديه جلسة صالحة، استرجاع الصورة الرمزية من قاعدة البيانات

        // تحضير وتنفيذ الاستعلام لاسترجاع صورة المستخدم
        $phone = $_SESSION['phone'];
        $password = $_SESSION['password'];

        try {

            $stmt = $pdo->prepare("SELECT avatar, username, until_active, usages, ID FROM details WHERE phone = :phone AND password = :password");
            $stmt->execute(array(':phone' => $phone, ':password' => $password));

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                // الصورة الرمزية موجودة في قاعدة البيانات ومسار الملف صالح، عرضها
                $avatar_path = $row['avatar'];
                $name = $row['username'];
                $uses = $row['usages'];
                $renew_date = $row['until_active'];
                $avatar = $row['avatar'];
                $_SESSION['ID'] = $row['ID']; // التأكد من وجود معرف المستخدم في الجلسة

                // إخراج تصحيح الأخطاء
                error_log("تم استرجاع معلومات المستخدم: " . print_r($row, true));
            } else {
                // المستخدم غير موجود
                echo "المستخدم غير موجود.";
                exit;
            }

            // المتابعة في المعالجة
            $accent_gender = isset($_POST['accent_gender']) ? $_POST['accent_gender'] : '';
            $translationStatus = isset($_POST['translationStatus']) ? $_POST['translationStatus'] : '';

            // إخراج تصحيح الأخطاء
            error_log("نوع الصوت: $accent_gender");
            error_log("حالة الترجمة: $translationStatus");

            if ($accent_gender == 'type1') {
                $character_name = 'آرثر - المملكة المتحدة'; 
                $this_is_instruction = "أنت إدوارد آلان بو، معلم إنجليزي محترم قديم من لندن، جاهز لتوفير محادثة ودية مناسبة للمرحلة الابتدائية، واستجابات قصيرة وموجزة. أنت في خضم محادثة، لذا أجب كما لو كنت منخرطًا بعمق في هذه المحادثة. حدد ردك في 200 حرف بالضبط. سؤالي هو:  ";
                $voice_id = 'x0u3EW21dbrORJzOq1m9'; // استبدل بمعرف الصوت الفعلي الخاص بك
                $character_avatar = "characters/edward.jpeg";
            } elseif ($accent_gender == 'type2') {
                $character_name = 'هاربر - المملكة المتحدة'; 
                $this_is_instruction = "أنت هاربر واتسون، معلمة إنجليزية محترمة وودودة من مانشستر، جاهزة لتوفير محادثة ودية مناسبة للمرحلة الابتدائية، واستجابات قصيرة لا تتجاوز أربع جمل. حدد ردك في 200 حرف بالضبط. سؤالي هو:  ";
                $voice_id = 'YOUR_VOICE_ID_FOR_HARPER'; // استبدل بمعرف الصوت الفعلي الخاص بك
                $character_avatar = "characters/harper.png";
            } elseif ($accent_gender == 'type3') {
                $character_name = 'ليون - الولايات المتحدة'; 
                $this_is_instruction = "أنت ليون س. كينيدي، معلم إنجليزي أمريكي مرح ومحترم، جاهز لتوفير محادثة ودية مناسبة للمرحلة الابتدائية، واستجابات قصيرة لا تتجاوز أربع جمل. حدد ردك في 200 حرف بالضبط. سؤالي هو:  ";
                $voice_id = 'YOUR_VOICE_ID_FOR_LEON'; // استبدل بمعرف الصوت الفعلي الخاص بك
                $character_avatar = "characters/leon.png";
            } elseif ($accent_gender == 'type4') {
                $character_name = 'كلير - الولايات المتحدة'; 
                $this_is_instruction = "أنت كلير ريدفيلد، معلمة إنجليزية أمريكية لطيفة، جاهزة لتوفير محادثة ودية مناسبة للمرحلة الابتدائية، واستجابات قصيرة لا تتجاوز أربع جمل. حدد ردك في 200 حرف بالضبط. سؤالي هو:  ";
                $voice_id = 'YOUR_VOICE_ID_FOR_CLAIRE'; // استبدل بمعرف الصوت الفعلي الخاص بك
                $character_avatar = "characters/clair.png";
            } else {
                // الحالة الافتراضية إذا لم يتم تحديد نوع الصوت
                error_log("نوع الصوت غير محدد أو غير صالح.");
                echo "نوع الصوت غير محدد.";
                exit;
            }

            $recordingsDir = $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR . 'home' . DIRECTORY_SEPARATOR . 'recordings';

            try {
                if (!file_exists($recordingsDir)) {
                    if (!mkdir($recordingsDir, 0777, true)) {
                        throw new Exception('فشل في إنشاء مجلد التسجيلات.');
                    }
                }

                $folders = array_diff(scandir($recordingsDir, SCANDIR_SORT_NONE), ['.', '..', 'desktop.ini']);
                $lastFolder = empty($folders) ? 0 : max($folders);

                if (!empty($folders)) {
                    $folderPath = $recordingsDir . DIRECTORY_SEPARATOR . $lastFolder;

                    $filesCount = count(array_diff(scandir($folderPath, SCANDIR_SORT_NONE), ['.', '..', 'desktop.ini']));
                    if ($filesCount >= 10) {
                        $lastFolder++;
                        $folderPath = $recordingsDir . DIRECTORY_SEPARATOR . $lastFolder;
                        if (!mkdir($folderPath, 0777, true)) {
                            throw new Exception('فشل في إنشاء مجلد جديد.');
                        }
                    }
                } else {
                    $folderPath = $recordingsDir . DIRECTORY_SEPARATOR . '1';
                    if (!mkdir($folderPath, 0777, true)) {
                        throw new Exception('فشل في إنشاء المجلد الأول.');
                    }
                }

                // التحقق مما إذا تم تحميل ملف الصوت
                if (!isset($_FILES['audio']) || $_FILES['audio']['error'] != UPLOAD_ERR_OK) {
                    error_log("لم يتم تحميل ملف الصوت بشكل صحيح.");
                    echo "لم يتم تحميل ملف الصوت بشكل صحيح.";
                    exit;
                }

                $originalFileName = $_FILES['audio']['name'];
                $originalExtension = strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION));
                $audioFileName = uniqid() . '.' . $originalExtension;
                $audioFilePath = $folderPath . DIRECTORY_SEPARATOR . $audioFileName;

                // إخراج تصحيح الأخطاء
                error_log("تم تحميل ملف الصوت: " . print_r($_FILES['audio'], true));

                if (move_uploaded_file($_FILES['audio']['tmp_name'], $audioFilePath)) {
                    // التحقق مما إذا كانت الامتداد مقبولًا
                    $acceptedExtensions = ['mp3', 'mp4', 'mpeg', 'mpga', 'm4a', 'wav', 'webm'];

                    if (!in_array($originalExtension, $acceptedExtensions)) {
                        // تحويل ملف الصوت إلى mp3
                        $convertedAudioFilePath = $folderPath . DIRECTORY_SEPARATOR . uniqid() . '.mp3';
                        $ffmpegCommand = 'ffmpeg -i ' . escapeshellarg($audioFilePath) . ' -f mp3 ' . escapeshellarg($convertedAudioFilePath) . ' 2>&1';
                        exec($ffmpegCommand, $ffmpegOutput, $ffmpegReturnVar);

                        // إخراج تصحيح الأخطاء
                        error_log("أمر FFmpeg: $ffmpegCommand");
                        error_log("مخرجات FFmpeg: " . implode("\n", $ffmpegOutput));

                        if ($ffmpegReturnVar !== 0) {
                            error_log("فشل FFmpeg في تحويل ملف الصوت.");
                            echo "فشل في تحويل ملف الصوت.";
                            exit;
                        }

                        // استخدام الملف المحول للتفريغ
                        $audioFilePath = $convertedAudioFilePath;
                    }
                } else {
                    throw new Exception('فشل في نقل الملف المحمل.');
                }

                http_response_code(200);
                echo "<p>تم الاستلام <span style='display: inline-block;color: #075e54;font-weight: bold;' class=\"blue-tick\">✓✓</span>.</p>";
            } catch (Exception $e) {
                http_response_code(500);
                echo $e->getMessage();
                error_log('خطأ في التسجيل: ' . $e->getMessage());
                exit;
            }

            // تفريغ الصوت
            $transcript = transcribeAudio($audioFilePath);
            // إخراج تصحيح الأخطاء
            error_log("نتيجة التفريغ: $transcript");

            $my_sound_path = substr($audioFilePath, strlen($_SERVER['DOCUMENT_ROOT']));
            $my_transcript = $transcript;
            if ($transcript) {

                $date = getCurrentSaudiTime();
                // إخراج رسالة المستخدم
                echo '<div class="chatbox_ai">
                <div class="message">
                  <div class="avatar_ai">
                    <img src="' . htmlspecialchars($avatar, ENT_QUOTES, 'UTF-8') . '" >
                  </div>
                  <div class="message-content">
                    <div class="header">
                      <span class="name">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</span>
                      <span class="date">' . $date . '</span>
                    </div>
                    <div class="sound-bar">
                      <audio class="audio-player_ai" controls src="' . htmlspecialchars($my_sound_path, ENT_QUOTES, 'UTF-8') . '"></audio>
                    </div>
                  </div>
                </div>
                <hr>
                <div class="text"><p>' . nl2br(htmlspecialchars($my_transcript, ENT_QUOTES, 'UTF-8')) . '</p></div>
              </div>';

                // الخطوة 1: استرجاع السياق من RAG (Pinecone) للإدخال
                $rag_context_input = search_similar_items($pinecone_api_key, $index_host, $transcript);
                if ($rag_context_input === null) {
                    echo "فشل في استرجاع السياق من RAG للإدخال.";
                    error_log("فشل في استرجاع السياق من RAG للإدخال.");
                    exit;
                }

                // إخراج السياق
                echo '<div class="rag-context">
                        <h3>السياق من RAG للإدخال:</h3>
                        <p>' . nl2br(htmlspecialchars($rag_context_input, ENT_QUOTES, 'UTF-8')) . '</p>
                      </div>';

                // الخطوة 2: التحقق من التعارض بين سؤال المستخدم والسياق
                $is_conflicted_input = check_conflict_with_rag($transcript, $rag_context_input);

                // إخراج نتيجة التحقق من التعارض للإدخال
                echo '<div class="conflict-check">
                        <h3>نتيجة التحقق من التعارض للإدخال:</h3>
                        <p>' . ($is_conflicted_input ? 'متعارض: سؤالك يتنافى مع اللوائح لدينا.' : 'لا يوجد تعارض.') . '</p>
                      </div>';

                if ($is_conflicted_input) {
                    // إذا كان متعارضًا، الرد برسالة محددة
                    $conflict_message = "سؤالك يتنافى مع اللوائح لدينا.";

                    // توليد صوت للرسالة المتعارضة
                    $audioResponsePath = play_text_as_sound($conflict_message, $voice_id);
                    error_log("مسار استجابة الصوت المتعارض: $audioResponsePath");

                    $open_ai_path = substr($audioResponsePath, strlen($_SERVER['DOCUMENT_ROOT']));

                    // إخراج رسالة التعارض
                    echo '<div class="chatbox">
                    <div class="message">
                      <div class="avatar">
                        <img src="' . htmlspecialchars($character_avatar, ENT_QUOTES, 'UTF-8') . '" >
                      </div>
                      <div class="message-content">
                        <div class="header">
                          <span class="name">' . htmlspecialchars($character_name, ENT_QUOTES, 'UTF-8') . '</span>
                          <span class="date">' . $date . '</span>
                        </div>
                        <div class="sound-bar">
                          <audio class="audio-player" controls autoplay src="' . htmlspecialchars($open_ai_path, ENT_QUOTES, 'UTF-8') . '"></audio>
                        </div>
                      </div>
                    </div>
                    <hr>
                    <div class="text"><p>' . htmlspecialchars($conflict_message, ENT_QUOTES, 'UTF-8') . '</p></div>
                  </div>';
                } else {
                    // إذا لم يكن هناك تعارض، توليد استجابة باستخدام نموذج Allam

                    // إعداد النص الموجه لنموذج Allam
                    $allam_prompt = "<s> [INST] $transcript [/INST]";

                    // توليد استجابة باستخدام نموذج Allam
                    $allamText = generateAllamResponse($allam_prompt);
                    error_log("النص المولد بواسطة Allam: $allamText");

                    if ($allamText) {
                        // الخطوة 3: استرجاع السياق من RAG (Pinecone) لاستجابة Allam
                        $rag_context_output = search_similar_items($pinecone_api_key, $index_host, $allamText);
                        if ($rag_context_output === null) {
                            echo "فشل في استرجاع السياق من RAG لاستجابة Allam.";
                            error_log("فشل في استرجاع السياق من RAG لاستجابة Allam.");
                            exit;
                        }

                        // إخراج السياق لاستجابة Allam
                        echo '<div class="rag-context">
                                <h3>السياق من RAG لاستجابة AI:</h3>
                                <p>' . nl2br(htmlspecialchars($rag_context_output, ENT_QUOTES, 'UTF-8')) . '</p>
                              </div>';

                        // الخطوة 4: التحقق من التعارض بين استجابة Allam والسياق
                        $is_conflicted_output = check_conflict_with_rag($allamText, $rag_context_output);

                        // إخراج نتيجة التحقق من التعارض لاستجابة Allam
                        echo '<div class="conflict-check">
                                <h3>نتيجة التحقق من التعارض لاستجابة AI:</h3>
                                <p>' . ($is_conflicted_output ? 'متعارض: سؤالك يتنافى مع اللوائح لدينا.' : 'لا يوجد تعارض.') . '</p>
                              </div>';

                        if ($is_conflicted_output) {
                            // إذا كان متعارضًا، الرد برسالة محددة
                            $conflict_message_output = "سؤالك يتنافى مع اللوائح لدينا.";

                            // توليد صوت للرسالة المتعارضة
                            $audioResponsePath_output = play_text_as_sound($conflict_message_output, $voice_id);
                            error_log("مسار استجابة الصوت المتعارض: $audioResponsePath_output");

                            $open_ai_path_output = substr($audioResponsePath_output, strlen($_SERVER['DOCUMENT_ROOT']));

                            // إخراج رسالة التعارض
                            echo '<div class="chatbox">
                            <div class="message">
                              <div class="avatar">
                                <img src="' . htmlspecialchars($character_avatar, ENT_QUOTES, 'UTF-8') . '" >
                              </div>
                              <div class="message-content">
                                <div class="header">
                                  <span class="name">' . htmlspecialchars($character_name, ENT_QUOTES, 'UTF-8') . '</span>
                                  <span class="date">' . $date . '</span>
                                </div>
                                <div class="sound-bar">
                                  <audio class="audio-player" controls autoplay src="' . htmlspecialchars($open_ai_path_output, ENT_QUOTES, 'UTF-8') . '"></audio>
                                </div>
                              </div>
                            </div>
                            <hr>
                            <div class="text"><p>' . htmlspecialchars($conflict_message_output, ENT_QUOTES, 'UTF-8') . '</p></div>
                          </div>';
                        } else {
                            // إذا لم يكن هناك تعارض، توليد صوت وعرض رسالة Allam

                            // توليد صوت للاستجابة باستخدام ElevenLabs
                            $audioResponsePath = play_text_as_sound($allamText, $voice_id);
                            error_log("مسار استجابة الصوت: $audioResponsePath");

                            $allam_audio_path = substr($audioResponsePath, strlen($_SERVER['DOCUMENT_ROOT']));
                            $allam_transcript = $allamText;
                            if ($translationStatus == 'on') {
                                $stacked_vocab = TranslateOpenAiResponse($allam_transcript);
                                error_log("قائمة المفردات المترجمة: $stacked_vocab");
                            }

                            // إخراج رسالة Allam
                            echo '<div class="chatbox">
                            <div class="message">
                              <div class="avatar">
                                <img src="' . htmlspecialchars($character_avatar, ENT_QUOTES, 'UTF-8') . '" >
                              </div>
                              <div class="message-content">
                                <div class="header">
                                  <span class="name">' . htmlspecialchars($character_name, ENT_QUOTES, 'UTF-8') . '</span>
                                  <span class="date">' . $date . '</span>
                                </div>
                                <div class="sound-bar">
                                  <audio class="audio-player" controls autoplay src="' . htmlspecialchars($allam_audio_path, ENT_QUOTES, 'UTF-8') . '"></audio>
                                </div>
                              </div>
                            </div>
                            <hr>
                            <div class="text"><p>' . nl2br(htmlspecialchars($allam_transcript, ENT_QUOTES, 'UTF-8')) . '</p></div>';

                            if ($translationStatus == 'on') {
                                echo '
                                <hr>
                                <div class="text"><p>قائمة المفردات المهمة:<br>' . nl2br(htmlspecialchars($stacked_vocab, ENT_QUOTES, 'UTF-8')) . '</p></div>
                                ';
                            }
                            echo '</div>';

                            // إدراج المحادثة في قاعدة البيانات
                            try {
                                // استعلام SQL لإدراج البيانات في الجدول
                                if ($translationStatus == 'on') {
                                    $sql = "INSERT INTO historical_chat (date, userID, user_sound, user_transcription, AI_sound, ai_generative, translations)
                                            VALUES (:date, :userID, :user_sound, :user_transcription, :AI_sound, :ai_generative, :translations)";
                                } else {
                                    $sql = "INSERT INTO historical_chat (date, userID, user_sound, user_transcription, AI_sound, ai_generative)
                                            VALUES (:date, :userID, :user_sound, :user_transcription, :AI_sound, :ai_generative)";
                                }

                                // تحضير وتنفيذ الاستعلام
                                $stmt = $pdo->prepare($sql);
                                $stmt->bindParam(':date', $date);
                                $stmt->bindParam(':userID', $_SESSION['ID']);
                                $stmt->bindParam(':user_sound', $my_sound_path);
                                $stmt->bindParam(':user_transcription', $my_transcript);
                                $stmt->bindParam(':AI_sound', $allam_audio_path);
                                $stmt->bindParam(':ai_generative', $allam_transcript);
                                if ($translationStatus == 'on') {
                                    $stmt->bindParam(':translations', $stacked_vocab);
                                }

                                if ($stmt->execute()) {
                                    // استعلام SQL للحصول على قيمة الاستخدامات الحالية من جدول 'details'
                                    $userIDToUpdate = $_SESSION['ID'];
                                    $selectSql = "SELECT usages FROM details WHERE ID = :userIDToUpdate";

                                    // تحضير وتنفيذ استعلام SELECT
                                    $selectStmt = $pdo->prepare($selectSql);
                                    $selectStmt->bindParam(':userIDToUpdate', $userIDToUpdate);
                                    $selectStmt->execute();

                                    // جلب قيمة الاستخدامات الحالية
                                    $currentUsages = $selectStmt->fetchColumn();

                                    if ($currentUsages !== false) {
                                        // خصم الاستخدامات بناءً على حالة الترجمة
                                        if ($translationStatus == 'on') {
                                            $newUsages = $currentUsages - 2;
                                        } else {
                                            $newUsages = $currentUsages - 1;
                                        }
                                        // استعلام SQL لتحديث قيمة الاستخدامات الجديدة في جدول 'details'
                                        $updateSql = "UPDATE details SET usages = :newUsages WHERE ID = :userIDToUpdate";

                                        // تحضير وتنفيذ استعلام UPDATE
                                        $updateStmt = $pdo->prepare($updateSql);
                                        $updateStmt->bindParam(':newUsages', $newUsages);
                                        $updateStmt->bindParam(':userIDToUpdate', $userIDToUpdate);
                                        $updateStmt->execute();

                                        // إخراج قيمة الاستخدامات الجديدة
                                        echo "عدد المحادثات المتبقية: $newUsages";
                                    } else {
                                        echo "المستخدم غير موجود أو قيمة الاستخدامات غير محددة.";
                                    }
                                }

                            } catch (PDOException $e) {
                                error_log("خطأ في قاعدة البيانات: " . $e->getMessage());
                                die("فشل الاتصال بقاعدة البيانات: " . $e->getMessage());
                            }
                        }
                    } else {
                        echo "لا توجد استجابة.. حاول مرة أخرى!\n";
                        error_log("لم يولد Allam استجابة.");
                    }
                }}
            
            } catch (PDOException $e) {
                error_log("خطأ في قاعدة البيانات: " . $e->getMessage());
                echo "خطأ: " . $e->getMessage();
            }
        }
    }

    $pdo = null;
?>
