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
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalhes do Projeto - Gestão de Eventos</title>
    <link rel="stylesheet" href="../assets/css/style.css">
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
                        <div class="card-title">Orçamento Total</div>
                        <div class="card-value"><?php echo number_format($estatisticas['orcamento_total'], 2, ',', '.'); ?> €</div>
                    </div>
                    
                    <div class="card">
                        <div class="card-title">Total de Despesas</div>
                        <div class="card-value"><?php echo number_format($estatisticas['soma_despesas'], 2, ',', '.'); ?> €</div>
                    </div>
                    
                    <div class="card">
                        <div class="card-title">Saldo</div>
                        <?php $saldo = $estatisticas['orcamento_total'] - $estatisticas['soma_despesas']; ?>
                        <div class="card-value <?php echo $saldo >= 0 ? 'positivo' : 'negativo'; ?>">
                            <?php echo number_format($saldo, 2, ',', '.'); ?> €
                        </div>
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .card {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .card-title {
            font-size: 16px;
            color: #6c757d;
            margin-bottom: 10px;
        }
        
        .card-value {
            font-size: 24px;
            font-weight: bold;
        }
        
        .positivo { color: green; }
        .negativo { color: red; }
        
        .stats {
            margin-bottom: 30px;
        }
        
        .acoes-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
        }
        
        .acoes-buttons .btn {
            display: block;
            text-align: center;
        }
        
        .back-link {
            margin-top: 20px;
        }
    </style>
    
    <?php include '../includes/footer.php'; ?>
</body>
</html>
