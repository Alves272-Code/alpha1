<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Europe/Lisbon');

function gerarTokenCSRF() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verificarTokenCSRF($token) {
    if (!isset($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function flash($chave, $mensagem = null) {
    if ($mensagem !== null) {
        $_SESSION['flash'][$chave] = $mensagem;
    } else {
        $msg = $_SESSION['flash'][$chave] ?? null;
        unset($_SESSION['flash'][$chave]);
        return $msg;
    }
}

function redirecionar($url) {
    if (headers_sent()) {
        echo '<meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url) . '">';
        echo '<script>window.location.href="' . htmlspecialchars($url) . '";</script>';
        exit;
    }
    header("Location: $url");
    exit;
}

function isAdmin($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT role FROM utilizadores WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    return $user && $user['role'] === 'admin';
}

function validarMagicBytes(string $filePath, array $magicsEsperados): bool {
    if (!file_exists($filePath)) return false;
    $handle = fopen($filePath, 'rb');
    $head = fread($handle, 8);
    fclose($handle);
    foreach ($magicsEsperados as $magic) {
        if (strpos($head, $magic) === 0) return true;
    }
    return false;
}

function fazerUploadAnexo($ficheiro, $contacto_id) {
    $dir = "uploads/contactos/";
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            error_log("Falha ao criar diretório: $dir");
            return false;
        }
    }
    $maximo = 5 * 1024 * 1024;
    if ($ficheiro['size'] > $maximo) {
        error_log("Anexo excede 5MB: " . $ficheiro['name']);
        return false;
    }
    $ext = strtolower(pathinfo($ficheiro['name'], PATHINFO_EXTENSION));
    $permitidas = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt'];
    if (!in_array($ext, $permitidas)) {
        error_log("Extensão não permitida: $ext");
        return false;
    }
       $mime = getFileMimeType($ficheiro['tmp_name']);
    $mimesPermitidos = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
        'application/pdf' => 'pdf', 'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'text/plain' => 'txt'
    ];
    if (!isset($mimesPermitidos[$mime])) {
        error_log("Tipo MIME não permitido: $mime");
        return false;
    }
        $magicMap = [
        'jpg' => "\xFF\xD8\xFF",
        'jpeg'=> "\xFF\xD8\xFF",
        'png' => "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A",   // <-- corrigido
        'gif' => "GIF8",
        'pdf' => "%PDF",
        'doc' => "\xD0\xCF\x11\xE0",
        'docx'=> "PK\x03\x04",
        'txt' => ''
    ];
    if (!empty($magicMap[$ext]) && !validarMagicBytes($ficheiro['tmp_name'], [$magicMap[$ext]])) {
        error_log("Magic bytes inválidos para $ext: " . $ficheiro['name']);
        return false;
    }
    $nome = "contacto_{$contacto_id}_" . time() . "_" . bin2hex(random_bytes(8)) . ".$ext";
    $caminho = $dir . $nome;
    if (move_uploaded_file($ficheiro['tmp_name'], $caminho)) {
        error_log("Anexo enviado com sucesso: $caminho");
        return $caminho;
    }
    error_log("Falha ao mover anexo para: $caminho");
    return false;
}

function sanitizarImagem(string $srcPath, string $destPath = null): bool {
    if ($destPath === null) $destPath = $srcPath;
    $info = @getimagesize($srcPath);
    if (!$info) return false;
    $width = $info[0];
    $height = $info[1];
    $mime = $info['mime'];
    $src = null;
    switch ($mime) {
        case 'image/jpeg': $src = @imagecreatefromjpeg($srcPath); break;
        case 'image/png':  $src = @imagecreatefrompng($srcPath); break;
        case 'image/gif':  $src = @imagecreatefromgif($srcPath); break;
        case 'image/webp':
            if (!function_exists('imagecreatefromwebp')) return false;
            $src = @imagecreatefromwebp($srcPath);
            break;
        default: return false;
    }
    if (!$src) return false;
    $maxWidth = 2000;
    if ($width > $maxWidth) {
        $newWidth = $maxWidth;
        $newHeight = (int)($height * ($maxWidth / $width));
        $dst = imagecreatetruecolor($newWidth, $newHeight);
        if ($mime == 'image/png' || $mime == 'image/webp') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($src);
        $src = $dst;
    }
    $result = false;
    switch ($mime) {
        case 'image/jpeg': $result = imagejpeg($src, $destPath, 85); break;
        case 'image/png':  $result = imagepng($src, $destPath, 8); break;
        case 'image/gif':  $result = imagegif($src, $destPath); break;
        case 'image/webp': $result = imagewebp($src, $destPath, 85); break;
    }
    imagedestroy($src);
    return $result;
}

function getFileMimeType(string $path): string {
    static $hasFinfo = null;
    if ($hasFinfo === null) {
        $hasFinfo = function_exists('finfo_open');
    }
    if ($hasFinfo) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);
        return $mime ?: 'application/octet-stream';
    }
    // fallback 1: mime_content_type (presente na maioria dos PHP ≥ 5.3)
    if (function_exists('mime_content_type')) {
        return mime_content_type($path) ?: 'application/octet-stream';
    }
    // fallback 2: adivinhar a partir da extensão
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $map = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt'  => 'text/plain',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

