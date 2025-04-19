<?php
require_once '../config/db.php';
require_once '../includes/functions.php';
verificarLogin();

$projeto_id = isset($_GET['projeto_id']) ? intval($_GET['projeto_id']) : 0;
$mensagem = isset($_GET['mensagem']) ? $_GET['mensagem'] : '';

// Verificar se o projeto existe
$stmt = $pdo->prepare("SELECT id, nome FROM projetos WHERE id = :id");
$stmt->bindParam(':id', $projeto_id, PDO::PARAM_INT);
$stmt->execute();
$projeto = $stmt->fetch();

if (!$projeto) {
    header('Location: ../dashboard.php');
    exit;
}

// Inicializar categorias expandidas na sessão se necessário
if (!isset($_SESSION['categorias_expandidas'])) {
    $_SESSION['categorias_expandidas'] = [];
}
if (!isset($_SESSION['categorias_expandidas'][$projeto_id])) {
    $_SESSION['categorias_expandidas'][$projeto_id] = [];
}

// Processar ação de toggle para expandir/colapsar categoria
if (isset($_GET['toggle'])) {
    $toggle_id = intval($_GET['toggle']);
    
    if (in_array($toggle_id, $_SESSION['categorias_expandidas'][$projeto_id])) {
        // Remover da lista de expandidos
        $_SESSION['categorias_expandidas'][$projeto_id] = array_diff(
            $_SESSION['categorias_expandidas'][$projeto_id], 
            [$toggle_id]
        );
    } else {
        // Adicionar à lista de expandidos
        $_SESSION['categorias_expandidas'][$projeto_id][] = $toggle_id;
    }
    
    // Redirecionar para limpar a URL
    header("Location: gerar.php?projeto_id=$projeto_id");
    exit;
}

// Obter categorias e calcular totais - Usando a função corrigida
$categorias = obterCategoriasDespesas($pdo, $projeto_id);
$categorias_com_totais = calcularTotaisCategoriasDespesas($categorias);

// Calcular a porcentagem global de execução do orçamento
$orcamento_global = isset($categorias_com_totais[0]['budget']) ? $categorias_com_totais[0]['budget'] : 0;
$despesas_global = isset($categorias_com_totais[0]['total_despesas']) ? $categorias_com_totais[0]['total_despesas'] : 0;
$percentagem_execucao = ($orcamento_global > 0) ? 
    ($despesas_global / $orcamento_global) * 100 : 0;

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

// Organizar categorias por pai para facilitar a renderização
$categorias_por_pai = [];
foreach ($categorias_com_totais as $categoria) {
    if (isset($categoria['categoria_pai_id']) && $categoria['categoria_pai_id'] !== null) {
        if (!isset($categorias_por_pai[$categoria['categoria_pai_id']])) {
            $categorias_por_pai[$categoria['categoria_pai_id']] = [];
        }
        $categorias_por_pai[$categoria['categoria_pai_id']][] = $categoria['id'];
    }
}

// Verificar se estamos visualizando despesas de uma categoria
$ver_despesas_id = isset($_GET['ver_despesas']) ? intval($_GET['ver_despesas']) : 0;
$despesas_categoria = [];
if ($ver_despesas_id > 0) {
    $despesas_categoria = obterDespesasPorCategoria($pdo, $ver_despesas_id);
    
    // Obter informações da categoria para exibir no título
    $stmt = $pdo->prepare("SELECT numero_conta, descricao, budget FROM categorias WHERE id = :id");
    $stmt->bindParam(':id', $ver_despesas_id, PDO::PARAM_INT);
    $stmt->execute();
    $categoria_despesas = $stmt->fetch();
    
    // Calcular total das despesas para esta categoria
    $total_despesas_categoria = 0;
    foreach ($despesas_categoria as $despesa) {
        $total_despesas_categoria += $despesa['valor'];
    }
    
    // Calcular percentagem de execução desta categoria
    $percentagem_categoria = ($categoria_despesas['budget'] > 0) ? 
        ($total_despesas_categoria / $categoria_despesas['budget']) * 100 : 0;
        
    // Determinar a classe da barra de progresso para esta categoria
    if ($percentagem_categoria <= 50) {
        $barra_cat_classe = "verde";
        $barra_cat_largura = $percentagem_categoria * 2;
    } elseif ($percentagem_categoria <= 75) {
        $barra_cat_classe = "amarelo";
        $barra_cat_largura = $percentagem_categoria;
    } elseif ($percentagem_categoria <= 95) {
        $barra_cat_classe = "laranja";
        $barra_cat_largura = $percentagem_categoria;
    } else {
        if ($percentagem_categoria > 100) {
            $barra_cat_classe = "vermelho-intenso";
        } else {
            $barra_cat_classe = "vermelho";
        }
        $barra_cat_largura = min($percentagem_categoria, 100);
    }
}

