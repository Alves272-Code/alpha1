<?php
session_start();
require 'db.php';
require 'functions.php';

if (!isset($_SESSION['user_id'])) {
    redirecionar('index.php');
}

$user_id    = $_SESSION['user_id'];
$is_admin   = isAdmin($pdo, $user_id);
$csrf_token = gerarTokenCSRF();

$artigo_edit = null;
if ($is_admin && isset($_GET['editar'])) {
    $stmt = $pdo->prepare("SELECT * FROM artigos WHERE id = ? AND user_id = ?");
    $stmt->execute([$_GET['editar'], $user_id]);
    $artigo_edit = $stmt->fetch();
}

$aba_inicial = $_GET['aba'] ?? 'pedidos';

// ==================== AJAX CHAT ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] === '1') {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    
    // Debug: reportar todos os erros para dentro do buffer
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
    try {
        if (!verificarTokenCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Token inválido');
        }
        
        $contacto_id = $_POST['contacto_id'] ?? 0;
        $mensagem    = trim($_POST['mensagem'] ?? '');
        
        if (empty($mensagem)) {
            throw new RuntimeException('Mensagem vazia');
        }
        
        $stmt = $pdo->prepare("SELECT user_id, email FROM contactos WHERE id = ?");
        $stmt->execute([$contacto_id]);
        $c = $stmt->fetch();
        
        if (!$c || (!$is_admin && $c['user_id'] != $user_id && $c['email'] != $_SESSION['user_email'])) {
            throw new RuntimeException('Acesso negado');
        }
        
        $anexo = null;
        if (!empty($_FILES['anexo']['tmp_name'])) {
            $anexo = fazerUploadAnexo($_FILES['anexo'], $contacto_id);
            if (!$anexo) {
                throw new RuntimeException('Anexo inválido (máx 5MB, jpg/png/pdf/doc/txt)');
            }
        }
        
        $stmt = $pdo->prepare("INSERT INTO mensagens_contacto (contacto_id, user_id, mensagem, anexo) VALUES (?, ?, ?, ?)");
        $stmt->execute([$contacto_id, $user_id, $mensagem, $anexo]);
        $mensagem_id = $pdo->lastInsertId();
        
        if ($is_admin) {
            $pdo->prepare("UPDATE contactos SET status = 'aberto' WHERE id = ? AND status = 'fechado'")->execute([$contacto_id]);
        }
        
        $stmt = $pdo->prepare("SELECT nome, role FROM utilizadores WHERE id = ?");
        $stmt->execute([$user_id]);
        $autor = $stmt->fetch() ?: ['nome' => 'Sistema', 'role' => 'user'];

        $stmt = $pdo->prepare("SELECT c.email, u.email AS owner_email FROM contactos c LEFT JOIN utilizadores u ON c.user_id = u.id WHERE c.id = ?");
        $stmt->execute([$contacto_id]);
        $dest = $stmt->fetch();
        $destinoEmail = $is_admin ? ($dest['owner_email'] ?: $dest['email']) : 'suporte@meusite.local';
        if (!empty($destinoEmail) && function_exists('mail')) {
            @mail($destinoEmail, "Nova resposta no pedido #$contacto_id", "Tem uma nova mensagem no seu pedido.");
        }
        
        $msg_html = '<div class="flex justify-end fade-in"><div class="chat-msg bg-indigo-500 text-white rounded-t-2xl rounded-bl-2xl p-3">';
        $msg_html .= '<div class="text-xs text-indigo-200 mb-1">' . htmlspecialchars($autor['nome']) . ' • ' . date('d/m H:i') . '</div>';
        $msg_html .= '<div>' . nl2br(htmlspecialchars($mensagem)) . '</div>';
        
        if ($anexo) {
            $ext = strtolower(pathinfo($anexo, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif'])) {
                $msg_html .= '<div class="mt-2"><a href="' . htmlspecialchars($anexo) . '" target="_blank"><img src="' . htmlspecialchars($anexo) . '" class="rounded max-h-32 object-cover"></a></div>';
            } else {
                $msg_html .= '<div class="mt-2"><a href="' . htmlspecialchars($anexo) . '" target="_blank" class="text-sm underline text-indigo-200"><i class="fas fa-paperclip"></i> ' . basename($anexo) . '</a></div>';
            }
        }
        $msg_html .= '</div></div>';
        
        echo json_encode(['html' => $msg_html, 'mensagem_id' => $mensagem_id]);
        
    } catch (Exception $e) {
        echo json_encode([
            'erro' => 'Erro interno: ' . $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine()
        ]);
    }
    exit;
}

