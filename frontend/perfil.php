<?php
session_start();
include "../backend/ligabd.php";

$currentUserId = isset($_SESSION['id']) ? $_SESSION['id'] : 0;
$currentUserType = isset($_SESSION['id_tipos_utilizador']) ? $_SESSION['id_tipos_utilizador'] : 0;

if (!isset($_SESSION["id"])) {
    header("Location: login.php");
    exit;
}

// Função para transformar URLs em links clicáveis
function makeLinksClickable($text)
{
    $pattern = '/(https?:\/\/[^\s]+)/';
    $linkedText = preg_replace($pattern, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>', $text);
    return $linkedText;
}

// Função para contar comentários
function getCommentCount($con, $postId)
{
    $sql = "SELECT COUNT(*) as count FROM comentarios WHERE id_publicacao = $postId";
    $result = mysqli_query($con, $sql);
    $data = mysqli_fetch_assoc($result);
    return $data['count'];
}

// Função para verificar se o post está salvo
function isPostSaved($con, $userId, $postId)
{
    $sql = "SELECT * FROM publicacao_salvas
            WHERE utilizador_id = $userId AND publicacao_id = $postId";
    $result = mysqli_query($con, $sql);
    return mysqli_num_rows($result) > 0;
}

// Função para buscar imagens da publicação
function getPostImages($con, $postId)
{
    $sql = "SELECT url, content_warning, tipo FROM publicacao_medias 
            WHERE publicacao_id = $postId
            ORDER BY ordem ASC";
    $result = mysqli_query($con, $sql);
    $medias = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $medias[] = $row;
    }
    return $medias;
}

// Função para buscar dados da poll
function getPollData($con, $publicacaoId, $userId = null)
{
    $sql = "SELECT p.id, p.pergunta, p.data_expiracao, p.total_votos,
                   po.id as opcao_id, po.opcao_texto, po.votos, po.ordem
            FROM polls p
            JOIN poll_opcoes po ON p.id = po.poll_id
            WHERE p.publicacao_id = ?
            ORDER BY po.ordem ASC";
    
    $stmt = $con->prepare($sql);
    if (!$stmt) {
        error_log("Failed to prepare poll data query: " . $con->error);
        return null;
    }
    
    $stmt->bind_param("i", $publicacaoId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result === false) {
        error_log("Failed to get result for poll data: " . $stmt->error);
        return null;
    }

    if ($result->num_rows === 0) {
        return null;
    }

    $opcoes = [];
    $pollData = null;
    
    while ($row = $result->fetch_assoc()) {
        if (!$pollData) {
            $pollData = [
                'id' => $row['id'],
                'pergunta' => $row['pergunta'],
                'data_expiracao' => $row['data_expiracao'],
                'total_votos' => intval($row['total_votos']),
                'expirada' => strtotime($row['data_expiracao']) < time()
            ];
        }
        
        $opcoes[] = [
            'id' => intval($row['opcao_id']),
            'texto' => $row['opcao_texto'],
            'votos' => intval($row['votos']),
            'percentagem' => $pollData['total_votos'] > 0 ? 
                round((intval($row['votos']) / $pollData['total_votos']) * 100, 1) : 0
        ];
    }

    // Verificar se o usuário já votou
    $userVoted = false;
    $userVotedOption = null;
    
    if ($userId > 0 && $pollData) {
        $sqlUserVote = "SELECT opcao_id FROM poll_votos WHERE poll_id = ? AND utilizador_id = ?";
        $stmtUserVote = $con->prepare($sqlUserVote);
        if ($stmtUserVote) {
            $stmtUserVote->bind_param("ii", $pollData['id'], $userId);
            $stmtUserVote->execute();
            $voteResult = $stmtUserVote->get_result();
            
            if ($voteResult === false) {
                error_log("Failed to get result for user vote: " . $stmtUserVote->error);
            } else if ($voteResult->num_rows > 0) {
                $userVoted = true;
                $voteData = $voteResult->fetch_assoc();
                $userVotedOption = intval($voteData['opcao_id']);
            }
        } else {
            error_log("Failed to prepare user vote query: " . $con->error);
        }
    }

    return [
        'poll' => $pollData,
        'opcoes' => $opcoes,
        'user_voted' => $userVoted,
        'user_voted_option' => $userVotedOption
    ];
}