function fazerUploadImagensArtigo($ficheiro, $artigo_id) {
    $dir = "uploads/artigos/$artigo_id/";
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) return false;
    }
    $thumbDir = $dir . 'thumbs/';
    if (!is_dir($thumbDir) && !mkdir($thumbDir, 0755, true)) error_log("Falha ao criar thumbs: $thumbDir");
    $maximo = 5 * 1024 * 1024;
    if ($ficheiro['size'] > $maximo) { error_log("Imagem excede 5MB: " . $ficheiro['name']); return false; }
    if (!is_uploaded_file($ficheiro['tmp_name'])) { error_log("Não é upload: " . $ficheiro['name']); return false; }
    $ext = strtolower(pathinfo($ficheiro['name'], PATHINFO_EXTENSION));
    $permitidas = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $permitidas)) { error_log("Extensão não permitida: $ext"); return false; }
        $mime = getFileMimeType($ficheiro['tmp_name']);
    $mimesPermitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    if (!isset($mimesPermitidos[$mime])) { error_log("MIME não permitido: $mime"); return false; }
    if ($mimesPermitidos[$mime] !== $ext && !($ext === 'jpg' && $mime === 'image/jpeg')) {
        error_log("Divergência extensão/MIME: $ext vs $mime"); return false;
    }
    $magicMap = ['jpg' => "\xFF\xD8\xFF", 'jpeg' => "\xFF\xD8\xFF", 'png' => "\x89PNG\r\n\x1a\n", 'gif' => "GIF8", 'webp' => "RIFF"];
    if (!validarMagicBytes($ficheiro['tmp_name'], [$magicMap[$ext]])) {
        error_log("Magic bytes inválidos para $ext: " . $ficheiro['name']); return false;
    }
    $infoImagem = @getimagesize($ficheiro['tmp_name']);
    if (!$infoImagem || $infoImagem[0] > 5000 || $infoImagem[1] > 5000) {
        error_log("Dimensões inválidas: " . $ficheiro['name']); return false;
    }
    $nome = "img_" . time() . "_" . bin2hex(random_bytes(4)) . ".$ext";
    $caminho = $dir . $nome;
    if (move_uploaded_file($ficheiro['tmp_name'], $caminho)) {
        sanitizarImagem($caminho);
        criarThumbnail($caminho, $ext, $artigo_id);
        error_log("Imagem enviada: $caminho");
        return $caminho;
    }
    error_log("Falha ao mover imagem: $caminho");
    return false;
}

function criarThumbnail($caminho, $ext, $artigo_id) {
    $thumbDir = dirname($caminho) . '/thumbs/';
    if (!is_dir($thumbDir)) {
        if (!mkdir($thumbDir, 0755, true)) { error_log("Falha ao criar thumbs: $thumbDir"); return false; }
    }
    $thumbPath = $thumbDir . 'thumb_' . basename($caminho);
    if (!extension_loaded('gd')) { error_log("GD não disponível"); return false; }
    list($width, $height) = getimagesize($caminho);
    $newWidth = 300;
    $newHeight = round($newWidth / ($width / $height));
    $src = null;
    switch($ext) {
        case 'jpg': case 'jpeg': $src = @imagecreatefromjpeg($caminho); break;
        case 'png': $src = @imagecreatefrompng($caminho); break;
        case 'gif': $src = @imagecreatefromgif($caminho); break;
        case 'webp': if (function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($caminho); break;
    }
    if (!$src) return false;
    $dst = imagecreatetruecolor($newWidth, $newHeight);
    if (in_array($ext, ['png', 'webp'])) { imagealphablending($dst, false); imagesavealpha($dst, true); }
    if ($ext === 'gif') {
        $transparent = imagecolortransparent($src);
        if ($transparent >= 0) {
            $transparentColor = imagecolorsforindex($src, $transparent);
            $transparentColor = imagecolorallocate($dst, $transparentColor['red'], $transparentColor['green'], $transparentColor['blue']);
            imagefill($dst, 0, 0, $transparentColor);
            imagecolortransparent($dst, $transparentColor);
        }
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    $success = false;
    switch($ext) {
        case 'jpg': case 'jpeg': $success = imagejpeg($dst, $thumbPath, 80); break;
        case 'png': $success = imagepng($dst, $thumbPath, 8); break;
        case 'gif': $success = imagegif($dst, $thumbPath); break;
        case 'webp': $success = imagewebp($dst, $thumbPath, 80); break;
    }
    imagedestroy($src);
    imagedestroy($dst);
    return $success;
}

function getImagensArtigo($pdo, $artigo_id) {
    $stmt = $pdo->prepare("SELECT * FROM imagens_artigo WHERE artigo_id = ? ORDER BY ordem ASC, criado_em ASC");
    $stmt->execute([$artigo_id]);
    return $stmt->fetchAll();
}

function eliminarImagemArtigo($pdo, $imagem_id) {
    $stmt = $pdo->prepare("SELECT caminho FROM imagens_artigo WHERE id = ?");
    $stmt->execute([$imagem_id]);
    $img = $stmt->fetch();
    if ($img) {
        if (file_exists($img['caminho'])) unlink($img['caminho']);
        $thumbPath = dirname($img['caminho']) . '/thumbs/thumb_' . basename($img['caminho']);
        if (file_exists($thumbPath)) unlink($thumbPath);
        $stmt = $pdo->prepare("DELETE FROM imagens_artigo WHERE id = ?");
        return $stmt->execute([$imagem_id]);
    }
    return false;
}

function sanitizar($string) {
    return htmlspecialchars(trim($string), ENT_QUOTES, 'UTF-8');
}

function validarExtensao($filename, $permitidas) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, $permitidas);
}

function gerarSlug($string) {
    $string = iconv('UTF-8', 'ASCII//TRANSLIT', $string);
    $string = preg_replace('/[^a-zA-Z0-9\s]/', '', $string);
    $string = strtolower(trim($string));
    $string = preg_replace('/\s+/', '-', $string);
    return $string;
}
