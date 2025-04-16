<?php
require_once '../config/db.php';
require_once '../includes/functions.php';
verificarLogin();

$projeto_id = isset($_GET['projeto_id']) ? intval($_GET['projeto_id']) : 0;

// Verificar se o projeto existe
$stmt = $pdo->prepare("SELECT id, nome FROM projetos WHERE id = :id");
$stmt->bindParam(':id', $projeto_id, PDO::PARAM_INT);
$stmt->execute();
$projeto = $stmt->fetch();

if (!$projeto) {
    header('Location: ../projetos/listar.php');
    exit;
}

// Parâmetros de filtro e paginação
$pagina = isset($_GET['pagina']) ? intval($_GET['pagina']) : 1;
$registros_por_pagina = 20;
$offset = ($pagina - 1) * $registros_por_pagina;

$categoria_id = isset($_GET['categoria_id']) ? intval($_GET['categoria_id']) : null;
$fornecedor = isset($_GET['fornecedor']) ? trim($_GET['fornecedor']) : '';
$data_inicio = isset($_GET['data_inicio']) ? trim($_GET['data_inicio']) : '';
$data_fim = isset($_GET['data_fim']) ? trim($_GET['data_fim']) : '';
$tipo = isset($_GET['tipo']) ? trim($_GET['tipo']) : '';

// Construir a consulta base
$sql_base = "
    SELECT d.id, d.tipo, d.valor, d.descricao, d.data_despesa, d.data_registro, 
           d.anexo_path, c.numero_conta, c.descricao as categoria_descricao,
           f.nome as fornecedor, u.email as registrado_por
    FROM despesas d
    JOIN categorias c ON d.categoria_id = c.id
    JOIN fornecedores f ON d.fornecedor_id = f.id
    JOIN usuarios u ON d.registrado_por = u.id
    WHERE d.projeto_id = :projeto_id
";

$sql_count = "
    SELECT COUNT(*) as total
    FROM despesas d
    JOIN categorias c ON d.categoria_id = c.id
    JOIN fornecedores f ON d.fornecedor_id = f.id
    WHERE d.projeto_id = :projeto_id
";

$params = [':projeto_id' => $projeto_id];

// Adicionar filtros, se necessário
if (!empty($categoria_id)) {
    $sql_base .= " AND d.categoria_id = :categoria_id";
    $sql_count .= " AND d.categoria_id = :categoria_id";
    $params[':categoria_id'] = $categoria_id;
}

if (!empty($fornecedor)) {
    $sql_base .= " AND f.nome LIKE :fornecedor";
    $sql_count .= " AND f.nome LIKE :fornecedor";
    $params[':fornecedor'] = "%$fornecedor%";
}

if (!empty($data_inicio)) {
    $sql_base .= " AND d.data_despesa >= :data_inicio";
    $sql_count .= " AND d.data_despesa >= :data_inicio";
    $params[':data_inicio'] = $data_inicio;
}

if (!empty($data_fim)) {
    $sql_base .= " AND d.data_despesa <= :data_fim";
    $sql_count .= " AND d.data_despesa <= :data_fim";
    $params[':data_fim'] = $data_fim;
}

if (!empty($tipo)) {
    $sql_base .= " AND d.tipo = :tipo";
    $sql_count .= " AND d.tipo = :tipo";
    $params[':tipo'] = $tipo;
}

// Adicionar ordenação e paginação
$sql_base .= " ORDER BY d.data_despesa DESC LIMIT :offset, :limit";

// Obter total de registros para paginação
$stmt = $pdo->prepare($sql_count);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$result = $stmt->fetch();
$total_registros = $result['total'];
$total_paginas = ceil($total_registros / $registros_por_pagina);

// Obter despesas
$stmt = $pdo->prepare($sql_base);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':limit', $registros_por_pagina, PDO::PARAM_INT);
$stmt->execute();
$despesas = $stmt->fetchAll();

// Obter categorias para o filtro
$stmt = $pdo->prepare("SELECT id, numero_conta, descricao FROM categorias WHERE projeto_id = :projeto_id ORDER BY numero_conta");
$stmt->bindParam(':projeto_id', $projeto_id, PDO::PARAM_INT);
$stmt->execute();
$categorias = $stmt->fetchAll();

