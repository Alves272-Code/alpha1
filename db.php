<?php
$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'oo4eepvg_dev3';
$user = getenv('DB_USER') ?: 'oo4eepvg_dev3';
$pass = getenv('DB_PASS') ?: 'Teste123@';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    error_log("Erro na ligação à base de dados: " . $e->getMessage());
    die("Erro na ligação à base de dados.");
}

function inicializarBaseDados($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS utilizadores (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(255) NOT NULL,
        email VARCHAR(255) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        role ENUM('user','admin') DEFAULT 'user',
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_email (email),
        INDEX idx_role (role)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS contactos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT DEFAULT NULL,
        parent_id INT DEFAULT NULL,
        nome VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL,
        assunto VARCHAR(255) NOT NULL,
        mensagem TEXT NOT NULL,
        status ENUM('aberto','fechado') DEFAULT 'aberto',
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        fechado_em TIMESTAMP NULL DEFAULT NULL,
        FOREIGN KEY (user_id) REFERENCES utilizadores(id) ON DELETE SET NULL,
        FOREIGN KEY (parent_id) REFERENCES contactos(id) ON DELETE SET NULL,
        INDEX idx_status (status),
        INDEX idx_user (user_id),
        INDEX idx_parent (parent_id),
        INDEX idx_criado (criado_em DESC)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS mensagens_contacto (
        id INT AUTO_INCREMENT PRIMARY KEY,
        contacto_id INT NOT NULL,
        user_id INT DEFAULT NULL,
        mensagem TEXT NOT NULL,
        anexo VARCHAR(500) DEFAULT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (contacto_id) REFERENCES contactos(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES utilizadores(id) ON DELETE SET NULL,
        INDEX idx_contacto (contacto_id, criado_em ASC)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS mensagens_lidas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        mensagem_id INT NOT NULL,
        user_id INT NOT NULL,
        lido_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_msg_user (mensagem_id, user_id),
        FOREIGN KEY (mensagem_id) REFERENCES mensagens_contacto(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES utilizadores(id) ON DELETE CASCADE,
        INDEX idx_user_lido (user_id, lido_em DESC)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS templates_resposta (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        titulo VARCHAR(120) NOT NULL,
        conteudo TEXT NOT NULL,
        ativo TINYINT(1) DEFAULT 1,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES utilizadores(id) ON DELETE CASCADE,
        INDEX idx_user_ativo (user_id, ativo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS artigos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        titulo VARCHAR(255) NOT NULL,
        conteudo TEXT NOT NULL,
        imagem VARCHAR(500) DEFAULT NULL,
        meta_title VARCHAR(70) DEFAULT NULL,
        meta_description VARCHAR(160) DEFAULT NULL,
        meta_keywords VARCHAR(255) DEFAULT NULL,
        publicado TINYINT(1) DEFAULT 0,
        visualizacoes INT DEFAULT 0,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES utilizadores(id) ON DELETE CASCADE,
        INDEX idx_publicado (publicado, criado_em DESC),
        INDEX idx_user_artigos (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS imagens_artigo (
        id INT AUTO_INCREMENT PRIMARY KEY,
        artigo_id INT NOT NULL,
        nome_original VARCHAR(255) NOT NULL,
        caminho VARCHAR(500) NOT NULL,
        alt_text VARCHAR(255) DEFAULT NULL,
        title VARCHAR(255) DEFAULT NULL,
        descricao TEXT DEFAULT NULL,
        publicado TINYINT(1) DEFAULT 1,
        ordem INT DEFAULT 0,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (artigo_id) REFERENCES artigos(id) ON DELETE CASCADE,
        INDEX idx_artigo_ordem (artigo_id, ordem ASC)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