// Obter ID do perfil a visualizar
$perfilId = isset($_GET['id']) ? intval($_GET['id']) : $_SESSION['id'];

// Buscar dados do utilizador
$sqlUtilizador = "SELECT * FROM utilizadores WHERE id = $perfilId";
$resultUtilizador = mysqli_query($con, $sqlUtilizador);

if (mysqli_num_rows($resultUtilizador) == 0) {
    header("Location: index.php");
    exit;
}

$utilizador = mysqli_fetch_assoc($resultUtilizador);

// Buscar dados do perfil
$sqlPerfil = "SELECT * FROM perfis WHERE id_utilizador = $perfilId";
$resultPerfil = mysqli_query($con, $sqlPerfil);
$perfil = mysqli_fetch_assoc($resultPerfil);

// Verificar se está a seguir
$isFollowing = false;
if ($currentUserId != $perfilId) {
    $sqlSeguir = "SELECT * FROM seguidores WHERE id_seguidor = $currentUserId AND id_seguido = $perfilId";
    $resultSeguir = mysqli_query($con, $sqlSeguir);
    $isFollowing = mysqli_num_rows($resultSeguir) > 0;
}

// Contar seguidores e seguindo
$sqlSeguidores = "SELECT COUNT(*) as count FROM seguidores WHERE id_seguido = $perfilId";
$resultSeguidores = mysqli_query($con, $sqlSeguidores);
$seguidores = mysqli_fetch_assoc($resultSeguidores)['count'];

$sqlSeguindo = "SELECT COUNT(*) as count FROM seguidores WHERE id_seguidor = $perfilId";
$resultSeguindo = mysqli_query($con, $sqlSeguindo);
$seguindo = mysqli_fetch_assoc($resultSeguindo)['count'];

// Contar publicações
$sqlPublicacoes = "SELECT COUNT(*) as count FROM publicacoes WHERE id_utilizador = $perfilId AND deletado_em = '0000-00-00 00:00:00'";
$resultPublicacoes = mysqli_query($con, $sqlPublicacoes);
$totalPublicacoes = mysqli_fetch_assoc($resultPublicacoes)['count'];
?>

<!DOCTYPE html>
<html lang="pt">

