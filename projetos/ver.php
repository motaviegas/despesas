<?php
require_once '../config/db.php';
require_once '../includes/functions.php';
verificarLogin();

$projeto_id = isset($_GET['projeto_id']) ? intval($_GET['projeto_id']) : 0;

// Verificar se o projeto existe
$stmt = $pdo->prepare("SELECT p.*, u.email as criador_email 
                      FROM projetos p 
                      LEFT JOIN usuarios u ON p.criado_por = u.id
                      WHERE p.id = :id");
$stmt->bindParam(':id', $projeto_id, PDO::PARAM_INT);
$stmt->execute();
$projeto = $stmt->fetch();

if (!$projeto) {
    header('Location: listar.php');
    exit;
}

// Obter estatísticas básicas
$stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT c.id) as total_categorias,
        COUNT(DISTINCT d.id) as total_despesas,
        COALESCE(SUM(d.valor), 0) as soma_despesas,
        (SELECT COALESCE(SUM(budget), 0) FROM categorias WHERE projeto_id = :projeto_id AND nivel = 1) as orcamento_total
    FROM categorias c
    LEFT JOIN despesas d ON c.id = d.categoria_id AND d.projeto_id = :projeto_id
    WHERE c.projeto_id = :projeto_id2
");
$stmt->bindParam(':projeto_id', $projeto_id, PDO::PARAM_INT);
$stmt->bindParam(':projeto_id2', $projeto_id, PDO::PARAM_INT);
$stmt->execute();
$estatisticas = $stmt->fetch();

// Calcular orçamento remanescente e percentagem de execução
$orcamento_remanescente = $estatisticas['orcamento_total'] - $estatisticas['soma_despesas'];
$percentagem_execucao = ($estatisticas['orcamento_total'] > 0) ? 
    ($estatisticas['soma_despesas'] / $estatisticas['orcamento_total']) * 100 : 0;

// Determinar a classe da barra de progresso com base na percentagem
if ($percentagem_execucao <= 50) {
    $barra_classe = "verde";
    $barra_largura = $percentagem_execucao * 2; // Dobro do valor para ocupar metade quando chegar a 50%
} elseif ($percentagem_execucao <= 75) {
    $barra_classe = "amarelo";
    $barra_largura = $percentagem_execucao;
} elseif ($percentagem_execucao <= 95) {
    $barra_classe = "laranja";
    $barra_largura = $percentagem_execucao;
} else {
    if ($percentagem_execucao > 100) {
        $barra_classe = "vermelho-intenso";
        $saldo_classe = "ultrapassado";
    } else {
        $barra_classe = "vermelho";
        $saldo_classe = "";
    }
    $barra_largura = min($percentagem_execucao, 100); // Limitar a 100% para exibição
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalhes do Projeto - Gestão de Eventos</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .projeto-detalhe {
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .info-section {
            margin-bottom: 30px;
        }
        
        .cards-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .card {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .card-title {
            font-size: 18px;
            color: #495057;
            margin-bottom: 15px;
            font-weight: 500;
        }
        
        .card-value {
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .positivo { color: #28a745; }
        .negativo { color: #dc3545; }
        .ultrapassado { 
            color: #dc3545; 
            font-weight: 900;
            font-size: 32px;
            text-shadow: 0px 0px 2px rgba(0,0,0,0.3);
        }
        
        .stats {
            margin-bottom: 30px;
        }
        
        .acoes-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .acoes-buttons .btn {
            display: block;
            text-align: center;
            padding: 12px 15px;
            font-weight: 500;
        }
        
        .back-link {
            margin-top: 20px;
        }
        
        /* Estilos para a barra de progresso */
        .progress-container {
            width: 100%;
            background-color: #e9ecef;
            border-radius: 4px;
            margin: 10px 0;
            position: relative;
            height: 25px;
            overflow: hidden;
        }
        
        .progress-bar {
            height: 100%;
            border-radius: 4px;
            transition: width 0.4s ease;
        }
        
        .progress-bar.verde {
            background: linear-gradient(to right, #28a745, #5cb85c);
        }
        
        .progress-bar.amarelo {
            background: linear-gradient(to right, #ffc107, #ffda6a);
        }
        
        .progress-bar.laranja {
            background: linear-gradient(to right, #fd7e14, #f8b67d);
        }
        
        .progress-bar.vermelho {
            background: linear-gradient(to right, #dc3545, #e66975);
        }
        
        .progress-bar.vermelho-intenso {
            background: linear-gradient(to right, #b10f1d, #dc3545);
            box-shadow: 0 0 8px rgba(220, 53, 69, 0.6);
        }
        
        .progress-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: #333;
            font-weight: bold;
            text-shadow: 0 0 2px rgba(255, 255, 255, 0.7);
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container">
        <h1>Detalhes do Projeto</h1>
        
        <div class="projeto-detalhe">
            <h2><?php echo htmlspecialchars($projeto['nome']); ?></h2>
            
            <div class="info-section">
                <p><strong>Descrição:</strong> <?php echo htmlspecialchars($projeto['descricao']); ?></p>
                <p><strong>Data de Criação:</strong> <?php echo date('d/m/Y', strtotime($projeto['data_criacao'])); ?></p>
                <p><strong>Criado por:</strong> <?php echo htmlspecialchars($projeto['criador_email']); ?></p>
            </div>
            
            <div class="resumo-financeiro">
                <h3>Resumo Financeiro</h3>
                
                <div class="cards-container">
                    <div class="card">
                        <div class="card-title">Total de Despesas</div>
                        <div class="card-value"><?php echo number_format($estatisticas['soma_despesas'], 2, ',', '.'); ?> €</div>
                    </div>
                    
                    <div class="card">
                        <div class="card-title">Orçamento Remanescente</div>
                        <div class="card-value <?php echo $orcamento_remanescente >= 0 ? 'positivo' : 'negativo ' . $saldo_classe; ?>">
                            <?php echo number_format($orcamento_remanescente, 2, ',', '.'); ?> €
                        </div>
                        <div class="progress-container">
                            <div class="progress-bar <?php echo $barra_classe; ?>" style="width: <?php echo $barra_largura; ?>%"></div>
                            <span class="progress-text"><?php echo number_format($percentagem_execucao, 1, ',', '.'); ?>%</span>
                        </div>
                    </div>
                    
                    <div class="card">
                        <div class="card-title">Orçamento Total</div>
                        <div class="card-value"><?php echo number_format($estatisticas['orcamento_total'], 2, ',', '.'); ?> €</div>
                    </div>
                </div>
                
                <div class="stats">
                    <p><strong>Total de Categorias:</strong> <?php echo $estatisticas['total_categorias']; ?></p>
                    <p><strong>Total de Despesas Registradas:</strong> <?php echo $estatisticas['total_despesas']; ?></p>
                </div>
            </div>
            
            <div class="acoes">
                <h3>Ações</h3>
                <div class="acoes-buttons">
                    <a href="../relatorios/gerar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn">Ver Orçamento</a>
                    <a href="../despesas/registrar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn">Nova Despesa</a>
                    <a href="../despesas/listar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn">Listar Despesas</a>
                    <a href="../orcamento/editar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn">Editar Orçamento</a>
                    <a href="../orcamento/historico.php?projeto_id=<?php echo $projeto_id; ?>" class="btn">Histórico de Alterações</a>
                </div>
            </div>
        </div>
        
        <div class="back-link">
            <a href="listar.php">&larr; Voltar para a lista de projetos</a>
        </div>
    </div>
    
    <?php include '../includes/footer.php'; ?>
</body>
</html>
