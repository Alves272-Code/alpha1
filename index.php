<?php
require 'functions.php';
require 'db.php';

// ------------------------------------------------------------
// Processamento de formulários
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificarTokenCSRF($_POST['csrf_token'] ?? '')) {
        flash('erro', 'Token de segurança inválido.');
        header('Location: index.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    // --- Registo ---
    if ($action === 'register') {
        $nome   = trim($_POST['nome'] ?? '');
        $email  = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        $senha  = $_POST['password'] ?? '';

        if ($nome === '' || $email === '' || $senha === '') {
            flash('erro_registo', 'Todos os campos são obrigatórios.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('erro_registo', 'Email inválido.');
        } elseif (strlen($senha) < 8) {
            flash('erro_registo', 'A password deve ter pelo menos 8 caracteres.');
        } else {
            $stmt = $pdo->prepare("SELECT id FROM utilizadores WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                flash('erro_registo', 'Este email já está registado.');
            } else {
                $hash = password_hash($senha, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO utilizadores (nome, email, password) VALUES (?, ?, ?)");
                if ($stmt->execute([$nome, $email, $hash])) {
                    $user_id = $pdo->lastInsertId();
                    session_regenerate_id(true);
                    $_SESSION['user_id']   = $user_id;
                    $_SESSION['user_nome'] = $nome;
                    $_SESSION['user_email']= $email;
                    header('Location: dashboard.php');
                    exit;
                } else {
                    flash('erro_registo', 'Erro ao criar conta. Tente novamente.');
                }
            }
        }
        header('Location: index.php');
        exit;
    }

    // --- Login ---
    if ($action === 'login') {
        $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        $senha = $_POST['password'] ?? '';

        if (!$email || !$senha) {
            flash('erro_login', 'Preencha todos os campos.');
        } else {
            $stmt = $pdo->prepare("SELECT * FROM utilizadores WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if ($user && password_verify($senha, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['user_nome'] = $user['nome'];
                $_SESSION['user_email']= $user['email'];
                header('Location: dashboard.php');
                exit;
            } else {
                flash('erro_login', 'Email ou password incorretos.');
            }
        }
        header('Location: index.php');
        exit;
    }

    // --- Contacto ---
    if ($action === 'contacto') {
        $nome     = trim($_POST['nome'] ?? '');
        $email    = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        $assunto  = trim($_POST['assunto'] ?? '');
        $mensagem = trim($_POST['mensagem'] ?? '');

        if ($nome === '' || $email === '' || $mensagem === '') {
            flash('erro_contacto', 'Todos os campos são obrigatórios.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('erro_contacto', 'Email inválido.');
        } else {
            $user_id = $_SESSION['user_id'] ?? null;
            $stmt = $pdo->prepare("INSERT INTO contactos (user_id, nome, email, assunto, mensagem) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $nome, $email, $assunto ?: 'Contacto público', $mensagem]);
            flash('sucesso_contacto', 'Mensagem enviada com sucesso!');
        }
        header('Location: index.php#contacto');
        exit;
    }

    header('Location: index.php');
    exit;
}

// ------------------------------------------------------------
// Dados para a vista
// ------------------------------------------------------------
$csrf_token        = gerarTokenCSRF();
$erro_login        = flash('erro_login');
$erro_registo      = flash('erro_registo');
$sucesso_contacto  = flash('sucesso_contacto');
$erro_contacto     = flash('erro_contacto');

$abrirModalLogin   = !empty($erro_login) ? 'true' : 'false';
$abrirModalRegisto = !empty($erro_registo) ? 'true' : 'false';

// Artigos publicados (blog)
$artigos = $pdo->query("SELECT id, titulo, imagem, criado_em FROM artigos WHERE publicado = 1 ORDER BY criado_em DESC LIMIT 3")->fetchAll();
$notificacoes_home = [];
if (!empty($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT c.id, c.assunto,
      (SELECT user_id FROM mensagens_contacto WHERE contacto_id = c.id ORDER BY criado_em DESC, id DESC LIMIT 1) AS ultimo_user
      FROM contactos c
      WHERE c.user_id = ? AND c.status = 'aberto'
      ORDER BY c.criado_em DESC LIMIT 5");
    $stmt->execute([$uid]);
    foreach ($stmt->fetchAll() as $row) {
        if (!empty($row['ultimo_user']) && (int)$row['ultimo_user'] !== $uid) {
            $notificacoes_home[] = "Nova resposta no pedido #{$row['id']} ({$row['assunto']}).";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MeuSite – Serviços profissionais de desenvolvimento web, marketing digital e segurança. Simulador de orçamento online. Entre em contacto.">
    <meta name="keywords" content="criação de sites, desenvolvimento web, marketing digital, segurança, simular orçamento, Porto, Portugal">
    <title>MeuSite – Serviços Profissionais</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css" />
    <script defer src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>
    <link href="https://unpkg.com/aos@2.3.4/dist/aos.css" rel="stylesheet">
    <script defer src="https://unpkg.com/aos@2.3.4/dist/aos.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
        .faq-answer { max-height: 0; overflow: hidden; transition: max-height 0.3s; }
        .faq-answer.open { max-height: 300px; }
        .swiper { visibility: hidden; }
        .swiper-initialized { visibility: visible; }
        .range-slider::-webkit-slider-thumb { appearance: none; width: 18px; height: 18px; background: #4F46E5; border-radius: 50%; cursor: pointer; }
        .range-slider::-moz-range-thumb { width: 18px; height: 18px; background: #4F46E5; border-radius: 50%; cursor: pointer; border: none; }
    </style>
</head>
<body class="bg-gray-50"
      x-data="{
          menuMobile: false,
          faqAberto: null,
          modalLogin: <?= $abrirModalLogin ?>,
          modalRegisto: <?= $abrirModalRegisto ?>,
          lightbox: null,
          modalPrivacidade: false,
          modalTermos: false,
          tipoSite: 'landing',
          paginas: 3,
          extras: { seo: false, blog: false, loja: false },
          get precoSimulado() {
              let base = { landing: 300, institucional: 800, loja: 1500 }[this.tipoSite] || 0;
              let adicional = Math.max(0, (this.paginas - 3) * 50);
              let ext = 0;
              if (this.extras.seo) ext += 200;
              if (this.extras.blog) ext += 400;
              if (this.extras.loja && this.tipoSite !== 'loja') ext += 1000;
              return base + adicional + ext;
          }
      }"
      @keydown.escape.window="lightbox = null; modalLogin = false; modalRegisto = false; modalPrivacidade = false; modalTermos = false">

<!-- NAVBAR -->
<nav class="bg-white shadow sticky top-0 z-40 p-4 flex justify-between items-center">
    <span class="text-2xl font-bold text-indigo-600">MeuSite</span>
    <div class="hidden md:flex gap-4 items-center">
        <a href="#servicos" class="hover:text-indigo-600">Serviços</a>
        <a href="#simulador" class="hover:text-indigo-600">Simulador</a>
        <a href="#como-funciona" class="hover:text-indigo-600">Como Funciona</a>
        <a href="#tecnologias" class="hover:text-indigo-600">Tecnologias</a>
        <a href="#portfolio" class="hover:text-indigo-600">Trabalhos</a>
        <a href="#testemunhos" class="hover:text-indigo-600">Testemunhos</a>
        <a href="#blog" class="hover:text-indigo-600">Blog</a>
        <a href="#contacto" class="hover:text-indigo-600">Contacto</a>
        <button @click="modalLogin = true" class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 transition">Entrar</button>
    </div>
    <button @click="menuMobile = !menuMobile" class="md:hidden text-2xl"><i class="fas fa-bars"></i></button>
</nav>

<!-- MENU MOBILE -->
<div x-show="menuMobile" x-cloak class="bg-white border-t p-4 md:hidden space-y-2">
    <a href="#servicos" @click="menuMobile=false" class="block">Serviços</a>
    <a href="#simulador" @click="menuMobile=false" class="block">Simulador</a>
    <a href="#como-funciona" @click="menuMobile=false" class="block">Como Funciona</a>
    <a href="#tecnologias" @click="menuMobile=false" class="block">Tecnologias</a>
    <a href="#portfolio" @click="menuMobile=false" class="block">Trabalhos</a>
    <a href="#testemunhos" @click="menuMobile=false" class="block">Testemunhos</a>
    <a href="#blog" @click="menuMobile=false" class="block">Blog</a>
    <a href="#contacto" @click="menuMobile=false" class="block">Contacto</a>
    <button @click="modalLogin = true; menuMobile = false" class="bg-indigo-600 text-white px-4 py-2 rounded w-full">Entrar</button>
</div>

<!-- HERO SWIPER -->
<?php if (!empty($_SESSION['user_id'])): ?>
<section class="max-w-6xl mx-auto px-4 pt-6">
    <div class="bg-white rounded-2xl shadow p-4 md:p-5 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div>
            <p class="text-sm text-gray-500">Sessão iniciada como</p>
            <p class="font-semibold text-indigo-700"><?= htmlspecialchars($_SESSION['user_nome']) ?></p>
            <?php if (!empty($notificacoes_home)): ?>
                <p class="text-sm text-amber-700 mt-1"><i class="fas fa-bell mr-1"></i><?= count($notificacoes_home) ?> notificação(ões) nova(s)</p>
            <?php endif; ?>
        </div>
        <div class="flex gap-2">
            <a href="dashboard.php" class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700">Ir ao Painel</a>
            <a href="dashboard.php?aba=novo" class="bg-white border px-4 py-2 rounded-lg hover:bg-gray-50">Criar Pedido</a>
        </div>
    </div>
    <?php if (!empty($notificacoes_home)): ?>
        <div class="mt-3 bg-amber-50 border border-amber-200 text-amber-800 rounded-xl p-3">
            <?php foreach ($notificacoes_home as $n): ?>
                <p class="text-sm">• <?= htmlspecialchars($n) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>
<section class="swiper w-full h-[400px]">
    <div class="swiper-wrapper">
        <div class="swiper-slide"><img src="https://picsum.photos/id/104/1200/500" loading="lazy" class="w-full h-full object-cover" alt="Slide 1"></div>
        <div class="swiper-slide"><img src="https://picsum.photos/id/106/1200/500" loading="lazy" class="w-full h-full object-cover" alt="Slide 2"></div>
        <div class="swiper-slide"><img src="https://picsum.photos/id/15/1200/500" loading="lazy" class="w-full h-full object-cover" alt="Slide 3"></div>
    </div>
    <div class="swiper-pagination"></div>
</section>

<!-- SERVIÇOS -->
<section id="servicos" class="py-16 max-w-6xl mx-auto px-4" data-aos="fade-up">
    <h2 class="text-3xl font-bold text-center mb-10">Serviços</h2>
    <div class="grid md:grid-cols-3 gap-8">
        <div class="bg-white p-6 rounded-xl shadow text-center transform hover:scale-105 transition">
            <i class="fas fa-code text-4xl text-indigo-600 mb-3"></i>
            <h3 class="text-xl font-bold">Desenvolvimento</h3>
            <p class="text-gray-600 mt-2">Sites modernos e rápidos.</p>
        </div>
        <div class="bg-white p-6 rounded-xl shadow text-center transform hover:scale-105 transition">
            <i class="fas fa-chart-line text-4xl text-indigo-600 mb-3"></i>
            <h3 class="text-xl font-bold">Marketing</h3>
            <p class="text-gray-600 mt-2">Alcance o topo.</p>
        </div>
        <div class="bg-white p-6 rounded-xl shadow text-center transform hover:scale-105 transition">
            <i class="fas fa-shield-alt text-4xl text-indigo-600 mb-3"></i>
            <h3 class="text-xl font-bold">Segurança</h3>
            <p class="text-gray-600 mt-2">Proteção total.</p>
        </div>
    </div>
</section>

<!-- ESTATÍSTICAS -->
<section class="py-16 bg-indigo-600 text-white" data-aos="fade-up">
    <div class="max-w-6xl mx-auto px-4 grid grid-cols-2 md:grid-cols-4 gap-8 text-center">
        <?php
        $stats = [
            ['150+', 'Projetos Entregues'],
            ['80+', 'Clientes Satisfeitos'],
            ['10+', 'Anos de Experiência'],
            ['98%', 'Suporte Rápido']
        ];
        foreach ($stats as $stat):
        ?>
        <div x-data="{ val: 0 }" x-init="let target=<?= (int)$stat[0] ?>; let inc=target/50; let i=setInterval(()=>{ if(val<target){val+=inc}else{clearInterval(i);val=target} }, 30)">
            <span class="text-4xl font-bold"><span x-text="Math.floor(val)"></span><?= strpos($stat[0], '%') !== false ? '%' : (strpos($stat[0], '+') !== false ? '+' : '') ?></span>
            <p class="text-indigo-200 mt-2"><?= $stat[1] ?></p>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- SIMULADOR -->
<section id="simulador" class="py-16 bg-white" data-aos="fade-up">
    <div class="max-w-3xl mx-auto px-4">
        <h2 class="text-3xl font-bold text-center mb-8">Simulador de Orçamento</h2>
        <p class="text-center text-gray-600 mb-10">Ajuste as opções abaixo e veja uma estimativa instantânea.</p>
        <div class="bg-gray-50 p-6 rounded-xl shadow">
            <div class="grid md:grid-cols-2 gap-8">
                <div class="space-y-6">
                    <div>
                        <label class="font-semibold">Tipo de site</label>
                        <select x-model="tipoSite" class="w-full mt-1 border rounded p-2">
                            <option value="landing">Landing Page</option>
                            <option value="institucional">Site Institucional</option>
                            <option value="loja">Loja Virtual</option>
                        </select>
                    </div>
                    <div>
                        <label class="font-semibold">Nº de páginas <span class="text-sm text-gray-500">(mín 3)</span></label>
                        <input type="range" x-model.number="paginas" min="3" max="20" class="w-full range-slider mt-1">
                        <div class="flex justify-between text-sm text-gray-600"><span>3</span><span x-text="paginas"></span><span>20</span></div>
                    </div>
                    <div class="space-y-2 mt-4">
                        <label class="font-semibold block">Extras</label>
                        <label class="flex items-center gap-2"><input type="checkbox" x-model="extras.seo" class="rounded"> SEO (+200€)</label>
                        <label class="flex items-center gap-2"><input type="checkbox" x-model="extras.blog" class="rounded"> Blog (+400€)</label>
                        <label class="flex items-center gap-2"><input type="checkbox" x-model="extras.loja" :disabled="tipoSite==='loja'" class="rounded"> Loja adicional (+1000€)</label>
                    </div>
                </div>
                <div class="flex flex-col justify-center items-center bg-indigo-600 text-white rounded-xl p-6 shadow-lg">
                    <p class="text-indigo-200 text-lg">Preço Estimado</p>
                    <p class="text-5xl font-bold mt-2">€<span x-text="precoSimulado.toLocaleString('pt-PT')"></span></p>
                    <p class="text-indigo-200 text-sm mt-4">Valor de referência. Contacte-nos.</p>
                    <a href="#contacto" class="mt-4 bg-white text-indigo-700 px-4 py-2 rounded-lg font-semibold hover:bg-gray-100 transition">Solicitar Proposta</a>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- COMO FUNCIONA (Timeline) -->
<section id="como-funciona" class="py-16 bg-gray-100" data-aos="fade-up">
    <div class="max-w-5xl mx-auto px-4">
        <h2 class="text-3xl font-bold text-center mb-12">Como Funciona</h2>
        <div class="relative timeline hidden md:block">
            <div class="absolute left-1/2 transform -translate-x-1/2 w-1 bg-indigo-200 h-full"></div>
        </div>
        <div class="space-y-12">
            <?php
            $passos = [
                ['1', 'Contacto', 'Envie-nos a sua ideia ou pedido de orçamento.'],
                ['2', 'Análise', 'Definimos os requisitos, prazos e orçamento final.'],
                ['3', 'Desenvolvimento', 'Construímos o seu projeto com as melhores tecnologias.'],
                ['4', 'Entrega', 'Fazemos a entrega e damos suporte contínuo.'],
            ];
            foreach ($passos as $i => $p):
            ?>
            <div class="flex flex-col md:flex-row items-center gap-6 <?= $i % 2 == 0 ? 'md:flex-row' : 'md:flex-row-reverse' ?>">
                <div class="flex-1" data-aos="<?= $i % 2 == 0 ? 'fade-right' : 'fade-left' ?>">
                    <div class="bg-white p-6 rounded-xl shadow">
                        <span class="bg-indigo-600 text-white rounded-full w-8 h-8 flex items-center justify-center font-bold mb-2"><?= $p[0] ?></span>
                        <h3 class="text-xl font-bold"><?= $p[1] ?></h3>
                        <p class="text-gray-600"><?= $p[2] ?></p>
                    </div>
                </div>
                <div class="hidden md:block w-1/12"></div>
                <div class="flex-1 <?= $i % 2 == 0 ? '' : 'md:order-first' ?>"></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- TECNOLOGIAS -->
<section id="tecnologias" class="py-16 bg-white" data-aos="fade-up">
    <div class="max-w-6xl mx-auto px-4 text-center">
        <h2 class="text-3xl font-bold mb-12">Tecnologias que Utilizamos</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-8">
            <?php
            $techs = [
                ['fa-html5', 'HTML5', 'text-orange-600'],
                ['fa-css3-alt', 'CSS3', 'text-blue-500'],
                ['fa-js', 'JavaScript', 'text-yellow-500'],
                ['fa-php', 'PHP', 'text-indigo-600'],
                ['fa-laravel', 'Laravel', 'text-red-600'],
                ['fa-react', 'React', 'text-cyan-500'],
                ['fa-database', 'MySQL', 'text-blue-800'],
                ['fa-cloud', 'Cloud', 'text-gray-600'],
            ];
            foreach ($techs as $tech):
            ?>
            <div class="flex flex-col items-center p-4 hover:scale-105 transition">
                <i class="fab <?= $tech[0] ?> text-5xl <?= $tech[2] ?> mb-3"></i>
                <span class="font-semibold"><?= $tech[1] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- PORTEFÓLIO -->
<section id="portfolio" class="py-16 bg-gray-100" data-aos="fade-up">
    <div class="max-w-6xl mx-auto px-4">
        <h2 class="text-3xl font-bold text-center mb-10">Trabalhos Recentes</h2>
        <div class="grid md:grid-cols-3 gap-8">
            <?php
            $templates = [
                ['Loja Virtual TechStore', 'E-commerce moderno com painel admin', 'https://picsum.photos/id/1/400/300'],
                ['Portal de Notícias', 'Blog responsivo com categorias', 'https://picsum.photos/id/10/400/300'],
                ['Site Corporativo GreenEnergy', 'Institucional com animações', 'https://picsum.photos/id/20/400/300'],
                ['Landing Page de eBook', 'Conversão otimizada', 'https://picsum.photos/id/30/400/300'],
                ['App de Tarefas', 'Dashboard interativo com Vue.js', 'https://picsum.photos/id/40/400/300'],
                ['Restaurante BellaVita', 'Website com menu e reservas', 'https://picsum.photos/id/50/400/300'],
            ];
            foreach ($templates as $tpl):
            ?>
            <div class="bg-white rounded-xl shadow overflow-hidden hover:shadow-lg transition">
                <img src="<?= $tpl[2] ?>" loading="lazy" alt="<?= $tpl[0] ?>" class="w-full h-48 object-cover">
                <div class="p-4">
                    <h3 class="font-bold"><?= $tpl[0] ?></h3>
                    <p class="text-gray-600 text-sm"><?= $tpl[1] ?></p>
                    <a href="#" class="text-indigo-600 mt-2 inline-block hover:underline">Ver demo →</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- TESTEMUNHOS -->
<section id="testemunhos" class="py-16 bg-white" data-aos="fade-up">
    <div class="max-w-6xl mx-auto px-4">
        <h2 class="text-3xl font-bold text-center mb-10">O que dizem os nossos clientes</h2>
        <div class="swiper-testemunhos swiper">
            <div class="swiper-wrapper">
                <?php
                $testemunhos = [
                    ['Maria Santos', 'Empresária', 'Profissionais incríveis! O meu site ficou além das expectativas e o suporte foi impecável.'],
                    ['João Silva', 'Director de Marketing', 'Aumentaram as nossas vendas em 200% com o novo e-commerce. Recomendo vivamente.'],
                    ['Carla Rodrigues', 'Freelancer', 'O simulador no site deles é fantástico! Já sabia quanto ia gastar antes de contratar.'],
                    ['Rui Costa', 'CEO Startup', 'Entregaram o projeto antes do prazo e com qualidade excecional.'],
                    ['Ana Martins', 'Designer', 'A comunicação durante todo o processo foi transparente e rápida.'],
                ];
                foreach ($testemunhos as $t):
                ?>
                <div class="swiper-slide p-4">
                    <div class="bg-white rounded-xl shadow p-6 text-center h-full flex flex-col justify-between border border-gray-100">
                        <div>
                            <i class="fas fa-quote-left text-indigo-300 text-3xl mb-2"></i>
                            <p class="text-gray-700 italic">"<?= htmlspecialchars($t[2]) ?>"</p>
                        </div>
                        <div class="mt-4">
                            <strong><?= htmlspecialchars($t[0]) ?></strong><br>
                            <span class="text-sm text-gray-500"><?= htmlspecialchars($t[1]) ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="swiper-pagination mt-6"></div>
        </div>
    </div>
</section>

<!-- BLOG -->
<section id="blog" class="py-16 bg-gray-100" data-aos="fade-up">
    <div class="max-w-6xl mx-auto px-4">
        <h2 class="text-3xl font-bold text-center mb-12">Últimos Artigos</h2>
        <div class="grid md:grid-cols-3 gap-8">
            <?php if (empty($artigos)): ?>
                <p class="text-gray-500 col-span-3 text-center">Em breve novos artigos.</p>
            <?php else: ?>
                <?php foreach ($artigos as $a): ?>
                <div class="bg-white rounded-xl shadow overflow-hidden hover:shadow-lg transition">
                    <img src="<?= htmlspecialchars($a['imagem'] ?: 'https://picsum.photos/400/200?random=' . $a['id']) ?>" loading="lazy" class="w-full h-40 object-cover">
                    <div class="p-4">
                        <h3 class="font-bold text-lg mb-2"><?= htmlspecialchars($a['titulo']) ?></h3>
                        <p class="text-gray-500 text-sm"><?= date('d/m/Y', strtotime($a['criado_em'])) ?></p>
                        <a href="artigo.php?id=<?= $a['id'] ?>" class="text-indigo-600 mt-3 inline-block hover:underline">Ler mais →</a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- CTA -->
<section class="py-16 bg-indigo-600 text-white text-center" data-aos="zoom-in">
    <div class="max-w-4xl mx-auto px-4">
        <h2 class="text-3xl font-bold mb-4">Pronto para transformar a sua ideia em realidade?</h2>
        <p class="text-indigo-200 mb-8">Solicite um orçamento gratuito e sem compromisso.</p>
        <a href="#contacto" class="bg-white text-indigo-700 px-8 py-3 rounded-full font-bold text-lg hover:bg-gray-100 transition">Fale Connosco</a>
    </div>
</section>

<!-- CONTACTO -->
<section id="contacto" class="py-16 max-w-6xl mx-auto px-4" data-aos="fade-up">
    <h2 class="text-3xl font-bold text-center mb-12">Contacte-nos</h2>
    <div class="grid md:grid-cols-2 gap-12">
        <div class="space-y-6">
            <div class="bg-white p-6 rounded-xl shadow">
                <h3 class="text-xl font-bold mb-4">Informações</h3>
                <div class="space-y-3">
                    <p class="flex items-center gap-2"><i class="fas fa-map-marker-alt text-indigo-600 w-6"></i> Rua Exemplo, 123, 4000-000 Porto, Portugal</p>
                    <p class="flex items-center gap-2"><i class="fas fa-phone text-indigo-600 w-6"></i> +351 222 333 444</p>
                    <p class="flex items-center gap-2"><i class="fas fa-envelope text-indigo-600 w-6"></i> geral@meusite.pt</p>
                </div>
                <div class="mt-4">
                    <p class="font-semibold mb-2">Redes Sociais</p>
                    <div class="flex gap-3 text-2xl text-indigo-600">
                        <a href="#" class="hover:text-indigo-800"><i class="fab fa-facebook"></i></a>
                        <a href="#" class="hover:text-indigo-800"><i class="fab fa-instagram"></i></a>
                        <a href="#" class="hover:text-indigo-800"><i class="fab fa-linkedin"></i></a>
                        <a href="#" class="hover:text-indigo-800"><i class="fab fa-twitter"></i></a>
                    </div>
                </div>
            </div>
            <div class="bg-white p-2 rounded-xl shadow">
                <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3039.02270137928!2d-8.6125!3d41.1579!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0xd2465b5a4e0e9e1%3A0x9b2c8e5e0e7c8b6!2sAvenida%20da%20Liberdade%2C%204000-000%20Porto!5e0!3m2!1spt-PT!2spt!4v1680000000000" width="100%" height="250" style="border:0;" allowfullscreen="" loading="lazy"></iframe>
            </div>
        </div>

        <div class="bg-white p-6 rounded-xl shadow">
            <h3 class="text-xl font-bold mb-4">Enviar Mensagem</h3>
            <?php if ($sucesso_contacto): ?>
                <div class="bg-green-100 text-green-700 p-3 rounded mb-4"><?= htmlspecialchars($sucesso_contacto) ?></div>
            <?php elseif ($erro_contacto): ?>
                <div class="bg-red-100 text-red-700 p-3 rounded mb-4"><?= htmlspecialchars($erro_contacto) ?></div>
            <?php endif; ?>
            <form action="index.php" method="POST">
                <input type="hidden" name="action" value="contacto">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <div class="mb-3">
                    <input type="text" name="nome" placeholder="Nome" required class="w-full border p-2 rounded focus:ring-2 focus:ring-indigo-500 outline-none">
                </div>
                <div class="mb-3">
                    <input type="email" name="email" placeholder="Email" required class="w-full border p-2 rounded focus:ring-2 focus:ring-indigo-500 outline-none">
                </div>
                <div class="mb-3">
                    <input type="text" name="assunto" placeholder="Assunto (opcional)" class="w-full border p-2 rounded focus:ring-2 focus:ring-indigo-500 outline-none">
                </div>
                <div class="mb-3">
                    <textarea name="mensagem" rows="4" placeholder="Mensagem" required class="w-full border p-2 rounded focus:ring-2 focus:ring-indigo-500 outline-none"></textarea>
                </div>
                <button class="w-full bg-indigo-600 text-white py-2 rounded hover:bg-indigo-700 transition">Enviar</button>
            </form>
            <div class="mt-4 text-sm text-gray-500 text-center">
                Ao submeter, concorda com a nossa 
                <button @click="modalPrivacidade = true" class="text-indigo-600 underline">Política de Privacidade</button> e 
                <button @click="modalTermos = true" class="text-indigo-600 underline">Termos e Condições</button>.
            </div>
        </div>
    </div>
</section>

<!-- MODAIS -->
<div x-show="modalLogin" x-cloak class="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50" @click.away="modalLogin = false" x-transition.opacity>
    <div class="bg-white rounded-xl max-w-sm w-full p-6" @click.stop>
        <div class="flex justify-between items-center mb-4"><h3 class="text-xl font-bold">Login</h3><button @click="modalLogin = false">&times;</button></div>
        <?php if ($erro_login): ?><div class="bg-red-100 text-red-700 p-2 rounded mb-3"><?= htmlspecialchars($erro_login) ?></div><?php endif; ?>
        <form action="index.php" method="POST">
            <input type="hidden" name="action" value="login">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="email" name="email" placeholder="Email" required class="w-full border p-2 mb-3 rounded">
            <input type="password" name="password" placeholder="Password" required class="w-full border p-2 mb-3 rounded">
            <button class="w-full bg-indigo-600 text-white py-2 rounded hover:bg-indigo-700 transition">Entrar</button>
        </form>
        <p class="mt-3 text-sm text-center">Não tem conta? <button @click="modalLogin = false; modalRegisto = true" class="text-indigo-600 underline">Registe-se</button></p>
    </div>
</div>

<div x-show="modalRegisto" x-cloak class="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50" @click.away="modalRegisto = false" x-transition.opacity>
    <div class="bg-white rounded-xl max-w-sm w-full p-6" @click.stop>
        <div class="flex justify-between items-center mb-4"><h3 class="text-xl font-bold">Criar Conta</h3><button @click="modalRegisto = false">&times;</button></div>
        <?php if ($erro_registo): ?><div class="bg-red-100 text-red-700 p-2 rounded mb-3"><?= htmlspecialchars($erro_registo) ?></div><?php endif; ?>
        <form action="index.php" method="POST">
            <input type="hidden" name="action" value="register">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="text" name="nome" placeholder="Nome completo" required class="w-full border p-2 mb-3 rounded">
            <input type="email" name="email" placeholder="Email" required class="w-full border p-2 mb-3 rounded">
            <input type="password" name="password" placeholder="Password (mín. 8 car.)" minlength="8" required class="w-full border p-2 mb-3 rounded">
            <button class="w-full bg-green-600 text-white py-2 rounded hover:bg-green-700 transition">Criar Conta</button>
        </form>
        <p class="mt-3 text-sm text-center">Já tem conta? <button @click="modalRegisto = false; modalLogin = true" class="text-indigo-600 underline">Faça login</button></p>
    </div>
</div>

<div x-show="modalPrivacidade" x-cloak class="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50" @click.away="modalPrivacidade = false" x-transition.opacity>
    <div class="bg-white rounded-xl max-w-lg w-full p-6 max-h-[80vh] overflow-y-auto" @click.stop>
        <div class="flex justify-between items-center mb-4"><h3 class="text-xl font-bold">Política de Privacidade</h3><button @click="modalPrivacidade = false">&times;</button></div>
        <div class="prose text-sm text-gray-700 space-y-3">
            <p><strong>Última atualização:</strong> <?= date('Y') ?></p>
            <p>O MeuSite compromete-se a proteger a sua privacidade. Recolhemos apenas os dados necessários para fornecer os nossos serviços, como nome, email e mensagens de contacto. Não partilhamos as suas informações com terceiros sem o seu consentimento.</p>
            <p>Utilizamos cookies essenciais para o funcionamento do site. Pode desativá-los nas configurações do navegador, mas algumas funcionalidades poderão não funcionar corretamente.</p>
            <p>Os seus dados são armazenados de forma segura e pode solicitar a eliminação a qualquer momento através do email geral@meusite.pt.</p>
            <p>Ao utilizar o nosso site, concorda com esta política.</p>
        </div>
    </div>
</div>

<div x-show="modalTermos" x-cloak class="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50" @click.away="modalTermos = false" x-transition.opacity>
    <div class="bg-white rounded-xl max-w-lg w-full p-6 max-h-[80vh] overflow-y-auto" @click.stop>
        <div class="flex justify-between items-center mb-4"><h3 class="text-xl font-bold">Termos e Condições</h3><button @click="modalTermos = false">&times;</button></div>
        <div class="prose text-sm text-gray-700 space-y-3">
            <p><strong>Última atualização:</strong> <?= date('Y') ?></p>
            <p>Ao contratar os serviços do MeuSite, o cliente concorda com os seguintes termos:</p>
            <ul class="list-disc ml-4">
                <li>Os orçamentos são válidos por 15 dias.</li>
                <li>O prazo de entrega é acordado mutuamente e pode variar consoante a complexidade do projeto.</li>
                <li>O cliente é responsável pelo conteúdo fornecido (textos, imagens), garantindo que não infringe direitos de terceiros.</li>
                <li>O pagamento é dividido em 50% no início e 50% na entrega final, salvo acordo diferente.</li>
                <li>O MeuSite reserva-se o direito de recusar projetos que violem leis ou normas éticas.</li>
            </ul>
            <p>Para mais informações, contacte-nos.</p>
        </div>
    </div>
</div>

<!-- LIGHTBOX -->
<div x-show="lightbox" x-cloak class="fixed inset-0 bg-black/80 flex items-center justify-center z-50 p-4" @click="lightbox = null" x-transition.opacity>
    <img :src="lightbox" class="max-w-full max-h-full rounded shadow-lg" alt="Imagem ampliada">
</div>

<!-- FOOTER -->
<footer class="bg-gray-800 text-gray-300 py-6">
    <div class="max-w-6xl mx-auto px-4 flex flex-col md:flex-row justify-between items-center">
        <p>© <?= date('Y') ?> MeuSite – Todos os direitos reservados.</p>
        <div class="flex gap-4 mt-2 md:mt-0">
            <button @click="modalPrivacidade = true" class="hover:text-white underline">Privacidade</button>
            <button @click="modalTermos = true" class="hover:text-white underline">Termos</button>
        </div>
    </div>
</footer>

<script>
    document.addEventListener('alpine:init', () => {
        new Swiper('.swiper', {
            loop: true,
            autoplay: { delay: 4000 },
            pagination: { el: '.swiper-pagination', clickable: true },
        });
        new Swiper('.swiper-testemunhos', {
            loop: true,
            autoplay: { delay: 5000, disableOnInteraction: false },
            pagination: { el: '.swiper-testemunhos .swiper-pagination', clickable: true },
            slidesPerView: 1,
            spaceBetween: 20,
            breakpoints: {
                640: { slidesPerView: 2, spaceBetween: 30 },
                1024: { slidesPerView: 3, spaceBetween: 40 }
            }
        });
        AOS.init({ once: true });
    });
</script>
</body>
</html>