<head>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($utilizador['nome_completo']); ?> - Orange</title>
    <link rel="stylesheet" href="css/style_perfil.css">
    <link rel="stylesheet" href="css/app.css">
    <link rel="stylesheet" href="css/style_polls.css">
    <link rel="stylesheet" href="css/video_player.css">
    <link rel="stylesheet" href="css/style_share.css">
    <link rel="icon" type="image/x-icon" href="images/favicon/favicon_orange.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body>
    <!-- Header -->
    <?php require "parciais/header.php" ?>

    <!-- Comments Modal -->
    <div id="commentsModal" class="modal-overlay" style="display: none; z-index: 1000;">
        <div class="comment-modal">
            <div class="modal-post" id="modalPostContent"></div>
            <div class="modal-comments">
                <div class="comments-list" id="commentsList"></div>
                <form class="comment-form" id="commentForm">
                    <input type="hidden" id="currentPostId" value="">
                    <input type="text" class="comment-input" id="commentInput" placeholder="Adicione um comentário..."
                        required>
                    <button type="submit" class="comment-submit">Publicar</button>
                </form>
            </div>
            <button class="close-button">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>

    <!-- Modal para mídia expandida -->
    <div id="imageModal" class="image-modal">
        <div class="image-modal-content">
            <button class="close-image-modal">&times;</button>
            <div id="modalImage" class="modal-image-container"></div>
        </div>
        <div class="image-modal-nav">
            <button id="prevImageBtn" class="modal-nav-btn">
                <i class="fas fa-chevron-left"></i>
            </button>
            <span id="imageCounter" class="image-counter">1 / 1</span>
            <button id="nextImageBtn" class="modal-nav-btn">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    </div>

    <!-- Profile Header -->
    <div class="profile-header">
        <div class="cover-photo" style="background-image: url('images/capa/<?php echo $perfil['foto_capa'] ?: 'default-capa.png'; ?>');">
            <?php if ($currentUserId == $perfilId): ?>
                <form action="../backend/upload_capa.php" method="post" enctype="multipart/form-data">
                    <button type="button" class="cover-photo-btn" onclick="document.getElementById('capaInput').click()">
                        <i class="fas fa-camera"></i> Alterar Capa
                    </button>
                    <input type="file" id="capaInput" name="foto" accept="image/*" style="display: none;" onchange="this.form.submit()">
                </form>
            <?php endif; ?>
        </div>

        <div class="profile-photo-container">
            <div class="profile-photo-wrapper">
                <img src="images/perfil/<?php echo $perfil['foto_perfil'] ?: 'default-profile.jpg'; ?>" 
                     alt="<?php echo htmlspecialchars($utilizador['nome_completo']); ?>" class="profile-photo">
                
                <?php if ($currentUserId == $perfilId): ?>
                    <form action="../backend/upload_foto.php" method="post" enctype="multipart/form-data">
                        <button type="button" class="change-photo-btn" onclick="document.getElementById('fotoInput').click()">
                            <i class="fas fa-camera"></i>
                        </button>
                        <input type="file" id="fotoInput" name="foto" accept="image/*" style="display: none;" onchange="this.form.submit()">
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <main>
        <!-- Profile Card -->
        <div class="profile-card">
            <div class="profile-header-content">
                <div class="profile-info">
                    <h1><?php echo htmlspecialchars($utilizador['nome_completo']); ?></h1>
                    <p class="nickperfil">@<?php echo htmlspecialchars($utilizador['nick']); ?></p>
                    
                    <?php if (!empty($perfil['ocupacao']) || !empty($perfil['pais']) || !empty($perfil['cidade'])): ?>
                        <div class="contact-info">
                            <?php if (!empty($perfil['ocupacao'])): ?>
                                <span><i class="fas fa-briefcase"></i> <?php echo htmlspecialchars($perfil['ocupacao']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($perfil['pais'])): ?>
                                <span><i class="fas fa-map-marker-alt"></i> 
                                    <?php echo htmlspecialchars($perfil['pais']); ?>
                                    <?php if (!empty($perfil['cidade'])): ?>
                                        , <?php echo htmlspecialchars($perfil['cidade']); ?>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($currentUserId == $perfilId): ?>
                    <a href="editar_perfil.php" class="edit-profile-btn">Editar Perfil</a>
                <?php else: ?>
                    <button class="follow-btn <?php echo $isFollowing ? 'unfollow-btn' : ''; ?>" 
                            data-user-id="<?php echo $perfilId; ?>">
                        <?php echo $isFollowing ? 'Deixar de Seguir' : 'Seguir'; ?>
                    </button>
                <?php endif; ?>
            </div>

            <?php if (!empty($perfil['biografia'])): ?>
                <p class="bio"><?php echo nl2br(htmlspecialchars($perfil['biografia'])); ?></p>
            <?php endif; ?>

            <div class="stats">
                <div class="stat">
                    <i class="fas fa-file-alt"></i>
                    <strong><?php echo $totalPublicacoes; ?></strong> Publicações
                </div>
                <div class="stat">
                    <i class="fas fa-users"></i>
                    <strong><?php echo $seguidores; ?></strong> Seguidores
                </div>
                <div class="stat">
                    <i class="fas fa-user-plus"></i>
                    <strong><?php echo $seguindo; ?></strong> A Seguir
                </div>
            </div>

            <?php if (!empty($perfil['x']) || !empty($perfil['linkedin']) || !empty($perfil['github'])): ?>
                <div class="social-links">
                    <?php if (!empty($perfil['x'])): ?>
                        <a href="<?php echo htmlspecialchars($perfil['x']); ?>" target="_blank" class="social-link">
                            <i class="fab fa-x-twitter"></i>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($perfil['linkedin'])): ?>
                        <a href="<?php echo htmlspecialchars($perfil['linkedin']); ?>" target="_blank" class="social-link">
                            <i class="fab fa-linkedin"></i>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($perfil['github'])): ?>
                        <a href="<?php echo htmlspecialchars($perfil['github']); ?>" target="_blank" class="social-link">
                            <i class="fab fa-github"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Posts -->
        <div class="posts">
            <?php
            $sql = "SELECT p.id_publicacao, p.conteudo, p.data_criacao, p.likes, p.tipo,
                           u.id AS id_utilizador, u.nick, 
                           pr.foto_perfil, pr.ocupacao 
                    FROM publicacoes p
                    JOIN utilizadores u ON p.id_utilizador = u.id
                    LEFT JOIN perfis pr ON u.id = pr.id_utilizador
                    WHERE p.id_utilizador = $perfilId AND p.deletado_em = '0000-00-00 00:00:00'
                    ORDER BY p.data_criacao DESC";

            $resultado = mysqli_query($con, $sql);

            if (mysqli_num_rows($resultado) > 0) {
                while ($linha = mysqli_fetch_assoc($resultado)) {
                    $foto = $linha['foto_perfil'] ?: 'default-profile.jpg';
                    $ocupacao = $linha['ocupacao'] ?: 'Utilizador';
                    $publicacaoId = $linha['id_publicacao'];

                    // Verificar se o usuário logado já deu like
                    $likedClass = '';
                    $checkSql = "SELECT * FROM publicacao_likes 
                                 WHERE publicacao_id = $publicacaoId AND utilizador_id = $currentUserId";
                    $checkResult = mysqli_query($con, $checkSql);
                    if (mysqli_num_rows($checkResult) > 0) {
                        $likedClass = 'liked';
                    }

                    // Verificar se o post está salvo
                    $savedClass = isPostSaved($con, $currentUserId, $publicacaoId) ? 'saved' : '';

                    // Buscar imagens da publicação
                    $images = getPostImages($con, $publicacaoId);
                    ?>
                    <article class="post" data-post-id="<?php echo $publicacaoId; ?>">
                        <div class="post-header">
                            <a href="perfil.php?id=<?php echo $linha['id_utilizador']; ?>">
                                <img src="images/perfil/<?php echo htmlspecialchars($foto); ?>" alt="User"
                                    class="profile-pic">
                            </a>
                            <div class="post-info">
                                <div>
                                    <a href="perfil.php?id=<?php echo $linha['id_utilizador']; ?>" class="profile-link">
                                        <h3><?php echo htmlspecialchars($linha['nick']); ?></h3>
                                    </a>
                                    <p><?php echo htmlspecialchars($ocupacao); ?></p>
                                </div>
                                <span
                                    class="timestamp"><?php echo date('d-m-Y H:i', strtotime($linha['data_criacao'])); ?></span>
                            </div>
                        </div>
                        <div class="post-content">
                            <?php if (!empty($linha['conteudo'])): ?>
                                <p><?php echo nl2br(makeLinksClickable($linha['conteudo'])); ?></p>
                            <?php endif; ?>

                            <?php if ($linha['tipo'] === 'poll'): ?>
                                <?php 
                                $pollData = getPollData($con, $linha['id_publicacao'], $_SESSION['id']);
                                if (is_array($pollData) && array_key_exists('poll', $pollData) && is_array($pollData['poll'])): 
                                ?>
                                    <div class="poll-container" data-poll-id="<?php echo $pollData['poll']['id']; ?>">
                                        <div class="poll-question"><?php echo htmlspecialchars($pollData['poll']['pergunta']); ?></div>
                                        
                                        <div class="poll-options">
                                            <?php foreach ($pollData['opcoes'] as $opcao): ?>
                                                <div class="poll-option <?php echo ($pollData['user_voted'] || $pollData['poll']['expirada']) ? 'disabled voted' : ''; ?> <?php echo ($pollData['user_voted_option'] == $opcao['id']) ? 'user-voted' : ''; ?>" 
                                                     data-opcao-id="<?php echo $opcao['id']; ?>"
                                                     <?php if (!$pollData['user_voted'] && !$pollData['poll']['expirada']): ?>
                                                         onclick="voteInPoll(<?php echo $pollData['poll']['id']; ?>, <?php echo $opcao['id']; ?>)"
                                                     <?php endif; ?>>
                                                    <div class="poll-option-progress" style="width: <?php echo $opcao['percentagem']; ?>%"></div>
                                                    <div class="poll-option-content">
                                                        <span class="poll-option-text"><?php echo htmlspecialchars($opcao['texto']); ?></span>
                                                        <?php if ($pollData['user_voted'] || $pollData['poll']['expirada']): ?>
                                                            <div class="poll-option-stats">
                                                                <span class="poll-option-percentage"><?php echo $opcao['percentagem']; ?>%</span>
                                                                <span class="poll-option-votes"><?php echo $opcao['votos']; ?> voto<?php echo $opcao['votos'] !== 1 ? 's' : ''; ?></span>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        
                                        <div class="poll-meta">
                                            <span class="poll-total-votes"><?php echo $pollData['poll']['total_votos']; ?> voto<?php echo $pollData['poll']['total_votos'] !== 1 ? 's' : ''; ?></span>
                                            <span class="poll-time-left <?php echo $pollData['poll']['expirada'] ? 'poll-expired' : ''; ?>">
                                                <i class="fas fa-clock"></i>
                                                <?php echo $pollData['poll']['expirada'] ? 'Poll encerrada' : 'Poll ativa'; ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if (!empty($images)): ?>
                                <div class="post-images">
                                    <?php
                                    $imageCount = count($images);
                                    $gridClass = '';
                                    if ($imageCount == 1)
                                        $gridClass = 'single';
                                    elseif ($imageCount == 2)
                                        $gridClass = 'double';
                                    elseif ($imageCount == 3)
                                        $gridClass = 'triple';
                                    elseif ($imageCount == 4)
                                        $gridClass = 'quad';
                                    else
                                        $gridClass = 'multiple';
                                    ?>
                                    <div class="images-grid <?php echo $gridClass; ?>">
                                        <?php foreach ($images as $i => $media): ?>
                                            <?php if ($i < 4 || $imageCount <= 4): ?>
                                                <div class="media-item"
                                                    onclick="openMediaModal(<?php echo $publicacaoId; ?>, <?php echo $i; ?>)">
                                                    <?php if ($media['tipo'] === 'video'): ?>
                                                        <div class="video-container">
                                                            <video muted preload="metadata" playsInline>
                                                                <source
                                                                    src="images/publicacoes/<?php echo htmlspecialchars($media['url']); ?>"
                                                                    type="video/mp4">
                                                                Seu navegador não suporta vídeos.
                                                            </video>
                                                        </div>
                                                    <?php else: ?>
                                                        <img src="images/publicacoes/<?php echo htmlspecialchars($media['url']); ?>"
                                                            alt="Imagem da publicação" class="post-media">
                                                    <?php endif; ?>
                                                    <?php if ($i == 3 && $imageCount > 4): ?>
                                                        <div class="more-images-overlay">
                                                            +<?php echo $imageCount - 4; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="post-actions">
                            <button class="like-btn <?php echo $likedClass; ?>"
                                data-publicacao-id="<?php echo $publicacaoId; ?>">
                                <i class="fas fa-thumbs-up"></i>
                                <span class="like-count"><?php echo $linha['likes']; ?></span>
                            </button>
                            <button class="comment-btn" onclick="openCommentsModal(<?php echo $linha['id_publicacao']; ?>)">
                                <i class="fas fa-comment"></i>
                                <span
                                    class="comment-count"><?php echo getCommentCount($con, $linha['id_publicacao']); ?></span>
                            </button>
                            <button class="share-btn" onclick="openShareModal(<?php echo $publicacaoId; ?>)">
                                <i class="fas fa-share"></i>
                            </button>
                            <button class="save-btn <?php echo $savedClass; ?>"
                                data-publicacao-id="<?php echo $publicacaoId; ?>">
                                <i class="fas fa-bookmark"></i>
                            </button>
                            <?php if ($_SESSION['id'] == $linha['id_utilizador'] || $_SESSION['id_tipos_utilizador'] == 2): ?>
                                <button class="delete-btn" onclick="deletePost(<?php echo $publicacaoId; ?>, this)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </article>
                    <?php
                }
            } else {
                echo "<p class='no-activity'>Este utilizador ainda não fez nenhuma publicação.</p>";
            }
            ?>
        </div>

        <div id="toast" class="toast">
            <div class="toast-icon">
                <i class="fas fa-check"></i>
            </div>
            <div class="toast-content">
                <p id="toast-message">Mensagem aqui</p>
            </div>
        </div>
    </main>

    <?php require "parciais/footer.php" ?>

    <!-- Include Video Player JavaScript -->
    <script src="js/video-player.js"></script>
    <script src="js/polls.js"></script>
    <script src="js/share-post.js"></script>

    <script>
        // Função para votar em uma poll
        async function voteInPoll(pollId, opcaoId) {
            try {
                const optionElement = document.querySelector(`[data-opcao-id="${opcaoId}"]`);
                if (optionElement) {
                    optionElement.classList.add('voting');
                }

                const formData = new FormData();
                formData.append('poll_id', pollId);
                formData.append('opcao_id', opcaoId);

                const response = await fetch('../backend/votar_poll.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    updatePollDisplay(pollId, data);
                    showToast('Voto registado com sucesso!');
                } else {
                    showToast(data.message || 'Erro ao votar', 'error');
                }
            } catch (error) {
                console.error('Erro ao votar:', error);
                showToast('Erro de conexão', 'error');
            } finally {
                if (optionElement) {
                    optionElement.classList.remove('voting');
                }
            }
        }

        function updatePollDisplay(pollId, data) {
            const pollContainer = document.querySelector(`[data-poll-id="${pollId}"]`);
            if (!pollContainer) return;

            // Atualizar opções
            data.opcoes.forEach(opcao => {
                const optionElement = pollContainer.querySelector(`[data-opcao-id="${opcao.id}"]`);
                if (optionElement) {
                    // Atualizar barra de progresso
                    const progressBar = optionElement.querySelector('.poll-option-progress');
                    if (progressBar) {
                        progressBar.style.width = `${opcao.percentagem}%`;
                    }

                    // Atualizar estatísticas
                    const percentage = optionElement.querySelector('.poll-option-percentage');
                    const votes = optionElement.querySelector('.poll-option-votes');
                    
                    if (percentage) {
                        percentage.textContent = `${opcao.percentagem}%`;
                    }
                    
                    if (votes) {
                        votes.textContent = `${opcao.votos} voto${opcao.votos !== 1 ? 's' : ''}`;
                    }

                    // Marcar como votada e desabilitar
                    optionElement.classList.add('voted', 'disabled');
                    optionElement.style.pointerEvents = 'none';

                    // Destacar opção líder
                    if (opcao.percentagem > 0 && opcao.votos === Math.max(...data.opcoes.map(o => o.votos))) {
                        optionElement.classList.add('leading');
                    }

                    // Se for a opção votada pelo usuário
                    if (opcao.user_voted) {
                        optionElement.classList.add('user-voted');
                    }
                }
            });

            // Atualizar total de votos
            const totalVotesElement = pollContainer.querySelector('.poll-total-votes');
            if (totalVotesElement) {
                totalVotesElement.textContent = `${data.total_votos} voto${data.total_votos !== 1 ? 's' : ''}`;
            }
        }

        // Sistema de visualização de mídia
        let currentImageModal = {
            postId: null,
            currentIndex: 0,
            medias: []
        };

        function openMediaModal(postId, mediaIndex = 0) {
            const postElement = document.querySelector(`.post[data-post-id="${postId}"]`);
            if (!postElement) return;

            const medias = [];
            const mediaElements = postElement.querySelectorAll('.media-item');

            mediaElements.forEach(item => {
                const videoElement = item.querySelector('video');
                const imgElement = item.querySelector('img');

                if (videoElement) {
                    const source = videoElement.querySelector('source');
                    medias.push({
                        url: source ? source.src.split('/').pop() : '',
                        tipo: 'video'
                    });
                } else if (imgElement) {
                    medias.push({
                        url: imgElement.src.split('/').pop(),
                        tipo: 'imagem'
                    });
                }
            });

            if (medias.length === 0) return;

            currentImageModal = {
                postId,
                currentIndex: mediaIndex,
                medias
            };

            showMediaInModal();
            document.getElementById('imageModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function showMediaInModal() {
            const modal = document.getElementById('imageModal');
            const modalContent = document.getElementById('modalImage');
            const imageCounter = document.getElementById('imageCounter');
            const prevBtn = document.getElementById('prevImageBtn');
            const nextBtn = document.getElementById('nextImageBtn');

            modalContent.innerHTML = '';

            const currentMedia = currentImageModal.medias[currentImageModal.currentIndex];

            if (currentMedia.tipo === 'video') {
                const videoContainer = document.createElement('div');
                videoContainer.className = 'modal-video-container';

                const video = document.createElement('video');
                video.autoplay = false;
                video.controls = false;
                video.className = 'modal-media';
                video.muted = false;
                video.preload = 'metadata';
                video.playsInline = true;

                const source = document.createElement('source');
                source.src = `images/publicacoes/${currentMedia.url}`;
                source.type = 'video/mp4';

                video.appendChild(source);
                video.appendChild(document.createTextNode('Seu navegador não suporta vídeos.'));
                videoContainer.appendChild(video);
                modalContent.appendChild(videoContainer);

                setTimeout(() => {
                    new ModernVideoPlayer(video);
                }, 100);
            } else {
                const img = document.createElement('img');
                img.src = `images/publicacoes/${currentMedia.url}`;
                img.className = 'modal-media';
                img.alt = 'Imagem expandida';
                modalContent.appendChild(img);
            }

            imageCounter.textContent = `${currentImageModal.currentIndex + 1} / ${currentImageModal.medias.length}`;

            prevBtn.disabled = currentImageModal.currentIndex === 0;
            nextBtn.disabled = currentImageModal.currentIndex === currentImageModal.medias.length - 1;
        }

        function closeImageModal() {
            const modalContent = document.getElementById('modalImage');
            const videos = modalContent.getElementsByTagName('video');
            for (let video of videos) {
                video.pause();
            }

            document.getElementById('imageModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function navigateImage(direction) {
            if (direction === 'prev' && currentImageModal.currentIndex > 0) {
                currentImageModal.currentIndex--;
            } else if (direction === 'next' && currentImageModal.currentIndex < currentImageModal.medias.length - 1) {
                currentImageModal.currentIndex++;
            }
            showMediaInModal();
        }

        // Event listeners para o modal
        document.querySelector('.close-image-modal').addEventListener('click', closeImageModal);
        document.getElementById('prevImageBtn').addEventListener('click', () => navigateImage('prev'));
        document.getElementById('nextImageBtn').addEventListener('click', () => navigateImage('next'));

        document.getElementById('imageModal').addEventListener('click', function (e) {
            if (e.target === this) {
                closeImageModal();
            }
        });

        document.addEventListener('keydown', function (e) {
            const modal = document.getElementById('imageModal');
            if (modal.style.display === 'flex') {
                if (e.key === 'Escape') {
                    closeImageModal();
                } else if (e.key === 'ArrowLeft') {
                    navigateImage('prev');
                } else if (e.key === 'ArrowRight') {
                    navigateImage('next');
                }
            }
        });

        // Like functionality
        document.querySelectorAll('.like-btn').forEach(button => {
            button.addEventListener('click', function () {
                const publicacaoId = this.getAttribute('data-publicacao-id');
                const likeCount = this.querySelector('.like-count');

                fetch('../backend/like.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `id_publicacao=${publicacaoId}`
                })
                    .then(response => response.text())
                    .then(data => {
                        if (data === 'liked') {
                            this.classList.add('liked');
                            likeCount.textContent = parseInt(likeCount.textContent) + 1;
                        } else if (data === 'unliked') {
                            this.classList.remove('liked');
                            likeCount.textContent = parseInt(likeCount.textContent) - 1;
                        }
                    })
                    .catch(error => console.error('Error:', error));
            });
        });

        // Função para mostrar toast
        function showToast(message) {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toast-message');
            toastMessage.textContent = message;

            toast.style.display = 'flex';
            setTimeout(() => {
                toast.classList.add('show');
            }, 10);

            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => {
                    toast.style.display = 'none';
                }, 300);
            }, 3000);
        }

        // Save functionality
        document.querySelectorAll('.save-btn').forEach(button => {
            button.addEventListener('click', function () {
                const publicacaoId = this.getAttribute('data-publicacao-id');

                fetch('../backend/save_post.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `id_publicacao=${publicacaoId}`
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            if (data.action === 'saved') {
                                this.classList.add('saved');
                                showToast('Adicionado aos itens salvos');
                            } else {
                                this.classList.remove('saved');
                                showToast('Removido dos itens salvos');
                            }
                        }
                    })
                    .catch(error => console.error('Error:', error));
            });
        });

        // Follow functionality
        document.querySelectorAll('.follow-btn').forEach(button => {
            button.addEventListener('click', function () {
                const userId = this.getAttribute('data-user-id');
                const isFollowing = this.classList.contains('unfollow-btn');

                fetch('../backend/seguir_alternativo.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `user_id=${userId}`
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            if (data.action === 'follow') {
                                this.textContent = 'Deixar de Seguir';
                                this.classList.add('unfollow-btn');
                                showToast('Agora está a seguir este utilizador');
                            } else {
                                this.textContent = 'Seguir';
                                this.classList.remove('unfollow-btn');
                                showToast('Deixou de seguir este utilizador');
                            }
                        }
                    })
                    .catch(error => console.error('Error:', error));
            });
        });

        // Função para apagar publicação
        function deletePost(postId, element) {
            if (confirm('Tem certeza que deseja apagar esta publicação?')) {
                fetch('../backend/delete_post.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `id_publicacao=${postId}`
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            element.closest('.post').style.opacity = '0';
                            element.closest('.post').style.transform = 'translateX(-100px)';
                            setTimeout(() => {
                                element.closest('.post').remove();

                                const postsContainer = document.querySelector('.posts');
                                if (postsContainer.children.length === 0) {
                                    postsContainer.innerHTML = '<p class="no-activity">Este utilizador ainda não fez nenhuma publicação.</p>';
                                }
                            }, 300);

                            showToast('Publicação apagada com sucesso');
                        } else {
                            showToast('Erro ao apagar publicação');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showToast('Erro ao apagar publicação');
                    });
            }
        }

        // Modal de comentários
        const modal = document.getElementById('commentsModal');
        const closeButton = modal.querySelector('.close-button');

        function closeModal() {
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        closeButton.addEventListener('click', closeModal);

        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                closeModal();
            }
        });

        let currentPostId = null;

        function openCommentsModal(postId) {
            currentPostId = postId;

            const postElement = document.querySelector(`.post[data-post-id="${postId}"]`);
            if (postElement) {
                const postClone = postElement.cloneNode(true);
                const actions = postClone.querySelector('.post-actions');
                if (actions) actions.remove();

                document.getElementById('modalPostContent').innerHTML = '';
                document.getElementById('modalPostContent').appendChild(postClone);

                loadComments(postId);
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
            }
        }

        // Envio de comentário
        document.getElementById('commentForm').addEventListener('submit', function (e) {
            e.preventDefault();

            const commentInput = document.getElementById('commentInput');
            const content = commentInput.value.trim();

            if (content && currentPostId) {
                const formData = new FormData();
                formData.append('post_id', currentPostId);
                formData.append('content', content);

                fetch('../backend/add_comment.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(result => {
                        if (result.success) {
                            commentInput.value = '';
                            loadComments(currentPostId);

                            const commentCount = document.querySelector(`.comment-btn[onclick*="${currentPostId}"] .comment-count`);
                            if (commentCount) {
                                commentCount.textContent = parseInt(commentCount.textContent) + 1;
                            }
                        }
                    });
            }
        });

        function loadComments(postId) {
            fetch(`../backend/get_comments.php?post_id=${postId}`)
                .then(response => response.json())
                .then(comments => {
                    const commentsList = document.getElementById('commentsList');
                    commentsList.innerHTML = '';

                    if (comments.length === 0) {
                        const noCommentsMsg = document.createElement('div');
                        noCommentsMsg.className = 'no-comments';
                        noCommentsMsg.textContent = 'Ainda sem comentários. Seja o primeiro a comentar!';
                        commentsList.appendChild(noCommentsMsg);
                        return;
                    }

                    comments.forEach(comment => {
                        const dataComentario = new Date(comment.data);
                        const dataComentarioFormatada = `${dataComentario.getDate().toString().padStart(2, '0')}-${(dataComentario.getMonth() + 1).toString().padStart(2, '0')}-${dataComentario.getFullYear()} ${dataComentario.getHours().toString().padStart(2, '0')}:${dataComentario.getMinutes().toString().padStart(2, '0')}`;

                        const commentItem = document.createElement('div');
                        commentItem.className = 'comment-item';
                        commentItem.innerHTML = `
                    <a href="perfil.php?id=${comment.utilizador_id}">
                        <img src="images/perfil/${comment.foto_perfil || 'default-profile.jpg'}" alt="User" class="comment-avatar">
                    </a>
                    <div class="comment-content">
                        <div class="comment-header">
                            <div class="comment-user-info">
                                <a href="perfil.php?id=${comment.utilizador_id}" class="profile-link">
                                    <span class="comment-username">${comment.nick}</span>
                                </a>
                                <span class="comment-time">${dataComentarioFormatada}</span>
                            </div>
                        </div>
                        <p class="comment-text">${comment.conteudo}</p>
                    </div>
                `;
                        commentsList.appendChild(commentItem);
                    });
                });
        }

        // Initialize video thumbnails after page load
        document.addEventListener('DOMContentLoaded', function () {
            initializeVideoThumbnails();
        });
    </script>
</body>

</html>