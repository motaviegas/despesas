<?php
require_once '../config/db.php';
require_once '../includes/functions.php';
verificarLogin();

$projeto_id = isset($_GET['projeto_id']) ? intval($_GET['projeto_id']) : 0;
$despesa_id = isset($_GET['despesa_id']) ? intval($_GET['despesa_id']) : 0;

// Verificar se o projeto existe
$stmt = $pdo->prepare("SELECT id, nome FROM projetos WHERE id = :id");
$stmt->bindParam(':id', $projeto_id, PDO::PARAM_INT);
$stmt->execute();
$projeto = $stmt->fetch();

if (!$projeto) {
    header('Location: ../projetos/listar.php');
    exit;
}

// Se tiver ID de despesa, obter histórico da despesa específica
if ($despesa_id > 0) {
    // Verificar se a despesa existe
    $stmt = $pdo->prepare("
        SELECT d.*, c.numero_conta, c.descricao as categoria_descricao, f.nome as fornecedor
        FROM despesas d
        JOIN categorias c ON d.categoria_id = c.id
        JOIN fornecedores f ON d.fornecedor_id = f.id
        WHERE d.id = :id AND d.projeto_id = :projeto_id
    ");
    $stmt->bindParam(':id', $despesa_id, PDO::PARAM_INT);
    $stmt->bindParam(':projeto_id', $projeto_id, PDO::PARAM_INT);
    $stmt->execute();
    $despesa = $stmt->fetch();

    if (!$despesa) {
        header("Location: ../despesas/listar.php?projeto_id=$projeto_id");
        exit;
    }

    // Obter histórico de edições desta despesa
    $historico_edicoes = obterHistoricoEdicoesDespesa($pdo, $despesa_id);
    $titulo_pagina = "Histórico da Despesa";
} else {
    // Obter histórico de exclusões do projeto
    $historico_exclusoes = obterHistoricoExclusoes($pdo, $projeto_id, 'despesa');
    $titulo_pagina = "Histórico de Despesas Excluídas";
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo_pagina; ?> - Gestão de Eventos</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container">
        <h1><?php echo $titulo_pagina; ?></h1>
        <h2>Projeto: <?php echo htmlspecialchars($projeto['nome']); ?></h2>
        
        <div class="actions">
            <a href="../despesas/listar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary">Voltar para Despesas</a>
            <a href="../relatorios/gerar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary">Ver Relatório</a>
        </div>
        
        <?php if (isset($despesa)): ?>
            <div class="despesa-info">
                <h3>Informações da Despesa</h3>
                <p><strong>Categoria:</strong> <?php echo htmlspecialchars($despesa['numero_conta'] . ' - ' . $despesa['categoria_descricao']); ?></p>
                <p><strong>Fornecedor:</strong> <?php echo htmlspecialchars($despesa['fornecedor']); ?></p>
                <p><strong>Tipo:</strong> <?php echo ucfirst($despesa['tipo']); ?></p>
                <p><strong>Valor:</strong> <?php echo number_format($despesa['valor'], 2, ',', '.'); ?> €</p>
                <p><strong>Data:</strong> <?php echo date('d/m/Y', strtotime($despesa['data_despesa'])); ?></p>
                <p><strong>Descrição:</strong> <?php echo htmlspecialchars($despesa['descricao']); ?></p>
                <?php if (!empty($despesa['anexo_path'])): ?>
                    <p><strong>Anexo:</strong> <a href="/mnt/Dados/facturas/<?php echo htmlspecialchars($despesa['anexo_path']); ?>" target="_blank">Ver anexo</a></p>
                <?php endif; ?>
            </div>
            
            <?php if (count($historico_edicoes) > 0): ?>
                <h3>Histórico de Edições</h3>
                <table class="historico-tabela">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Usuário</th>
                            <th>Campo</th>
                            <th>Valor Anterior</th>
                            <th>Valor Novo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historico_edicoes as $index => $edicao): 
                            $classe = $index % 2 == 0 ? 'even-row' : 'odd-row';
                        ?>
                            <?php if ($edicao['categoria_id_anterior'] != $edicao['categoria_id_novo']): ?>
                                <tr class="<?php echo $classe; ?>">
                                    <td rowspan="5"><?php echo date('d/m/Y H:i', strtotime($edicao['data_edicao'])); ?></td>
                                    <td rowspan="5"><?php echo htmlspecialchars($edicao['usuario_email']); ?></td>
                                    <td>Categoria</td>
                                    <td><?php echo htmlspecialchars($edicao['numero_conta_anterior'] . ' - ' . $edicao['descricao_categoria_anterior']); ?></td>
                                    <td><?php echo htmlspecialchars($edicao['numero_conta_novo'] . ' - ' . $edicao['descricao_categoria_novo']); ?></td>
                                </tr>
                                <tr class="<?php echo $classe; ?>">
                                    <td>Fornecedor</td>
                                    <td><?php echo htmlspecialchars($edicao['fornecedor_anterior']); ?></td>
                                    <td><?php echo htmlspecialchars($edicao['fornecedor_novo']); ?></td>
                                </tr>
                                <tr class="<?php echo $classe; ?>">
                                    <td>Tipo</td>
                                    <td><?php echo ucfirst($edicao['tipo_anterior']); ?></td>
                                    <td><?php echo ucfirst($edicao['tipo_novo']); ?></td>
                                </tr>
                                <tr class="<?php echo $classe; ?>">
                                    <td>Valor</td>
                                    <td><?php echo number_format($edicao['valor_anterior'], 2, ',', '.'); ?> €</td>
                                    <td><?php echo number_format($edicao['valor_novo'], 2, ',', '.'); ?> €</td>
                                </tr>
                                <tr class="<?php echo $classe; ?>">
                                    <td>Data</td>
                                    <td><?php echo date('d/m/Y', strtotime($edicao['data_despesa_anterior'])); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($edicao['data_despesa_nova'])); ?></td>
                                </tr>
                            <?php else: ?>
                                <tr class="<?php echo $classe; ?>">
                                    <td><?php echo date('d/m/Y H:i', strtotime($edicao['data_edicao'])); ?></td>
                                    <td><?php echo htmlspecialchars($edicao['usuario_email']); ?></td>
                                    <td colspan="3">
                                        <?php if ($edicao['fornecedor_anterior'] != $edicao['fornecedor_novo']): ?>
                                            <strong>Fornecedor:</strong> <?php echo htmlspecialchars($edicao['fornecedor_anterior']); ?> → <?php echo htmlspecialchars($edicao['fornecedor_novo']); ?><br>
                                        <?php endif; ?>
                                        
                                        <?php if ($edicao['tipo_anterior'] != $edicao['tipo_novo']): ?>
                                            <strong>Tipo:</strong> <?php echo ucfirst($edicao['tipo_anterior']); ?> → <?php echo ucfirst($edicao['tipo_novo']); ?><br>
                                        <?php endif; ?>
                                        
                                        <?php if ($edicao['valor_anterior'] != $edicao['valor_novo']): ?>
                                            <strong>Valor:</strong> <?php echo number_format($edicao['valor_anterior'], 2, ',', '.'); ?> € → <?php echo number_format($edicao['valor_novo'], 2, ',', '.'); ?> €<br>
                                        <?php endif; ?>
                                        
                                        <?php if ($edicao['descricao_anterior'] != $edicao['descricao_nova']): ?>
                                            <strong>Descrição:</strong> <?php echo htmlspecialchars($edicao['descricao_anterior']); ?> → <?php echo htmlspecialchars($edicao['descricao_nova']); ?><br>
                                        <?php endif; ?>
                                        
                                        <?php if ($edicao['data_despesa_anterior'] != $edicao['data_despesa_nova']): ?>
                                            <strong>Data:</strong> <?php echo date('d/m/Y', strtotime($edicao['data_despesa_anterior'])); ?> → <?php echo date('d/m/Y', strtotime($edicao['data_despesa_nova'])); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>Esta despesa não possui histórico de edições.</p>
            <?php endif; ?>
            
        <?php else: ?>
            <!-- Histórico de exclusões -->
            <?php if (count($historico_exclusoes) > 0): ?>
                <h3>Despesas Excluídas</h3>
                <table class="historico-tabela">
                    <thead>
                        <tr>
                            <th>Data da Exclusão</th>
                            <th>Excluído Por</th>
                            <th>Categoria</th>
                            <th>Fornecedor</th>
                            <th>Valor</th>
                            <th>Data da Despesa</th>
                            <th>Motivo da Exclusão</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($historico_exclusoes as $index => $exclusao): 
                            $classe = $index % 2 == 0 ? 'even-row' : 'odd-row';
                        ?>
                            <tr class="<?php echo $classe; ?>">
                                <td><?php echo date('d/m/Y H:i', strtotime($exclusao['data_exclusao'])); ?></td>
                                <td><?php echo htmlspecialchars($exclusao['usuario_email']); ?></td>
                                <td><?php echo htmlspecialchars($exclusao['numero_conta'] . ' - ' . $exclusao['categoria_descricao']); ?></td>
                                <td><?php echo htmlspecialchars($exclusao['fornecedor_nome']); ?></td>
                                <td><?php echo number_format($exclusao['valor'], 2, ',', '.'); ?> €</td>
                                <td><?php echo date('d/m/Y', strtotime($exclusao['data_despesa'])); ?></td>
                                <td><?php echo htmlspecialchars($exclusao['motivo']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>Não há histórico de despesas excluídas para este projeto.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <style>
        .despesa-info {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .despesa-info p {
            margin-bottom: 8px;
        }
        .historico-tabela {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .historico-tabela th {
            background-color: #f0f0f0;
            text-align: left;
            padding: 10px;
            border-bottom: 2px solid #ddd;
        }
        .historico-tabela td {
            padding: 8px 10px;
            border-bottom: 1px solid #eee;
        }
        .even-row {
            background-color: #f9f9f9;
        }
        .odd-row {
            background-color: #ffffff;
        }
        .historico-tabela tr:hover {
            background-color: #f5f5f5;
        }
    </style>
    
    <?php include '../includes/footer.php'; ?>
</body>
</html>
