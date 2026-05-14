<?php
require 'db.php';
require 'functions.php';

$id = $_GET['id'] ?? 0;
$stmt = $pdo->prepare("SELECT * FROM artigos WHERE id = ? AND publicado = 1");
$stmt->execute([$id]);
$artigo = $stmt->fetch();

if (!$artigo) {
    redirecionar('index.php');
}

$pdo->prepare("UPDATE artigos SET visualizacoes = visualizacoes + 1 WHERE id = ?")->execute([$id]);

$csrf_token = gerarTokenCSRF();

$url_artigo = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
$titulo_encoded = urlencode($artigo['titulo']);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($artigo['meta_title'] ?: $artigo['titulo']) ?> | MeuSite</title>
    <meta name="description" content="<?= htmlspecialchars($artigo['meta_description'] ?: substr(strip_tags($artigo['conteudo']), 0, 160)) ?>">
    <meta name="keywords" content="<?= htmlspecialchars($artigo['meta_keywords'] ?? '') ?>">
    <meta property="og:title" content="<?= htmlspecialchars($artigo['meta_title'] ?: $artigo['titulo']) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($artigo['meta_description'] ?: substr(strip_tags($artigo['conteudo']), 0, 160)) ?>">
    <?php if ($artigo['imagem']): ?>
    <meta property="og:image" content="<?= htmlspecialchars($artigo['imagem']) ?>">
    <?php endif; ?>
    <meta property="og:url" content="<?= htmlspecialchars($url_artigo) ?>">
    <meta property="og:type" content="article">
    <meta property="og:site_name" content="MeuSite">
    <meta name="twitter:card" content="summary_large_image">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .prose img { border-radius: 0.75rem; max-width: 100%; height: auto; }
        .prose p { margin-bottom: 1rem; }
    </style>
</head>
<body class="bg-gray-50">

<nav class="bg-white shadow sticky top-0 z-40 p-4 flex justify-between items-center">
    <a href="index.php" class="text-2xl font-bold text-indigo-600">MeuSite</a>
    <div class="hidden md:flex gap-4 items-center">
        <a href="index.php#servicos" class="hover:text-indigo-600">Serviços</a>
        <a href="index.php#portfolio" class="hover:text-indigo-600">Trabalhos</a>
        <a href="index.php#blog" class="hover:text-indigo-600">Blog</a>
        <a href="index.php#contacto" class="hover:text-indigo-600">Contacto</a>
    </div>
    <a href="index.php#blog" class="text-gray-600 hover:text-indigo-600"><i class="fas fa-arrow-left"></i> Blog</a>
</nav>

<div class="max-w-3xl mx-auto px-4 pt-4">
    <nav class="text-sm text-gray-500">
        <a href="index.php" class="hover:text-indigo-600">Home</a> <span class="mx-1">/</span>
        <a href="index.php#blog" class="hover:text-indigo-600">Blog</a> <span class="mx-1">/</span>
        <span><?= htmlspecialchars($artigo['titulo']) ?></span>
    </nav>
</div>

<main class="max-w-3xl mx-auto py-8 px-4">
    <article>
        <?php if ($artigo['imagem']): ?>
            <img src="<?= htmlspecialchars($artigo['imagem']) ?>" alt="<?= htmlspecialchars($artigo['titulo']) ?>" class="w-full h-64 md:h-96 object-cover rounded-xl mb-6">
        <?php endif; ?>
        <h1 class="text-3xl md:text-4xl font-bold mb-4"><?= htmlspecialchars($artigo['titulo']) ?></h1>
        <div class="flex flex-wrap items-center text-gray-500 text-sm mb-6 gap-4">
            <span><i class="far fa-calendar-alt mr-1"></i> <?= date('d/m/Y', strtotime($artigo['criado_em'])) ?></span>
            <span><i class="far fa-eye mr-1"></i> <?= $artigo['visualizacoes'] ?> visualizações</span>
        </div>

        <div class="prose max-w-none text-gray-700 text-lg leading-relaxed">
            <?= nl2br(htmlspecialchars($artigo['conteudo'])) ?>
        </div>

        <div class="mt-8 pt-6 border-t flex items-center gap-4">
            <span class="text-gray-600 font-semibold">Partilhar:</span>
            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($url_artigo) ?>" target="_blank" class="bg-blue-600 text-white w-10 h-10 rounded-full flex items-center justify-center hover:bg-blue-700"><i class="fab fa-facebook-f"></i></a>
            <a href="https://twitter.com/intent/tweet?url=<?= urlencode($url_artigo) ?>&text=<?= $titulo_encoded ?>" target="_blank" class="bg-sky-500 text-white w-10 h-10 rounded-full flex items-center justify-center hover:bg-sky-600"><i class="fab fa-twitter"></i></a>
            <a href="https://www.linkedin.com/shareArticle?mini=true&url=<?= urlencode($url_artigo) ?>&title=<?= $titulo_encoded ?>" target="_blank" class="bg-blue-800 text-white w-10 h-10 rounded-full flex items-center justify-center hover:bg-blue-900"><i class="fab fa-linkedin-in"></i></a>
            <a href="https://api.whatsapp.com/send?text=<?= $titulo_encoded ?>%20-%20<?= urlencode($url_artigo) ?>" target="_blank" class="bg-green-500 text-white w-10 h-10 rounded-full flex items-center justify-center hover:bg-green-600"><i class="fab fa-whatsapp"></i></a>
        </div>
    </article>
</main>

<footer class="text-center py-6 text-gray-500 border-t mt-8">
    © <?= date('Y') ?> MeuSite
</footer>
</body>
</html>