// Exportar para CSV se solicitado
if (isset($_GET['exportar']) && $_GET['exportar'] == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="relatorio_' . $projeto_id . '_' . date('Ymd') . '.csv"');
    
    gerarCSVRelatorio($pdo, $projeto_id, $categorias_com_totais);
    exit;
}

// Exportar para Excel se solicitado
if (isset($_GET['exportar']) && $_GET['exportar'] == 'excel') {
    // Implementar exportação para Excel aqui
    header('Location: gerar.php?projeto_id=' . $projeto_id . '&mensagem=Exportação para Excel será implementada em breve.');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório de Orçamento - Gestão de Eventos</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        .relatorio-header {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .resumo-orcamento {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin: 25px 0;
        }
        
        .resumo-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .resumo-titulo {
            font-size: 16px;
            color: #6c757d;
            margin-bottom: 10px;
        }
        
        .resumo-valor {
            font-size: 24px;
            font-weight: bold;
        }
        
        .positivo { color: #28a745; }
        .negativo { color: #dc3545; }
        .ultrapassado { 
            color: #dc3545; 
            font-weight: 900;
            font-size: 32px;
            text-shadow: 0px 0px 2px rgba(0,0,0,0.3);
        }
        
        .detalhes-categoria {
            background-color: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .despesa-item {
            border-bottom: 1px solid #f0f0f0;
            transition: background-color 0.2s;
        }
        
        .despesa-item:hover {
            background-color: #f8f9fa;
        }
        
        .despesa-item:last-child {
            border-bottom: none;
        }
        
        .anexo-link {
            display: inline-flex;
            align-items: center;
            color: #2062b7;
            font-size: 14px;
            margin-right: 10px;
            text-decoration: none;
        }
        
        .anexo-link i {
            margin-right: 5px;
        }
        
        .anexo-link:hover {
            text-decoration: underline;
        }
        
        .botoes-fixos {
            position: fixed;
            bottom: 30px;
            right: 30px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .botao-flutuante {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: #2062b7;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
            cursor: pointer;
            transition: all 0.3s;
            font-size: 24px;
            text-decoration: none;
        }
        
        .botao-flutuante:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 15px rgba(0,0,0,0.25);
        }
        
        .botao-flutuante.secundario {
            background-color: #6c757d;
            width: 50px;
            height: 50px;
            font-size: 20px;
        }
        
        .subtitulo-secao {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .relatorio-acoes {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        /* Estilos específicos para a tabela do relatório */
        .relatorio {
            width: 100%;
            border-collapse: collapse;
            box-shadow: 0 0 15px rgba(0,0,0,0.05);
            margin-bottom: 30px;
            background-color: white;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .relatorio th,
        .relatorio td {
            padding: 12px 15px;
            text-align: left;
        }
        
        .relatorio th {
            background-color: #2062b7;
            color: white;
            font-weight: 500;
            text-transform: uppercase;
            font-size: 13px;
            letter-spacing: 0.5px;
        }
        
        .relatorio td:nth-child(3),
        .relatorio td:nth-child(4),
        .relatorio td:nth-child(5) {
            text-align: right;
            font-family: 'Consolas', monospace;
        }
        
        .relatorio tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        
        .relatorio tr:hover {
            background-color: #f0f7ff;
        }
        
        .toggle-icon {
            cursor: pointer;
            display: inline-block;
            width: 24px;
            height: 24px;
            line-height: 24px;
            text-align: center;
            background-color: #e9ecef;
            color: #2062b7;
            border-radius: 50%;
            margin-right: 5px;
            transition: all 0.2s;
        }
        
        .toggle-icon:hover {
            background-color: #2062b7;
            color: white;
        }
        
        .nivel-1 {
            font-weight: bold;
        }
        
        .nivel-2 {
            padding-left: 20px;
        }
        
        .nivel-3 {
            padding-left: 40px;
        }
        
        .nivel-4 {
            padding-left: 60px;
        }
        
        .nivel-5 {
            padding-left: 80px;
        }
        
        .subcategorias-row {
            background-color: #f8f9fa;
        }
        
        .subcategoria-container {
            padding: 0 20px;
        }
        
        .ver-despesas {
            display: inline-block;
            margin-left: 10px;
            font-size: 13px;
            color: #2062b7;
            text-decoration: none;
        }
        
        .ver-despesas:hover {
            text-decoration: underline;
        }
        
        /* Indicadores para as categorias */
        .categoria-indicador {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 6px;
        }
        
        .categoria-indicador.verde { background-color: #28a745; }
        .categoria-indicador.amarelo { background-color: #ffc107; }
        .categoria-indicador.laranja { background-color: #fd7e14; }
        .categoria-indicador.vermelho { background-color: #dc3545; }
        .categoria-indicador.vermelho-intenso { 
            background-color: #dc3545;
            box-shadow: 0 0 5px #dc3545;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container">
        <div class="relatorio-header">
            <h1>Relatório de Orçamento</h1>
            <h2><?php echo htmlspecialchars($projeto['nome']); ?></h2>
            
            <?php if (!empty($mensagem)): ?>
                <div class="alert alert-info"><?php echo $mensagem; ?></div>
            <?php endif; ?>
            
            <?php if (isset($categorias_com_totais[0])): // Se temos o total global ?>
                <div class="resumo-orcamento">
                    <div class="resumo-card">
                        <div class="resumo-titulo">Total de Despesas</div>
                        <div class="resumo-valor"><?php echo number_format($categorias_com_totais[0]['total_despesas'], 2, ',', '.'); ?> €</div>
                    </div>
                    
                    <div class="resumo-card">
                        <div class="resumo-titulo">Orçamento Remanescente</div>
                        <div class="resumo-valor <?php echo $categorias_com_totais[0]['delta'] >= 0 ? 'positivo' : 'negativo ' . $saldo_classe; ?>">
                            <?php echo number_format($categorias_com_totais[0]['delta'], 2, ',', '.'); ?> €
                        </div>
                        <div class="progress-container">
                            <div class="progress-bar <?php echo $barra_classe; ?>" style="width: <?php echo $barra_largura; ?>%"></div>
                            <span class="progress-text"><?php echo number_format($percentagem_execucao, 1, ',', '.'); ?>% Executado</span>
                        </div>
                    </div>
                    
                    <div class="resumo-card">
                        <div class="resumo-titulo">Orçamento Total</div>
                        <div class="resumo-valor"><?php echo number_format($categorias_com_totais[0]['budget'], 2, ',', '.'); ?> €</div>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="relatorio-acoes">
                <a href="?projeto_id=<?php echo $projeto_id; ?>&exportar=csv" class="btn">
                    <i class="fa fa-file-text-o"></i> Exportar CSV
                </a>
                <a href="?projeto_id=<?php echo $projeto_id; ?>&exportar=excel" class="btn">
                    <i class="fa fa-file-excel-o"></i> Exportar Excel
                </a>
                <a href="../orcamento/editar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-sm">
                    <i class="fa fa-pencil"></i> Editar Orçamento
                </a>
                <a href="../despesas/listar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-sm">
                    <i class="fa fa-list"></i> Listar Despesas
                </a>
            </div>
        </div>
        
        <?php if ($ver_despesas_id > 0 && isset($categoria_despesas)): ?>
            <div class="detalhes-categoria">
                <div class="subtitulo-secao">
                    <h3>Despesas: <?php echo htmlspecialchars($categoria_despesas['numero_conta'] . ' - ' . $categoria_despesas['descricao']); ?></h3>
                    <a href="?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-sm">
                        <i class="fa fa-arrow-left"></i> Voltar ao Relatório
                    </a>
                </div>
                
                <div class="resumo-orcamento">
                    <div class="resumo-card">
                        <div class="resumo-titulo">Total Despesas</div>
                        <div class="resumo-valor"><?php echo number_format($total_despesas_categoria, 2, ',', '.'); ?> €</div>
                    </div>
                    
                    <div class="resumo-card">
                        <div class="resumo-titulo">Orçamento da Categoria</div>
                        <div class="resumo-valor"><?php echo number_format($categoria_despesas['budget'], 2, ',', '.'); ?> €</div>
                    </div>
                    
                    <div class="resumo-card">
                        <div class="resumo-titulo">Execução do Orçamento</div>
                        <div class="progress-container">
                            <div class="progress-bar <?php echo $barra_cat_classe; ?>" style="width: <?php echo $barra_cat_largura; ?>%"></div>
                            <span class="progress-text"><?php echo number_format($percentagem_categoria, 1, ',', '.'); ?>%</span>
                        </div>
                    </div>
                </div>
                
                <?php if (count($despesas_categoria) > 0): ?>
                    <table class="despesas-tabela">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Tipo</th>
                                <th>Fornecedor</th>
                                <th>Descrição</th>
                                <th>Valor</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($despesas_categoria as $index => $despesa): 
                                $classe = $index % 2 == 0 ? 'even-row' : 'odd-row';
                            ?>
                            <tr class="despesa-item <?php echo $classe; ?>">
                                <td><?php echo date('d/m/Y', strtotime($despesa['data_despesa'])); ?></td>
                                <td><?php echo ucfirst($despesa['tipo']); ?></td>
                                <td><?php echo htmlspecialchars($despesa['fornecedor']); ?></td>
                                <td><?php echo htmlspecialchars($despesa['descricao']); ?></td>
                                <td><?php echo number_format($despesa['valor'], 2, ',', '.'); ?> €</td>
                                <td>
                                    <?php if (!empty($despesa['anexo_path'])): ?>
                                        <a href="../assets/arquivos/<?php echo $despesa['anexo_path']; ?>" target="_blank" class="anexo-link" title="Ver fatura">
                                            <i class="fa fa-file-pdf-o"></i> Fatura
                                        </a>
                                    <?php else: ?>
                                        <span class="no-anexo">-</span>
                                    <?php endif; ?>
                                    
                                    <a href="../despesas/editar.php?projeto_id=<?php echo $projeto_id; ?>&despesa_id=<?php echo $despesa['id']; ?>" class="btn-acao editar" title="Editar despesa">
                                        <i class="fa fa-pencil"></i>
                                    </a>
                                    <a href="../despesas/excluir.php?projeto_id=<?php echo $projeto_id; ?>&despesa_id=<?php echo $despesa['id']; ?>" class="btn-acao excluir" title="Excluir despesa">
                                        <i class="fa fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="sem-despesas">Não há despesas registradas para esta categoria.</div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <table class="relatorio">
                <thead>
                    <tr>
                        <th>Nr de conta</th>
                        <th>DESCRIÇÃO DA RUBRICA</th>
                        <th>Tot Despesa</th>
                        <th>Delta Budget</th>
                        <th>Budget</th>
                    </tr>
                </thead>
                <tbody id="categorias-container">
                    <?php if (isset($categorias_com_totais[0])): // Total Global ?>
                        <tr class="categoria-0">
                            <td>TOTAL</td>
                            <td>TOTAL GLOBAL</td>
                            <td><?php echo number_format($categorias_com_totais[0]['total_despesas'], 2, ',', '.'); ?> €</td>
                            <td class="<?php echo $categorias_com_totais[0]['delta'] >= 0 ? 'positivo' : 'negativo'; ?>">
                                <?php echo number_format($categorias_com_totais[0]['delta'], 2, ',', '.'); ?> €
                            </td>
                            <td><?php echo number_format($categorias_com_totais[0]['budget'], 2, ',', '.'); ?> €</td>
                        </tr>
                    <?php endif; ?>
                    
                    <?php 
                    // Função para renderizar categorias de forma recursiva
                    function renderizarCategorias($categorias, $categorias_por_pai, $nivel, $categorias_expandidas, $projeto_id) {
                        foreach ($categorias as $categoria) {
                            if ($categoria['nivel'] != $nivel) continue;
                            if ($categoria['id'] == 0) continue; // Pular o total global
                            
                            $expandida = in_array($categoria['id'], $categorias_expandidas);
                            $tem_filhos = isset($categorias_por_pai[$categoria['id']]);
                            $classe_delta = $categoria['delta'] >= 0 ? 'positivo' : 'negativo';
                            $classe_nivel = "nivel-{$nivel}";
                            
                            // Calcular percentagem de execução para esta categoria
                            $percentagem_cat = ($categoria['budget'] > 0) ? 
                                ($categoria['total_despesas'] / $categoria['budget']) * 100 : 0;
                                
                            // Determinar a cor baseada na percentagem
                            if ($percentagem_cat <= 50) {
                                $cor_percentagem = "verde";
                            } elseif ($percentagem_cat <= 75) {
                                $cor_percentagem = "amarelo";
                            } elseif ($percentagem_cat <= 95) {
                                $cor_percentagem = "laranja";
                            } else {
                                $cor_percentagem = $percentagem_cat > 100 ? "vermelho-intenso" : "vermelho";
                            }
                            
                            echo "<tr class='categoria-row' id='categoria-{$categoria['id']}'>";
                            echo "<td>{$categoria['numero_conta']}</td>";
                            echo "<td class='{$classe_nivel}'>";
                            
                            if ($tem_filhos) {
                                $simbolo = $expandida ? '−' : '+';
                                echo "<span class='toggle-icon expandir' data-id='{$categoria['id']}'>{$simbolo}</span> ";
                            } else {
                                echo "<span class='toggle-icon'></span>";
                            }
                            
                            echo htmlspecialchars($categoria['descricao']);
                            
                            // Adicionar link "Ver despesas" para categorias de nível 3 ou superior ou categorias sem filhos
                            if ($nivel >= 3 || !$tem_filhos) {
                                echo " <a href='?projeto_id={$projeto_id}&ver_despesas={$categoria['id']}' class='ver-despesas'>
                                    <i class='fa fa-eye'></i> Ver despesas
                                </a>";
                            }
                            
                            echo "</td>";
                            
                            // Coluna Total Despesas com percentagem em tooltip e indicador visual
                            echo "<td title='{$percentagem_cat}% do orçamento'>";
                            echo "<span class='categoria-indicador {$cor_percentagem}'></span>";
                            echo number_format($categoria['total_despesas'], 2, ',', '.') . " €</td>";
                            
                            echo "<td class='{$classe_delta}'>" . number_format($categoria['delta'], 2, ',', '.') . " €</td>";
                            echo "<td>" . number_format($categoria['budget'], 2, ',', '.') . " €</td>";
                            echo "</tr>";
                            
                            // Criar linha para subcategorias (inicialmente oculta)
                            echo "<tr class='subcategorias-row subcategorias-{$categoria['id']} " . ($expandida ? '' : 'oculto') . "'>";
                            echo "<td colspan='5'><div class='subcategoria-container' id='subcategoria-container-{$categoria['id']}'>";
                            
                            // Se a categoria estiver expandida, renderizar seus filhos
                            if ($tem_filhos && $expandida) {
                                $filhos = array_filter($categorias, function($cat) use ($categoria) {
                                    return $cat['categoria_pai_id'] == $categoria['id'];
                                });
                                
                                renderizarCategorias($filhos, $categorias_por_pai, $nivel + 1, $categorias_expandidas, $projeto_id);
                            }
                            
                            echo "</div></td></tr>";
                        }
                    }
                    
                    // Obter categorias de nível 1
                    $categorias_nivel1 = array_filter($categorias_com_totais, function($cat) {
                        return isset($cat['nivel']) && $cat['nivel'] == 1;
                    });
                    
                    // Renderizar categorias começando pelo nível 1
                    renderizarCategorias(
                        $categorias_nivel1, 
                        $categorias_por_pai, 
                        1, 
                        $_SESSION['categorias_expandidas'][$projeto_id] ?? [], 
                        $projeto_id
                    );
                    ?>
                </tbody>
            </table>
        <?php endif; ?>
        
        <!-- Botões flutuantes fixos no canto inferior direito -->
        <div class="botoes-fixos">
            <a href="../despesas/registrar.php?projeto_id=<?php echo $projeto_id; ?>" class="botao-flutuante" title="Nova Despesa">
                <i class="fa fa-plus"></i>
            </a>
            <a href="../orcamento/editar.php?projeto_id=<?php echo $projeto_id; ?>" class="botao-flutuante secundario" title="Editar Orçamento">
                <i class="fa fa-pencil"></i>
            </a>
            <a href="../projetos/ver.php?projeto_id=<?php echo $projeto_id; ?>" class="botao-flutuante secundario" title="Detalhes do Projeto">
                <i class="fa fa-info"></i>
            </a>
        </div>
    </div>
    
    <script>
    $(document).ready(function() {
        // Manipular clique nos ícones de expansão
        $(document).on('click', '.toggle-icon.expandir', function() {
            var categoriaId = $(this).data('id');
            var isExpandido = $(this).text() === '−';
            
            // Alternar o símbolo
            $(this).text(isExpandido ? '+' : '−');
            
            // Mostrar/ocultar a linha de subcategorias
            $('.subcategorias-' + categoriaId).toggleClass('oculto');
            
            // Se estiver expandindo e o container estiver vazio, carregar via AJAX
            if (!isExpandido && $('#subcategoria-container-' + categoriaId).children().length === 0) {
                $('#subcategoria-container-' + categoriaId).html('<div class="loading-spinner">Carregando...</div>');
                
                $.ajax({
                    url: '../relatorios/obter_subcategorias.php',
                    method: 'POST',
                    data: { 
                        categoria_id: categoriaId,
                        mostrar_despesas: 0
                    },
                    success: function(response) {
                        $('#subcategoria-container-' + categoriaId).html(response);
                    },
                    error: function() {
                        $('#subcategoria-container-' + categoriaId).html('<div class="erro">Erro ao carregar subcategorias.</div>');
                    }
                });
            }
            
            // Atualizar o estado na sessão
            $.post('atualizar_estado_categoria.php', {
                projeto_id: <?php echo $projeto_id; ?>,
                categoria_id: categoriaId,
                expandido: !isExpandido
            });
        });
        
        // Confirmação de exclusão
        $(document).on('click', '.btn-acao.excluir', function(e) {
            if (!confirm("Tem certeza que deseja excluir esta despesa? Esta ação não pode ser desfeita.")) {
                e.preventDefault();
            }
        });
        
        // Adicionar tooltips nos valores de execução do orçamento
        $('.resumo-valor').each(function() {
            if ($(this).hasClass('positivo')) {
                $(this).attr('title', 'Orçamento dentro do limite planejado');
            } else if ($(this).hasClass('negativo')) {
                $(this).attr('title', 'Orçamento excedido! Atenção necessária');
            }
        });
        
        // Adicionar tooltips nas barras de progresso
        $('.progress-container').each(function() {
            let percentagem = $(this).find('.progress-text').text();
            let mensagem = '';
            
            if (percentagem.includes('100')) {
                mensagem = 'Orçamento totalmente utilizado';
            } else if (parseFloat(percentagem) > 100) {
                mensagem = 'Orçamento excedido!';
            } else {
                mensagem = 'Progresso da execução orçamentária';
            }
            
            $(this).attr('title', mensagem);
        });
        
        // Efeito de hover nas linhas de categoria
        $('.categoria-row').hover(
            function() {
                $(this).css('background-color', '#f0f7ff');
            },
            function() {
                $(this).css('background-color', '');
            }
        );
    });
    </script>
    
    <?php include '../includes/footer.php'; ?>
</body>
</html>
