<?php
/**
 * Скрипт для загрузки файлов на сервер через веб
 * Разместите этот файл в корневой директории сайта на сервере
 * Например: /var/www/products-backend/public/upload-via-web.php
 * 
 * Использование:
 * 1. Загрузите этот файл через FTP/SFTP в public_html или public директории
 * 2. Откройте в браузере: http://194.87.104.42/upload-via-web.php
 * 3. Введите токен и загрузите файлы
 */

// Установите свой секретный токен для безопасности
$SECRET_TOKEN = 'upload_token_2026_secure';
$UPLOAD_DIR = __DIR__ . '/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    
    if ($token !== $SECRET_TOKEN) {
        http_response_code(403);
        echo json_encode(['error' => 'Неверный токен']);
        exit;
    }
    
    if (!isset($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Файл не загружен']);
        exit;
    }
    
    $file = $_FILES['file'];
    $targetPath = $UPLOAD_DIR . basename($file['name']);
    
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        echo json_encode([
            'success' => true,
            'message' => 'Файл загружен успешно',
            'filename' => $file['name'],
            'path' => $targetPath
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Ошибка при загрузке файла']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Загрузка файлов на сервер</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 { color: #333; }
        .form-group { margin-bottom: 20px; }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input[type="password"],
        input[type="file"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button {
            background: #f53003;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover { background: #d42a02; }
        #result {
            margin-top: 20px;
            padding: 15px;
            border-radius: 4px;
            display: none;
        }
        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
        .instructions {
            background: #e7f3ff;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .instructions ol { margin: 10px 0; padding-left: 20px; }
        .instructions li { margin: 5px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📤 Загрузка файлов на сервер</h1>
        
        <div class="instructions">
            <h3>Инструкция:</h3>
            <ol>
                <li>Введите секретный токен</li>
                <li>Выберите файл для загрузки</li>
                <li>Нажмите "Загрузить"</li>
                <li>После загрузки всех файлов, удалите этот скрипт!</li>
            </ol>
            <p><strong>Файлы для загрузки:</strong></p>
            <ul>
                <li>app/Http/Middleware/CheckSellicoPermission.php</li>
            </ul>
        </div>
        
        <form id="uploadForm" enctype="multipart/form-data">
            <div class="form-group">
                <label for="token">Секретный токен:</label>
                <input type="password" id="token" name="token" required>
            </div>
            
            <div class="form-group">
                <label for="file">Выберите файл:</label>
                <input type="file" id="file" name="file" required>
            </div>
            
            <button type="submit">Загрузить</button>
        </form>
        
        <div id="result"></div>
    </div>
    
    <script>
        document.getElementById('uploadForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const resultDiv = document.getElementById('result');
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                resultDiv.style.display = 'block';
                resultDiv.className = data.success ? 'success' : 'error';
                resultDiv.textContent = data.success 
                    ? `✅ ${data.message}: ${data.filename}` 
                    : `❌ ${data.error}`;
                
                if (data.success) {
                    document.getElementById('file').value = '';
                }
            } catch (error) {
                resultDiv.style.display = 'block';
                resultDiv.className = 'error';
                resultDiv.textContent = '❌ Ошибка сети: ' + error.message;
            }
        });
    </script>
</body>
</html>