// ==================== PROCESSAMENTO POST ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificarTokenCSRF($_POST['csrf_token'] ?? '')) {
        flash('erro', 'Token de segurança inválido.');
        redirecionar('dashboard.php');
    }

    if (isset($_POST['atualizar_perfil'])) {
        $novo_nome = trim($_POST['nome'] ?? '');
        $novo_email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);

        if ($novo_nome === '' || !$novo_email || !filter_var($novo_email, FILTER_VALIDATE_EMAIL)) {
            flash('erro', 'Nome e email válidos são obrigatórios.');
            redirecionar('dashboard.php?aba=perfil');
        }

        $stmt = $pdo->prepare("SELECT id FROM utilizadores WHERE email = ? AND id <> ?");
        $stmt->execute([$novo_email, $user_id]);
        if ($stmt->fetch()) {
            flash('erro', 'Este email já está associado a outra conta.');
            redirecionar('dashboard.php?aba=perfil');
        }

        $stmt = $pdo->prepare("UPDATE utilizadores SET nome = ?, email = ? WHERE id = ?");
        $stmt->execute([$novo_nome, $novo_email, $user_id]);
        $_SESSION['user_nome'] = $novo_nome;
        $_SESSION['user_email'] = $novo_email;
        flash('sucesso', 'Perfil atualizado com sucesso.');
        redirecionar('dashboard.php?aba=perfil');
    }

    if (isset($_POST['alterar_password'])) {
        $atual = $_POST['password_atual'] ?? '';
        $nova = $_POST['password_nova'] ?? '';
        $confirmar = $_POST['password_confirmar'] ?? '';

        if ($atual === '' || $nova === '' || $confirmar === '') {
            flash('erro', 'Preencha os três campos de password.');
            redirecionar('dashboard.php?aba=perfil');
        }
        if (strlen($nova) < 8) {
            flash('erro', 'A nova password deve ter pelo menos 8 caracteres.');
            redirecionar('dashboard.php?aba=perfil');
        }
        if ($nova !== $confirmar) {
            flash('erro', 'A confirmação da nova password não coincide.');
            redirecionar('dashboard.php?aba=perfil');
        }

        $stmt = $pdo->prepare("SELECT password FROM utilizadores WHERE id = ?");
        $stmt->execute([$user_id]);
        $u = $stmt->fetch();
        if (!$u || !password_verify($atual, $u['password'])) {
            flash('erro', 'Password atual incorreta.');
            redirecionar('dashboard.php?aba=perfil');
        }

        $nova_hash = password_hash($nova, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE utilizadores SET password = ? WHERE id = ?")->execute([$nova_hash, $user_id]);
        flash('sucesso', 'Password alterada com sucesso.');
        redirecionar('dashboard.php?aba=perfil');
    }

    // --- Pedidos ---
    if (isset($_POST['novo_pedido'])) {
        $assunto  = trim($_POST['assunto'] ?? '');
        $mensagem = trim($_POST['mensagem'] ?? '');
        if ($assunto === '' || $mensagem === '') {
            flash('erro', 'Preencha assunto e mensagem.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO contactos (user_id, nome, email, assunto, mensagem) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $_SESSION['user_nome'], $_SESSION['user_email'], $assunto, $mensagem]);
            flash('sucesso', 'Pedido criado com sucesso!');
            redirecionar('dashboard.php?pedido=' . $pdo->lastInsertId());
        }
    }
    
    if ($is_admin && isset($_POST['toggle_status'])) {
        $pid  = $_POST['pedido_id'];
        $stmt = $pdo->prepare("SELECT status FROM contactos WHERE id = ?");
        $stmt->execute([$pid]);
        $p = $stmt->fetch();
        if ($p) {
            $novo = $p['status'] === 'aberto' ? 'fechado' : 'aberto';
            $pdo->prepare("UPDATE contactos SET status = ?, fechado_em = IF(? = 'fechado', NOW(), NULL) WHERE id = ?")->execute([$novo, $novo, $pid]);
            flash('sucesso', 'Estado do pedido atualizado.');
        }
        redirecionar('dashboard.php?pedido=' . $pid);
    }
    
    if (!$is_admin && isset($_POST['reabrir_pedido'])) {
        $antigo_id    = $_POST['pedido_id'];
        $assunto      = trim($_POST['assunto'] ?? '');
        $justificacao = trim($_POST['mensagem_reabertura'] ?? '');
        if ($assunto === '' || $justificacao === '') {
            flash('erro', 'Preencha assunto e justificação.');
            redirecionar('dashboard.php?pedido=' . $antigo_id);
        }
        $stmt = $pdo->prepare("SELECT * FROM contactos WHERE id = ? AND (user_id = ? OR email = ?)");
        $stmt->execute([$antigo_id, $user_id, $_SESSION['user_email']]);
        $antigo = $stmt->fetch();
        if ($antigo && $antigo['status'] === 'fechado') {
            $fechado = strtotime($antigo['fechado_em'] ?? $antigo['criado_em']);
            if ((time() - $fechado) / 86400 <= 30) {
                $stmt = $pdo->prepare("INSERT INTO contactos (user_id, nome, email, assunto, mensagem, parent_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $_SESSION['user_nome'], $_SESSION['user_email'], $assunto, $justificacao, $antigo_id]);
                $novo_id = $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO mensagens_contacto (contacto_id, user_id, mensagem) VALUES (?, ?, ?)")->execute([$novo_id, $user_id, $justificacao]);
                flash('sucesso', 'Pedido reaberto com sucesso!');
                redirecionar('dashboard.php?pedido=' . $novo_id);
            } else {
                flash('erro', 'Prazo de 30 dias para reabertura excedido.');
            }
        } else {
            flash('erro', 'Pedido não encontrado ou já se encontra aberto.');
        }
        redirecionar('dashboard.php');
    }

    // --- Gestão de Artigos (admin) ---
    if ($is_admin && isset($_POST['acao_artigo'])) {
        $acao = $_POST['acao_artigo'];
        $titulo = trim($_POST['titulo'] ?? '');
        $conteudo = trim($_POST['conteudo'] ?? '');
        $meta_title = trim($_POST['meta_title'] ?? '');
        $meta_desc = trim($_POST['meta_description'] ?? '');
        $meta_keys = trim($_POST['meta_keywords'] ?? '');
        $publicado = isset($_POST['publicado']) ? 1 : 0;
        $artigo_id = $_POST['artigo_id'] ?? 0;
        if (empty($titulo) || empty($conteudo)) {
            $_SESSION['flash']['dados_artigo'] = $_POST;
            flash('erro_artigo', 'Título e conteúdo são obrigatórios.');
            $redir = $acao === 'criar' ? 'novo_artigo' : 'editor_artigo&editar=' . $artigo_id;
            redirecionar("dashboard.php?aba=$redir");
        }
        if ($acao === 'criar') {
            $stmt = $pdo->prepare("INSERT INTO artigos (titulo, conteudo, imagem, meta_title, meta_description, meta_keywords, publicado, user_id) VALUES (?, ?, NULL, ?, ?, ?, ?, ?)");
            $stmt->execute([$titulo, $conteudo, $meta_title, $meta_desc, $meta_keys, $publicado, $user_id]);
            $artigo_id = $pdo->lastInsertId();
            flash('sucesso_artigo', 'Artigo criado com sucesso!');
        } else {
            $stmt = $pdo->prepare("UPDATE artigos SET titulo=?, conteudo=?, meta_title=?, meta_description=?, meta_keywords=?, publicado=?, atualizado_em=NOW() WHERE id=? AND user_id=?");
            $stmt->execute([$titulo, $conteudo, $meta_title, $meta_desc, $meta_keys, $publicado, $artigo_id, $user_id]);
            flash('sucesso_artigo', 'Artigo atualizado com sucesso!');
        }
        if ($artigo_id && isset($_POST['capa_existente_id']) && $_POST['capa_existente_id'] !== '') {
            $capaId = (int)$_POST['capa_existente_id'];
            $stmt = $pdo->prepare("SELECT caminho FROM imagens_artigo WHERE id = ? AND artigo_id = ?");
            $stmt->execute([$capaId, $artigo_id]);
            $imgCapa = $stmt->fetch();
            if ($imgCapa) {
                $pdo->prepare("UPDATE artigos SET imagem = ? WHERE id = ?")->execute([$imgCapa['caminho'], $artigo_id]);
            }
        }
        if ($artigo_id && isset($_FILES['imagens_galeria']) && !empty($_FILES['imagens_galeria']['tmp_name'][0])) {
            $files = $_FILES['imagens_galeria'];
            $stmt = $pdo->prepare("SELECT MAX(ordem) as max_ordem FROM imagens_artigo WHERE artigo_id = ?");
            $stmt->execute([$artigo_id]);
            $maxOrdem = $stmt->fetch()['max_ordem'] ?? -1;
            $ordemAtual = $maxOrdem + 1;
            for ($i = 0; $i < count($files['tmp_name']); $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
                $caminho = fazerUploadImagensArtigo([
                    'name' => $files['name'][$i], 'tmp_name' => $files['tmp_name'][$i],
                    'size' => $files['size'][$i], 'error' => $files['error'][$i]
                ], $artigo_id);
                if ($caminho) {
                    $alt = $_POST['alt_text'][$i] ?? '';
                    $tit = $_POST['image_title'][$i] ?? '';
                    $desc = $_POST['descricao'][$i] ?? '';
                    $pub = isset($_POST['publicado_img'][$i]) ? 1 : 0;
                    $stmt = $pdo->prepare("INSERT INTO imagens_artigo (artigo_id, nome_original, caminho, alt_text, title, descricao, publicado, ordem) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$artigo_id, $files['name'][$i], $caminho, $alt, $tit, $desc, $pub, $ordemAtual]);
                    $capaNovaIndex = $_POST['capa_nova_index'] ?? null;
                    if ($capaNovaIndex !== null && $capaNovaIndex !== '' && (int)$capaNovaIndex === $i) {
                        $pdo->prepare("UPDATE artigos SET imagem = ? WHERE id = ?")->execute([$caminho, $artigo_id]);
                    }
                    $ordemAtual++;
                }
            }
        }
        if ($artigo_id && isset($_POST['imagens_ordem']) && !empty($_POST['imagens_ordem'])) {
            $idsOrdenados = array_filter(array_map('intval', explode(',', $_POST['imagens_ordem'])));
            $ordem = 0;
            foreach ($idsOrdenados as $imgId) {
                $stmt = $pdo->prepare("UPDATE imagens_artigo SET ordem = ? WHERE id = ? AND artigo_id = ?");
                $stmt->execute([$ordem++, $imgId, $artigo_id]);
            }
        }
        if ($artigo_id && isset($_POST['alt_text_existente']) && is_array($_POST['alt_text_existente'])) {
            foreach ($_POST['alt_text_existente'] as $imgId => $alt) {
                $imgId = (int)$imgId;
                $title = $_POST['image_title_existente'][$imgId] ?? '';
                $desc = $_POST['descricao_existente'][$imgId] ?? '';
                $pub = isset($_POST['publicado_img_existente'][$imgId]) ? 1 : 0;
                $stmt = $pdo->prepare("UPDATE imagens_artigo SET alt_text=?, title=?, descricao=?, publicado=? WHERE id=? AND artigo_id=?");
                $stmt->execute([$alt, $title, $desc, $pub, $imgId, $artigo_id]);
            }
        }
        redirecionar('dashboard.php?aba=artigos');
    }

    // Eliminar artigo
    if ($is_admin && isset($_GET['eliminar_artigo'])) {
        $artigo_id = $_GET['eliminar_artigo'];
        $stmt = $pdo->prepare("SELECT caminho FROM imagens_artigo WHERE artigo_id = ?");
        $stmt->execute([$artigo_id]);
        $imagens = $stmt->fetchAll();
        foreach ($imagens as $img) {
            if (file_exists($img['caminho'])) unlink($img['caminho']);
            $thumbPath = dirname($img['caminho']) . '/thumbs/thumb_' . basename($img['caminho']);
            if (file_exists($thumbPath)) unlink($thumbPath);
        }
        $pdo->prepare("DELETE FROM imagens_artigo WHERE artigo_id = ?")->execute([$artigo_id]);
        $pdo->prepare("DELETE FROM artigos WHERE id = ? AND user_id = ?")->execute([$artigo_id, $user_id]);
        $dir = "uploads/artigos/$artigo_id/";
        if (is_dir($dir)) {
            array_map('unlink', glob("$dir/thumbs/*.*"));
            rmdir("$dir/thumbs");
            array_map('unlink', glob("$dir/*.*"));
            rmdir($dir);
        }
        flash('sucesso_artigo', 'Artigo eliminado com sucesso!');
        redirecionar('dashboard.php?aba=artigos');
    }

    // Alternar estado publicado
    if ($is_admin && isset($_GET['toggle_publicado'])) {
        $stmt = $pdo->prepare("UPDATE artigos SET publicado = NOT publicado WHERE id = ? AND user_id = ?");
        $stmt->execute([$_GET['toggle_publicado'], $user_id]);
        redirecionar('dashboard.php?aba=artigos');
    }

    // Eliminar imagem específica
    if ($is_admin && isset($_GET['eliminar_imagem'])) {
        $img_id = $_GET['eliminar_imagem'];
        $artigo_id = $_GET['artigo_id'] ?? 0;
        $stmt = $pdo->prepare("SELECT caminho FROM imagens_artigo WHERE id = ?");
        $stmt->execute([$img_id]);
        $img = $stmt->fetch();
        if ($img) {
            $stmt = $pdo->prepare("SELECT imagem FROM artigos WHERE id = ?");
            $stmt->execute([$artigo_id]);
            $artigo = $stmt->fetch();
            if ($artigo && $artigo['imagem'] === $img['caminho']) {
                $pdo->prepare("UPDATE artigos SET imagem = NULL WHERE id = ?")->execute([$artigo_id]);
            }
            if (file_exists($img['caminho'])) unlink($img['caminho']);
            $thumbPath = dirname($img['caminho']) . '/thumbs/thumb_' . basename($img['caminho']);
            if (file_exists($thumbPath)) unlink($thumbPath);
            $pdo->prepare("DELETE FROM imagens_artigo WHERE id = ?")->execute([$img_id]);
        }
        redirecionar("dashboard.php?aba=editor_artigo&editar=$artigo_id");
    }

    redirecionar('dashboard.php');
}

// ==================== DADOS PARA VISTA ====================
if ($is_admin) {
    $pedidos = $pdo->query("SELECT c.*, u.nome AS user_nome,
        (SELECT COUNT(*) FROM mensagens_contacto WHERE contacto_id = c.id) AS msgs,
        (SELECT MAX(criado_em) FROM mensagens_contacto WHERE contacto_id = c.id) AS ultima_msg_em,
        (SELECT user_id FROM mensagens_contacto WHERE contacto_id = c.id ORDER BY criado_em DESC, id DESC LIMIT 1) AS ultima_msg_user_id
        FROM contactos c LEFT JOIN utilizadores u ON c.user_id = u.id
        ORDER BY c.status ASC, c.criado_em DESC")->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT c.*,
        (SELECT COUNT(*) FROM mensagens_contacto WHERE contacto_id = c.id) AS msgs,
        (SELECT MAX(criado_em) FROM mensagens_contacto WHERE contacto_id = c.id) AS ultima_msg_em,
        (SELECT user_id FROM mensagens_contacto WHERE contacto_id = c.id ORDER BY criado_em DESC, id DESC LIMIT 1) AS ultima_msg_user_id
        FROM contactos c WHERE c.user_id = ? OR c.email = ?
        ORDER BY c.criado_em DESC");
    $stmt->execute([$user_id, $_SESSION['user_email']]);
    $pedidos = $stmt->fetchAll();
}

$total_pedidos = count($pedidos);
$pedidos_abertos = count(array_filter($pedidos, function ($p) {
    return (isset($p['status']) ? $p['status'] : '') === 'aberto';
}));
$pedidos_fechados = max(0, $total_pedidos - $pedidos_abertos);

$pedido_atual = null;
$mensagens    = [];
$mensagens_lidas_por_outros = [];
if (isset($_GET['pedido'])) {
    $id = $_GET['pedido'];
    if (!isset($_SESSION['pedidos_lidos'])) $_SESSION['pedidos_lidos'] = [];
    $_SESSION['pedidos_lidos'][$id] = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("SELECT * FROM contactos WHERE id = ?");
    $stmt->execute([$id]);
    $pedido_atual = $stmt->fetch();
    if ($pedido_atual && ($is_admin || $pedido_atual['user_id'] == $user_id || $pedido_atual['email'] == $_SESSION['user_email'])) {
        $stmt = $pdo->prepare("SELECT m.*, u.nome AS autor_nome, u.role AS autor_role
                               FROM mensagens_contacto m LEFT JOIN utilizadores u ON m.user_id = u.id
                               WHERE m.contacto_id = ? ORDER BY m.criado_em ASC");
        $stmt->execute([$id]);
        $mensagens = $stmt->fetchAll();
        $pdo->prepare("INSERT IGNORE INTO mensagens_lidas (mensagem_id, user_id)
                       SELECT m.id, ? FROM mensagens_contacto m
                       WHERE m.contacto_id = ? AND m.user_id <> ?")->execute([$user_id, $id, $user_id]);
        if (!empty($mensagens)) {
            $ids = array_column($mensagens, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "SELECT mensagem_id, COUNT(*) AS c FROM mensagens_lidas WHERE mensagem_id IN ($placeholders) AND user_id <> ? GROUP BY mensagem_id";
            $stmt = $pdo->prepare($sql);
            $params = $ids;
            $params[] = $user_id;
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $r) $mensagens_lidas_por_outros[$r['mensagem_id']] = (int)$r['c'];
        }
    } else {
        $pedido_atual = null;
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="dashboard.css">
    <title>Dashboard | MeuSite</title>
</head>
<body class="bg-gradient-to-br from-gray-50 to-gray-100" x-data="dashboard()" @resize.window="largura = window.innerWidth">
<div x-show="sidebar && largura < 768" x-cloak class="fixed inset-0 bg-black/50 z-40 md:hidden" @click="sidebar = false"></div>

<div class="flex h-screen overflow-hidden">
    <!-- Sidebar -->
    <aside class="w-72 bg-gradient-to-b from-indigo-800 to-indigo-900 text-white shadow-xl flex flex-col fixed md:static z-50 h-full sidebar-mobile"
           :class="sidebar || largura >= 768 ? 'translate-x-0' : '-translate-x-full'">
        <div class="p-6 border-b border-indigo-700 flex justify-between items-center">
            <span class="text-2xl font-bold">MeuSite</span>
            <button @click="sidebar = false" class="md:hidden text-2xl">&times;</button>
        </div>
        <nav class="flex-1 p-4 space-y-2">
            <button @click="aba = 'pedidos'; sidebar = false" :class="aba === 'pedidos' ? 'bg-indigo-700' : ''" class="w-full text-left p-3 rounded-xl hover:bg-indigo-700 flex gap-2 transition">
                <i class="fas fa-ticket-alt w-5"></i> <?= $is_admin ? 'Pedidos' : 'Meus Pedidos' ?>
            </button>
            <button @click="aba = 'perfil'; sidebar = false" :class="aba === 'perfil' ? 'bg-indigo-700' : ''" class="w-full text-left p-3 rounded-xl hover:bg-indigo-700 flex gap-2 transition">
                <i class="fas fa-user-circle w-5"></i> Perfil
            </button>
            <?php if ($is_admin): ?>
                <button @click="aba = 'artigos'; sidebar = false" :class="aba === 'artigos' ? 'bg-indigo-700' : ''" class="w-full text-left p-3 rounded-xl hover:bg-indigo-700 flex gap-2 transition">
                    <i class="fas fa-newspaper w-5"></i> Artigos
                </button>
            <?php endif; ?>
        </nav>
        <div class="p-4 border-t border-indigo-700">
            <a href="logout.php" class="flex gap-2 text-indigo-200 hover:text-white transition">
                <i class="fas fa-sign-out-alt"></i> Sair
            </a>
        </div>
    </aside>

    <!-- Conteúdo principal -->
    <main class="flex-1 overflow-y-auto p-4 md:p-6 w-full">
        <button @click="sidebar = true" class="md:hidden mb-4 bg-white p-2 rounded-full shadow inline-block">
            <i class="fas fa-bars text-xl text-indigo-600"></i>
        </button>

        <!-- Mensagens flash -->
        <?php foreach (['sucesso', 'erro', 'sucesso_artigo', 'erro_artigo'] as $tipo): ?>
            <?php $msg = flash($tipo); if ($msg): ?>
                <div class="mb-4 p-3 rounded-lg <?= strpos($tipo, 'erro') !== false ? 'bg-red-100 text-red-700 border border-red-200' : 'bg-green-100 text-green-700 border border-green-200' ?> flex justify-between items-center">
                    <span><?= htmlspecialchars($msg) ?></span>
                    <button @click="$event.target.parentElement.remove()" class="text-lg">&times;</button>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>

        <!-- Aba Pedidos -->
        <div x-show="aba === 'pedidos'">
            <?php if (!$pedido_atual): ?>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <div class="bg-white rounded-xl shadow p-4">
                        <p class="text-sm text-gray-500">Total de pedidos</p>
                        <p class="text-2xl font-bold text-indigo-700"><?= $total_pedidos ?></p>
                    </div>
                    <div class="bg-white rounded-xl shadow p-4">
                        <p class="text-sm text-gray-500">Abertos</p>
                        <p class="text-2xl font-bold text-green-600"><?= $pedidos_abertos ?></p>
                    </div>
                    <div class="bg-white rounded-xl shadow p-4">
                        <p class="text-sm text-gray-500">Fechados</p>
                        <p class="text-2xl font-bold text-gray-600"><?= $pedidos_fechados ?></p>
                    </div>
                </div>
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
                    <h2 class="text-2xl font-bold"><?= $is_admin ? 'Centro de Pedidos' : 'Meus Pedidos' ?></h2>
                    <div class="flex gap-2 w-full sm:w-auto">
                        <input type="text" x-model="filtroPedidos" placeholder="Pesquisar por assunto..." class="border rounded-lg px-3 py-2 w-full sm:w-64">
                    </div>
                    <?php if (!$is_admin): ?>
                        <button @click="aba = 'novo'" class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 transition">
                            <i class="fas fa-plus mr-1"></i> Novo Pedido
                        </button>
                    <?php endif; ?>
                </div>
                <div class="grid gap-4">
                    <?php foreach($pedidos as $p): ?>
                    <?php
                        $ultimoAutor = (int)($p['ultima_msg_user_id'] ?? 0);
                        $ultimoMomento = $p['ultima_msg_em'] ?? null;
                        $lidoEm = $_SESSION['pedidos_lidos'][$p['id']] ?? null;
                        $naoLido = $ultimoMomento && $ultimoAutor !== (int)$user_id && (!$lidoEm || strtotime($ultimoMomento) > strtotime($lidoEm));
                    ?>
                    <div x-show="matchesPedido('<?= htmlspecialchars(strtolower($p['assunto']), ENT_QUOTES) ?>')" class="bg-white rounded-xl shadow p-5 border-l-4 <?= $p['status'] === 'aberto' ? 'border-green-500' : 'border-gray-300' ?> hover:shadow-md transition">
                        <div class="flex flex-col sm:flex-row justify-between">
                            <div>
                                <div class="flex gap-2 items-center mb-1">
                                    <span class="font-mono bg-gray-100 px-2 py-0.5 rounded text-sm">#<?=$p['id']?></span>
                                    <span class="text-xs <?=$p['status']==='aberto'?'bg-green-100 text-green-800':'bg-gray-100 text-gray-600'?> px-2 py-0.5 rounded-full"><?=$p['status']?></span>
                                    <?php if($naoLido): ?>
                                        <span class="text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded-full">Não lido</span>
                                    <?php endif; ?>
                                    <?php if($is_admin): ?><span class="text-sm text-gray-500"><?=htmlspecialchars($p['user_nome']??'Visitante')?></span><?php endif; ?>
                                </div>
                                <p class="font-semibold"><?=htmlspecialchars($p['assunto'])?></p>
                                <p class="text-sm text-gray-600 truncate max-w-xs"><?=htmlspecialchars(substr($p['mensagem'],0,80))?>...</p>
                                <p class="text-xs text-gray-400 mt-1"><?=$p['msgs']?> mensagens • <?=date('d/m/Y',strtotime($p['criado_em']))?></p>
                                <?php if($p['status']==='aberto' && !empty($p['ultima_msg_em']) && (time()-strtotime($p['ultima_msg_em'])) > 4*3600): ?>
                                    <p class="text-xs text-amber-600 mt-1"><i class="fas fa-clock"></i> SLA alerta: sem resposta há mais de 4h</p>
                                <?php endif; ?>
                            </div>
                            <div class="flex gap-2 mt-3 sm:mt-0 items-center">
                                <a href="?pedido=<?=$p['id']?>" class="bg-blue-500 text-white px-3 py-1 rounded text-sm hover:bg-blue-600 transition">Abrir</a>
                                <?php if(!$is_admin && $p['status']==='fechado'): ?>
                                    <button @click="reabrirId = <?=$p['id']?>; modalReabrir = true" class="bg-yellow-500 text-white px-3 py-1 rounded text-sm hover:bg-yellow-600 transition">Reabrir</button>
                                <?php endif; ?>
                                <?php if($is_admin): ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?=$csrf_token?>">
                                        <input type="hidden" name="pedido_id" value="<?=$p['id']?>">
                                        <button name="toggle_status" class="bg-<?=$p['status']==='aberto'?'gray':'green'?>-500 text-white px-3 py-1 rounded text-sm hover:bg-<?=$p['status']==='aberto'?'gray':'green'?>-600 transition">
                                            <?=$p['status']==='aberto'?'Fechar':'Reabrir'?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if(empty($pedidos)): ?>
                        <p class="text-gray-500 text-center py-8">Nenhum pedido encontrado.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Aba Novo Pedido -->
        <div x-show="aba === 'novo'" class="max-w-2xl mx-auto bg-white p-6 rounded-2xl shadow">
            <h2 class="text-2xl font-bold mb-4">Novo Pedido</h2>
            <form method="POST" action="dashboard.php">
                <input type="hidden" name="csrf_token" value="<?=$csrf_token?>">
                <div class="mb-4">
                    <label class="block font-medium mb-1">Assunto</label>
                    <input type="text" name="assunto" required class="w-full border p-3 rounded-xl focus:ring-2 focus:ring-indigo-500 outline-none">
                </div>
                <div class="mb-4">
                    <label class="block font-medium mb-1">Mensagem</label>
                    <textarea name="mensagem" rows="5" required class="w-full border p-3 rounded-xl focus:ring-2 focus:ring-indigo-500 outline-none"></textarea>
                </div>
                <div class="flex gap-2">
                    <button type="submit" name="novo_pedido" class="bg-indigo-600 text-white px-6 py-2 rounded-xl hover:bg-indigo-700 transition">Enviar</button>
                    <button type="button" @click="aba = 'pedidos'" class="bg-gray-300 px-6 py-2 rounded-xl hover:bg-gray-400 transition">Cancelar</button>
                </div>
            </form>
        </div>

        <!-- Aba Perfil -->
        <div x-show="aba === 'perfil'" class="max-w-3xl mx-auto space-y-6">
            <div class="bg-white p-6 rounded-2xl shadow">
                <h2 class="text-2xl font-bold mb-4">Perfil</h2>
                <form method="POST" action="dashboard.php" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?=$csrf_token?>">
                    <div>
                        <label class="block font-medium mb-1">Nome</label>
                        <input type="text" name="nome" required value="<?=htmlspecialchars($_SESSION['user_nome'])?>" class="w-full border p-3 rounded-xl">
                    </div>
                    <div>
                        <label class="block font-medium mb-1">Email</label>
                        <input type="email" name="email" required value="<?=htmlspecialchars($_SESSION['user_email'])?>" class="w-full border p-3 rounded-xl">
                    </div>
                    <button type="submit" name="atualizar_perfil" class="bg-indigo-600 text-white px-6 py-2 rounded-xl hover:bg-indigo-700 transition">
                        Guardar alterações
                    </button>
                </form>
            </div>
            <div class="bg-white p-6 rounded-2xl shadow">
                <h3 class="text-xl font-semibold mb-4">Segurança</h3>
                <form method="POST" action="dashboard.php" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?=$csrf_token?>">
                    <div>
                        <label class="block font-medium mb-1">Password atual</label>
                        <input type="password" name="password_atual" required class="w-full border p-3 rounded-xl">
                    </div>
                    <div>
                        <label class="block font-medium mb-1">Nova password</label>
                        <input type="password" name="password_nova" minlength="8" required class="w-full border p-3 rounded-xl">
                    </div>
                    <div>
                        <label class="block font-medium mb-1">Confirmar nova password</label>
                        <input type="password" name="password_confirmar" minlength="8" required class="w-full border p-3 rounded-xl">
                    </div>
                    <button type="submit" name="alterar_password" class="bg-gray-900 text-white px-6 py-2 rounded-xl hover:bg-black transition">
                        Alterar password
                    </button>
                </form>
            </div>
        </div>

        <!-- Aba Artigos (admin) -->
        <?php if ($is_admin): ?>
        <div x-show="aba === 'artigos'" class="max-w-6xl mx-auto">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold">Artigos</h2>
                <a href="dashboard.php?aba=novo_artigo" class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 transition">
                    <i class="fas fa-plus mr-1"></i> Novo Artigo
                </a>
            </div>
            <div class="grid gap-6">
                <?php
                $artigos = $pdo->query("SELECT * FROM artigos ORDER BY criado_em DESC")->fetchAll();
                if (empty($artigos)): ?>
                    <div class="text-center py-12 bg-white rounded-xl shadow">
                        <i class="fas fa-newspaper text-5xl text-gray-300 mb-4"></i>
                        <p class="text-gray-500">Nenhum artigo ainda.</p>
                    </div>
                <?php else: foreach ($artigos as $art): ?>
                <div class="bg-white rounded-xl shadow p-5 hover:shadow-md transition flex flex-col md:flex-row gap-4">
                    <div class="w-full md:w-32 flex-shrink-0">
                        <img src="<?= htmlspecialchars($art['imagem'] ?: 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22300%22 height=%22200%22%3E%3Crect fill=%22%23ddd%22 width=%22300%22 height=%22200%22/%3E%3Ctext fill=%22%23999%22 x=%2250%25%22 y=%2250%25%22 dominant-baseline=%22middle%22 text-anchor=%22middle%22 font-size=%2220%22%3ESem imagem%3C/text%3E%3C/svg%3E') ?>" 
                             class="w-full h-24 object-cover rounded-lg" alt="<?= htmlspecialchars($art['titulo']) ?>">
                    </div>
                    <div class="flex-1">
                        <div class="flex flex-wrap items-center gap-2 mb-2">
                            <span class="text-xs font-mono bg-gray-100 px-2 py-0.5 rounded">#<?=$art['id']?></span>
                            <span class="text-xs <?=$art['publicado']?'bg-green-100 text-green-800':'bg-yellow-100 text-yellow-800'?> px-2 py-0.5 rounded-full">
                                <?=$art['publicado']?'Publicado':'Rascunho'?>
                            </span>
                            <span class="text-xs text-gray-400"><i class="far fa-eye mr-1"></i> <?=$art['visualizacoes']?></span>
                            <span class="text-xs text-gray-400"><i class="far fa-calendar mr-1"></i> <?=date('d/m/Y',strtotime($art['criado_em']))?></span>
                        </div>
                        <h3 class="font-semibold text-lg mb-2"><?=htmlspecialchars($art['titulo'])?></h3>
                        <div class="flex flex-wrap gap-2">
                            <a href="../artigo.php?id=<?=$art['id']?>" target="_blank" class="bg-green-500 text-white px-3 py-1 rounded text-sm hover:bg-green-600 transition">
                                <i class="fas fa-eye mr-1"></i> Ver
                            </a>
                            <a href="?toggle_publicado=<?=$art['id']?>" class="bg-<?=$art['publicado']?'yellow':'green'?>-500 text-white px-3 py-1 rounded text-sm hover:bg-<?=$art['publicado']?'yellow':'green'?>-600 transition">
                                <?=$art['publicado']?'<i class="fas fa-lock mr-1"></i> Despublicar':'<i class="fas fa-check mr-1"></i> Publicar'?>
                            </a>
                            <a href="dashboard.php?aba=editor_artigo&editar=<?=$art['id']?>" class="bg-blue-500 text-white px-3 py-1 rounded text-sm hover:bg-blue-600 transition">
                                <i class="fas fa-edit mr-1"></i> Editar
                            </a>
                            <a href="?eliminar_artigo=<?=$art['id']?>" class="bg-red-500 text-white px-3 py-1 rounded text-sm hover:bg-red-600 transition" onclick="return confirm('Eliminar artigo?')">
                                <i class="fas fa-trash mr-1"></i> Eliminar
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- Editor de Artigo -->
        <div x-show="aba === 'novo_artigo' || aba === 'editor_artigo'" class="max-w-6xl mx-auto bg-white p-6 rounded-2xl shadow">
            <?php
            $modo_edicao = $artigo_edit ? true : false;
            $dados_flash = $_SESSION['flash']['dados_artigo'] ?? null;
            if ($dados_flash) {
                $dados = $dados_flash;
                unset($_SESSION['flash']['dados_artigo']);
            } else {
                $dados = $artigo_edit ?? [];
            }
            ?>
            <h2 class="text-2xl font-bold mb-6"><?= $modo_edicao ? 'Editar Artigo' : 'Novo Artigo' ?></h2>
            <form method="POST" action="dashboard.php" enctype="multipart/form-data" x-data="galeriaManager()" @submit="prepararEnvio()">
                <input type="hidden" name="csrf_token" value="<?=$csrf_token?>">
                <input type="hidden" name="acao_artigo" value="<?= $modo_edicao ? 'editar' : 'criar' ?>">
                <input type="hidden" name="artigo_id" value="<?= $dados['id'] ?? 0 ?>">

                <div class="grid md:grid-cols-3 gap-6">
                    <div class="md:col-span-2 space-y-6">
                        <div>
                            <label class="block font-semibold mb-1">Título *</label>
                            <input type="text" name="titulo" value="<?= htmlspecialchars($dados['titulo'] ?? '') ?>" required class="w-full border p-3 rounded-xl focus:ring-2 focus:ring-indigo-500 outline-none">
                        </div>
                        <div>
                            <label class="block font-semibold mb-1">Conteúdo *</label>
                            <textarea name="conteudo" rows="15" required class="w-full border p-3 rounded-xl focus:ring-2 focus:ring-indigo-500 outline-none"><?= htmlspecialchars($dados['conteudo'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <div class="space-y-6">
                        <div>
                            <label class="flex items-center gap-2 font-semibold">
                                <input type="checkbox" name="publicado" value="1" <?= ($dados['publicado'] ?? false) ? 'checked' : '' ?>>
                                Publicar artigo
                            </label>
                        </div>
                    </div>
                </div>

                <!-- SEO -->
                <div class="border-t pt-6 mt-6">
                    <h3 class="text-xl font-bold mb-4"><i class="fas fa-search text-indigo-600 mr-2"></i> SEO & Metadados</h3>
                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label>Meta Title</label>
                            <input type="text" name="meta_title" value="<?= htmlspecialchars($dados['meta_title'] ?? '') ?>" maxlength="70" class="w-full border p-2 rounded-lg" placeholder="Título SEO (max 70 caracteres)">
                        </div>
                        <div>
                            <label>Meta Keywords</label>
                            <input type="text" name="meta_keywords" value="<?= htmlspecialchars($dados['meta_keywords'] ?? '') ?>" class="w-full border p-2 rounded-lg" placeholder="Palavras-chave separadas por vírgula">
                        </div>
                        <div class="md:col-span-2">
                            <label>Meta Description</label>
                            <textarea name="meta_description" rows="2" maxlength="160" class="w-full border p-2 rounded-lg" placeholder="Descrição (max 160 caracteres)"><?= htmlspecialchars($dados['meta_description'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Galeria -->
                <div class="border-t pt-6 mt-6">
                    <h3 class="text-xl font-bold mb-4 flex items-center gap-2">
                        <i class="fas fa-images text-indigo-600"></i> Galeria / Capa do Artigo
                    </h3>
                    <p class="text-sm text-gray-600 mb-4">
                        Arraste as imagens para reordenar. Clique em <span class="font-bold">"⭐ Capa"</span> para definir a imagem de destaque.
                    </p>
                    <input type="hidden" name="capa_existente_id" x-ref="capaExistenteId" value="">
                    <input type="hidden" name="capa_nova_index" x-ref="capaNovaIndex" value="">

                    <div id="sortable-gallery" class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                        <?php if ($modo_edicao && $artigo_edit):
                            $imagens = $pdo->prepare("SELECT * FROM imagens_artigo WHERE artigo_id = ? ORDER BY ordem ASC, criado_em ASC");
                            $imagens->execute([$artigo_edit['id']]);
                            foreach ($imagens->fetchAll() as $img):
                        ?>
                        <div class="gallery-thumb bg-white rounded-xl shadow p-3 cursor-grab relative"
                             draggable="true"
                             data-id="<?= $img['id'] ?>"
                             data-caminho="<?= htmlspecialchars($img['caminho']) ?>"
                             @dragstart="dragStart(event)"
                             @dragover.prevent="dragOver(event)"
                             @drop="drop(event)"
                             @dragend="dragEnd(event)">
                            <?php if ($artigo_edit['imagem'] === $img['caminho']): ?>
                                <div class="capa-badge">⭐ Capa</div>
                            <?php endif; ?>
                            <img src="<?= htmlspecialchars($img['caminho']) ?>" class="w-full h-32 object-cover rounded-lg mb-2" alt="<?= htmlspecialchars($img['alt_text'] ?? '') ?>">
                            <div class="text-xs space-y-1">
                                <input type="text" name="alt_text_existente[<?=$img['id']?>]" value="<?= htmlspecialchars($img['alt_text'] ?? '') ?>" placeholder="Alt text" class="w-full border rounded p-1">
                                <input type="text" name="image_title_existente[<?=$img['id']?>]" value="<?= htmlspecialchars($img['title'] ?? '') ?>" placeholder="Title" class="w-full border rounded p-1">
                                <input type="text" name="descricao_existente[<?=$img['id']?>]" value="<?= htmlspecialchars($img['descricao'] ?? '') ?>" placeholder="Descrição" class="w-full border rounded p-1">
                                <div class="flex items-center justify-between mt-1">
                                    <label class="flex items-center gap-1 text-xs">
                                        <input type="checkbox" name="publicado_img_existente[<?=$img['id']?>]" value="1" <?= $img['publicado'] ? 'checked' : '' ?>> Publicar
                                    </label>
                                    <button type="button" @click="setCapaExistente('<?= $img['id'] ?>')" class="text-xs bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded hover:bg-yellow-200 transition">⭐ Capa</button>
                                </div>
                                <a href="?eliminar_imagem=<?=$img['id']?>&artigo_id=<?=$artigo_edit['id']?>" class="text-red-500 hover:underline text-xs block mt-1" onclick="return confirm('Eliminar esta imagem?')">🗑 Eliminar</a>
                            </div>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>
                    <input type="hidden" name="imagens_ordem" x-ref="imagensOrdemInput" value="">

                    <div class="drop-zone p-6 text-center cursor-pointer mb-6" 
                         @click="$refs.inputGaleria.click()"
                         @dragover.prevent="dragover = true" 
                         @dragleave.prevent="dragover = false" 
                         @drop.prevent="handleDrop($event)" 
                         :class="{ 'drag': dragover }">
                        <p class="text-gray-500 mb-2"><i class="fas fa-cloud-upload-alt text-3xl"></i></p>
                        <p class="font-semibold">Clique ou arraste imagens para aqui</p>
                        <p class="text-sm text-gray-400">JPG, PNG, GIF, WebP (máx 5MB cada)</p>
                        <input type="file" name="imagens_galeria[]" multiple class="hidden" x-ref="inputGaleria" @change="handleFiles($el)" accept="image/jpeg,image/png,image/gif,image/webp">
                    </div>

                    <template x-for="(img, index) in previews" :key="index">
                        <div class="flex items-start gap-4 mt-4 p-3 bg-gray-50 rounded-xl relative">
                            <div x-show="novaCapaIndex === index" class="capa-badge">⭐ Capa</div>
                            <img :src="img.src" class="preview-image">
                            <div class="flex-1 grid grid-cols-2 gap-2">
                                <div>
                                    <label class="text-xs font-medium">Alt Text</label>
                                    <input type="text" :name="'alt_text['+index+']'" class="w-full border rounded p-1 text-sm">
                                </div>
                                <div>
                                    <label class="text-xs font-medium">Title</label>
                                    <input type="text" :name="'image_title['+index+']'" class="w-full border rounded p-1 text-sm">
                                </div>
                                <div class="col-span-2">
                                    <label class="text-xs font-medium">Descrição</label>
                                    <input type="text" :name="'descricao['+index+']'" class="w-full border rounded p-1 text-sm">
                                </div>
                                <div class="col-span-2 flex items-center gap-4">
                                    <label class="flex items-center gap-1 text-sm">
                                        <input type="checkbox" :name="'publicado_img['+index+']'" value="1" checked> Publicar
                                    </label>
                                    <button type="button" @click="setCapaNova(index)" class="text-xs bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded hover:bg-yellow-200 transition">⭐ Capa</button>
                                    <button type="button" @click="removerPreview(index)" class="text-red-500 text-sm hover:underline">Remover</button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="flex gap-2 mt-6">
                    <button type="submit" class="bg-indigo-600 text-white px-6 py-2 rounded-xl hover:bg-indigo-700 transition">
                        <i class="fas fa-save mr-1"></i> Salvar Artigo
                    </button>
                    <a href="dashboard.php?aba=artigos" class="bg-gray-300 px-6 py-2 rounded-xl hover:bg-gray-400 transition inline-block">Cancelar</a>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </main>
</div>

<!-- Chat (modal) -->
<?php if ($pedido_atual): ?>
<div x-data="chat(<?= $pedido_atual['id'] ?>)" x-init="init()">
    <div x-show="!minimizado" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-2 md:p-4">
        <div class="bg-white rounded-2xl w-full max-w-3xl h-[95vh] md:h-[85vh] flex flex-col shadow-2xl">
            <div class="bg-gradient-to-r from-indigo-600 to-indigo-700 text-white p-4 flex justify-between items-center">
                <div>
                    <h3 class="text-lg font-bold">#<?=$pedido_atual['id']?> - <?=htmlspecialchars($pedido_atual['assunto'])?></h3>
                    <p class="text-indigo-200 text-sm"><?=$pedido_atual['status']==='aberto'?'🟢 Aberto':'🔒 Fechado'?></p>
                </div>
                <div class="flex gap-2">
                    <?php if($is_admin): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?=$csrf_token?>">
                        <input type="hidden" name="pedido_id" value="<?=$pedido_atual['id']?>">
                        <button name="toggle_status" class="bg-white/20 rounded-full p-2 hover:bg-white/30 transition">
                            <i class="fas fa-<?=$pedido_atual['status']==='aberto'?'lock':'unlock'?>"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                    <button @click="minimizado = true" class="bg-white/20 rounded-full p-2 hover:bg-white/30 transition">
                        <i class="fas fa-window-minimize"></i>
                    </button>
                    <a href="dashboard.php" class="bg-white/20 rounded-full p-2 hover:bg-white/30 transition">
                        <i class="fas fa-times"></i>
                    </a>
                </div>
            </div>
            <div class="flex-1 overflow-y-auto p-4 space-y-3 bg-gray-50" x-ref="msgContainer">
                <?php foreach($mensagens as $msg):
                    $meu = (!$is_admin && $msg['user_id'] == $user_id) || ($is_admin && ($msg['autor_role']??'') === 'admin' && $msg['user_id'] == $user_id);
                ?>
                <div class="flex <?=$meu?'justify-end':'justify-start'?>">
                    <div class="chat-msg <?=$meu?'bg-indigo-500 text-white rounded-t-2xl rounded-bl-2xl':'bg-white border rounded-t-2xl rounded-br-2xl shadow'?> p-3">
                        <div class="text-xs <?=$meu?'text-indigo-200':'text-gray-500'?> mb-1">
                            <?=htmlspecialchars($msg['autor_nome']??'Sistema')?> • <?=date('d/m H:i',strtotime($msg['criado_em']))?>
                        </div>
                        <div><?=nl2br(htmlspecialchars($msg['mensagem']))?></div>
                        <?php if($meu): ?>
                            <div class="text-[11px] <?=$meu?'text-indigo-200':'text-gray-500'?> mt-1">
                                <?= !empty($mensagens_lidas_por_outros[$msg['id']]) ? '✓✓ Lida' : '✓ Enviada' ?>
                            </div>
                        <?php endif; ?>
                        <?php if($msg['anexo']): ?>
                            <div class="mt-2">
                                <?php $ext = strtolower(pathinfo($msg['anexo'], PATHINFO_EXTENSION)); ?>
                                <?php if(in_array($ext, ['jpg','jpeg','png','gif'])): ?>
                                    <a href="<?=htmlspecialchars($msg['anexo'])?>" target="_blank">
                                        <img src="<?=htmlspecialchars($msg['anexo'])?>" class="rounded max-h-32 object-cover">
                                    </a>
                                <?php else: ?>
                                    <a href="<?=htmlspecialchars($msg['anexo'])?>" target="_blank" class="text-sm underline <?=$meu?'text-indigo-200':'text-indigo-600'?>">
                                        <i class="fas fa-paperclip"></i> <?=basename($msg['anexo'])?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <form @submit.prevent="enviarMensagem" enctype="multipart/form-data" class="p-4 bg-white border-t">
                <input type="hidden" name="csrf_token" value="<?=$csrf_token?>">
                <input type="hidden" name="contacto_id" value="<?=$pedido_atual['id']?>">
                <div class="flex gap-2 items-end">
                    <div class="flex-1">
                        <textarea x-ref="mensagemInput" name="mensagem" rows="2" class="w-full border rounded-xl p-2 focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="Responder..." required></textarea>
                        <div class="mt-2">
                            <label class="text-xs text-gray-500">Resposta rápida</label>
                            <select @change="$refs.mensagemInput.value = $event.target.value" class="w-full border rounded-lg p-2 text-sm">
                                <option value="">Selecionar template...</option>
                                <option>Olá! Recebemos o seu pedido e estamos a analisar. Respondemos em breve.</option>
                                <option>Obrigado pelo contacto. Precisamos de mais detalhes para avançar com a análise.</option>
                                <option>Pedido concluído com sucesso. Caso precise, estamos disponíveis para ajustes.</option>
                            </select>
                        </div>
                        <div class="mt-2" x-data="anexo()" x-ref="anexoComponent">
                            <div class="drop-zone p-2" :class="{ 'drag': arrastando }" @dragover.prevent="arrastando = true" @dragleave.prevent="arrastando = false" @drop.prevent="largar($event)">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <label class="cursor-pointer text-gray-500 hover:text-indigo-600 text-sm">
                                        <i class="fas fa-paperclip"></i> Anexar
                                        <input type="file" name="anexo" class="hidden" @change="selecionar" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.txt" x-ref="inputFile">
                                    </label>
                                    <span class="text-xs text-gray-400">Máx 5MB</span>
                                    <template x-if="ficheiro">
                                        <span class="bg-gray-100 px-2 py-1 rounded-full text-sm flex items-center gap-1">
                                            <img x-show="preview" :src="preview" class="w-5 h-5 object-cover rounded"> 
                                            <span x-text="ficheiro.name"></span>
                                            <button type="button" @click="remover" class="text-red-500">&times;</button>
                                        </span>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="bg-indigo-600 text-white rounded-full p-3 w-12 h-12 flex items-center justify-center flex-shrink-0 hover:bg-indigo-700 transition" :disabled="enviando">
                        <i class="fas fa-paper-plane" x-show="!enviando"></i>
                        <i class="fas fa-spinner fa-spin" x-show="enviando"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <div x-show="minimizado" class="fixed bottom-4 right-4 z-50" @click="minimizado = false">
        <div class="bg-indigo-600 text-white rounded-full shadow-lg p-3 flex items-center gap-2 cursor-pointer hover:bg-indigo-700 transition">
            <i class="fas fa-comment-dots"></i> Chat #<?=$pedido_atual['id']?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal Reabertura -->
<div x-show="modalReabrir" x-cloak class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" @click.away="modalReabrir = false">
    <div class="bg-white rounded-2xl max-w-md w-full p-6" @click.stop>
        <h3 class="text-xl font-bold mb-4">Reabrir Pedido</h3>
        <form method="POST" action="dashboard.php">
            <input type="hidden" name="csrf_token" value="<?=$csrf_token?>">
            <input type="hidden" name="pedido_id" :value="reabrirId">
            <div class="mb-3">
                <label class="block font-medium mb-1">Assunto</label>
                <input type="text" name="assunto" required class="w-full border rounded-lg p-2">
            </div>
            <div class="mb-3">
                <label class="block font-medium mb-1">Justificação</label>
                <textarea name="mensagem_reabertura" rows="3" required class="w-full border rounded-lg p-2"></textarea>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" @click="modalReabrir = false" class="bg-gray-300 px-4 py-2 rounded-lg hover:bg-gray-400 transition">Cancelar</button>
                <button type="submit" name="reabrir_pedido" class="bg-yellow-500 text-white px-4 py-2 rounded-lg hover:bg-yellow-600 transition">Reabrir</button>
            </div>
        </form>
    </div>
</div>

<script>
window.abaInicial = '<?= $aba_inicial ?>';
</script>
<script src="dashboard.js"></script>
</body>
</html>