// Calcular total das despesas (com filtros)
$sql_total = "
    SELECT COALESCE(SUM(d.valor), 0) as total
    FROM despesas d
    JOIN categorias c ON d.categoria_id = c.id
    JOIN fornecedores f ON d.fornecedor_id = f.id
    WHERE d.projeto_id = :projeto_id
";

// Adicionar os mesmos filtros à consulta de total
if (!empty($categoria_id)) {
    $sql_total .= " AND d.categoria_id = :categoria_id";
}
if (!empty($fornecedor)) {
    $sql_total .= " AND f.nome LIKE :fornecedor";
}
if (!empty($data_inicio)) {
    $sql_total .= " AND d.data_despesa >= :data_inicio";
}
if (!empty($data_fim)) {
    $sql_total .= " AND d.data_despesa <= :data_fim";
}
if (!empty($tipo)) {
    $sql_total .= " AND d.tipo = :tipo";
}

$stmt = $pdo->prepare($sql_total);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$resultado_total = $stmt->fetch();
$total_despesas = $resultado_total['total'];
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Listar Despesas - Gestão de Eventos</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container">
        <h1>Listar Despesas</h1>
        <h2>Projeto: <?php echo htmlspecialchars($projeto['nome']); ?></h2>
        
        <div class="actions">
            <a href="../despesas/registrar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-primary">Registrar Nova Despesa</a>
            <a href="../relatorios/gerar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary">Ver Relatório</a>
            <a href="../projetos/ver.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary">Detalhes do Projeto</a>
        </div>
        
        <div class="filter-section">
            <h3>Filtros</h3>
            <form method="get" action="">
                <input type="hidden" name="projeto_id" value="<?php echo $projeto_id; ?>">
                
                <div class="filter-grid">
                    <div class="form-group">
                        <label for="categoria_id">Categoria:</label>
                        <select id="categoria_id" name="categoria_id">
                            <option value="">Todas as categorias</option>
                            <?php foreach ($categorias as $categoria): ?>
                                <option value="<?php echo $categoria['id']; ?>" <?php echo $categoria_id == $categoria['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($categoria['numero_conta'] . ' - ' . $categoria['descricao']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="fornecedor">Fornecedor:</label>
                        <input type="text" id="fornecedor" name="fornecedor" value="<?php echo htmlspecialchars($fornecedor); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="tipo">Tipo:</label>
                        <select id="tipo" name="tipo">
                            <option value="">Todos os tipos</option>
                            <option value="serviço" <?php echo $tipo === 'serviço' ? 'selected' : ''; ?>>Serviço</option>
                            <option value="bem" <?php echo $tipo === 'bem' ? 'selected' : ''; ?>>Bem</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="data_inicio">Data Início:</label>
                        <input type="date" id="data_inicio" name="data_inicio" value="<?php echo $data_inicio; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="data_fim">Data Fim:</label>
                        <input type="date" id="data_fim" name="data_fim" value="<?php echo $data_fim; ?>">
                    </div>
                    
                    <div class="form-group filter-actions">
                        <button type="submit" class="btn">Filtrar</button>
                        <a href="?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary">Limpar Filtros</a>
                    </div>
                </div>
            </form>
        </div>
        
        <div class="resumo">
            <h3>Resumo</h3>
            <p>Total de despesas: <strong><?php echo number_format($total_registros, 0, ',', '.'); ?></strong></p>
            <p>Valor total: <strong><?php echo number_format($total_despesas, 2, ',', '.'); ?> €</strong></p>
        </div>
        
        <?php if (count($despesas) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Categoria</th>
                        <th>Fornecedor</th>
                        <th>Tipo</th>
                        <th>Descrição</th>
                        <th>Valor</th>
                        <th>Anexo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($despesas as $despesa): ?>
                        <tr>
                            <td><?php echo date('d/m/Y', strtotime($despesa['data_despesa'])); ?></td>
                            <td><?php echo htmlspecialchars($despesa['numero_conta'] . ' - ' . $despesa['categoria_descricao']); ?></td>
                            <td><?php echo htmlspecialchars($despesa['fornecedor']); ?></td>
                            <td><?php echo ucfirst($despesa['tipo']); ?></td>
                            <td><?php echo htmlspecialchars($despesa['descricao']); ?></td>
                            <td><?php echo number_format($despesa['valor'], 2, ',', '.'); ?> €</td>
                            <td>
                                <?php if ($despesa['anexo_path']): ?>
                                    <a href="../assets/arquivos/<?php echo htmlspecialchars($despesa['anexo_path']); ?>" target="_blank">Ver</a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <!-- Paginação -->
            <?php if ($total_paginas > 1): ?>
                <div class="paginacao">
                    <?php if ($pagina > 1): ?>
                        <a href="?projeto_id=<?php echo $projeto_id; ?>&pagina=1<?php echo !empty($categoria_id) ? '&categoria_id='.$categoria_id : ''; ?><?php echo !empty($fornecedor) ? '&fornecedor='.urlencode($fornecedor) : ''; ?><?php echo !empty($tipo) ? '&tipo='.$tipo : ''; ?><?php echo !empty($data_inicio) ? '&data_inicio='.$data_inicio : ''; ?><?php echo !empty($data_fim) ? '&data_fim='.$data_fim : ''; ?>" class="pagina-link">Primeira</a>
                        <a href="?projeto_id=<?php echo $projeto_id; ?>&pagina=<?php echo $pagina-1; ?><?php echo !empty($categoria_id) ? '&categoria_id='.$categoria_id : ''; ?><?php echo !empty($fornecedor) ? '&fornecedor='.urlencode($fornecedor) : ''; ?><?php echo !empty($tipo) ? '&tipo='.$tipo : ''; ?><?php echo !empty($data_inicio) ? '&data_inicio='.$data_inicio : ''; ?><?php echo !empty($data_fim) ? '&data_fim='.$data_fim : ''; ?>" class="pagina-link">Anterior</a>
                    <?php endif; ?>
                    
                    <span class="pagina-info">Página <?php echo $pagina; ?> de <?php echo $total_paginas; ?></span>
                    
                    <?php if ($pagina < $total_paginas): ?>
                        <a href="?projeto_id=<?php echo $projeto_id; ?>&pagina=<?php echo $pagina+1; ?><?php echo !empty($categoria_id) ? '&categoria_id='.$categoria_id : ''; ?><?php echo !empty($fornecedor) ? '&fornecedor='.urlencode($fornecedor) : ''; ?><?php echo !empty($tipo) ? '&tipo='.$tipo : ''; ?><?php echo !empty($data_inicio) ? '&data_inicio='.$data_inicio : ''; ?><?php echo !empty($data_fim) ? '&data_fim='.$data_fim : ''; ?>" class="pagina-link">Próxima</a>
                        <a href="?projeto_id=<?php echo $projeto_id; ?>&pagina=<?php echo $total_paginas; ?><?php echo !empty($categoria_id) ? '&categoria_id='.$categoria_id : ''; ?><?php echo !empty($fornecedor) ? '&fornecedor='.urlencode($fornecedor) : ''; ?><?php echo !empty($tipo) ? '&tipo='.$tipo : ''; ?><?php echo !empty($data_inicio) ? '&data_inicio='.$data_inicio : ''; ?><?php echo !empty($data_fim) ? '&data_fim='.$data_fim : ''; ?>" class="pagina-link">Última</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
        <?php else: ?>
            <p>Nenhuma despesa encontrada. <a href="../despesas/registrar.php?projeto_id=<?php echo $projeto_id; ?>">Registre sua primeira despesa</a>.</p>
        <?php endif; ?>
    </div>
    
    <style>
        .filter-section {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .filter-actions {
            display: flex;
            align-items: flex-end;
            gap: 10px;
        }
        
        .resumo {
            margin-bottom: 20px;
            padding: 15px;
            background-color: #e9ecef;
            border-left: 4px solid #0056b3;
            border-radius: 4px;
        }
        
        .paginacao {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 20px;
            gap: 10px;
        }
        
        .pagina-link {
            padding: 5px 10px;
            background-color: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-decoration: none;
            color: #0056b3;
        }
        
        .pagina-link:hover {
            background-color: #e9ecef;
        }
        
        .pagina-info {
            margin: 0 10px;
        }
    </style>
    
    <?php include '../includes/footer.php'; ?>
</body>
</html>
