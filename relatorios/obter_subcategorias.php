<?php
require_once '../config/db.php';
require_once '../includes/functions.php';
verificarLogin();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['categoria_id'])) {
    $categoria_id = intval($_POST['categoria_id']);
    $mostrar_despesas = isset($_POST['mostrar_despesas']) ? intval($_POST['mostrar_despesas']) : 0;
    
    // Obter todas as subcategorias diretas (filhos)
    $stmt = $pdo->prepare("
        SELECT c.id, c.numero_conta, c.descricao, c.budget, c.nivel, c.categoria_pai_id,
               COALESCE(SUM(d.valor), 0) as total_despesas,
               (c.budget - COALESCE(SUM(d.valor), 0)) as delta
        FROM categorias c
        LEFT JOIN despesas d ON c.id = d.categoria_id
        WHERE c.categoria_pai_id = :categoria_pai_id
        GROUP BY c.id
        ORDER BY c.numero_conta
    ");
    $stmt->bindParam(':categoria_pai_id', $categoria_id, PDO::PARAM_INT);
    $stmt->execute();
    $subcategorias_raw = $stmt->fetchAll();
    
    // Obter todas as categorias e despesas do projeto para calcular os totais corretos
    $stmt = $pdo->prepare("SELECT projeto_id FROM categorias WHERE id = :id");
    $stmt->bindParam(':id', $categoria_id, PDO::PARAM_INT);
    $stmt->execute();
    $projeto = $stmt->fetch();
    $projeto_id = $projeto['projeto_id'];
    
    // Obter todas as categorias do projeto e calcular os totais
    $categorias_despesas_raw = obterCategoriasDespesas($pdo, $projeto_id);
    $categorias_despesas = calcularTotaisCategoriasDespesas($categorias_despesas_raw);
    
    // Filtrar apenas as subcategorias desta categoria
    $subcategorias = [];
    foreach ($subcategorias_raw as $sub) {
        if (isset($categorias_despesas[$sub['id']])) {
            $subcategorias[$sub['id']] = $categorias_despesas[$sub['id']];
        }
    }
    
    // Exibir subcategorias se existirem
    if (count($subcategorias) > 0) {
        echo '<table class="subtabela" style="width:100%;">';
        
        $contador = 0; // Contador para alternância de cores
        foreach ($subcategorias as $subcategoria) {
            // Verificar se esta subcategoria tem filhos
            $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM categorias WHERE categoria_pai_id = :categoria_pai_id");
            $stmt->bindParam(':categoria_pai_id', $subcategoria['id'], PDO::PARAM_INT);
            $stmt->execute();
            $tem_filhos = $stmt->fetch()['total'] > 0;
            
            // Verificar se existem despesas nesta subcategoria
            $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM despesas WHERE categoria_id = :categoria_id");
            $stmt->bindParam(':categoria_id', $subcategoria['id'], PDO::PARAM_INT);
            $stmt->execute();
            $tem_despesas = $stmt->fetch()['total'] > 0;
            
            // Calcular percentagem de execução para esta categoria
            $percentagem_execucao = ($subcategoria['budget'] > 0) ? 
                ($subcategoria['total_despesas'] / $subcategoria['budget']) * 100 : 0;
                
            // Determinar a cor baseada na percentagem
            if ($percentagem_execucao <= 50) {
                $barra_classe = "verde";
                $barra_largura = $percentagem_execucao * 2; // Dobro para ocupar metade quando chegar a 50%
            } elseif ($percentagem_execucao <= 75) {
                $barra_classe = "amarelo";
                $barra_largura = $percentagem_execucao;
            } elseif ($percentagem_execucao <= 95) {
                $barra_classe = "laranja";
                $barra_largura = $percentagem_execucao;
            } else {
                if ($percentagem_execucao > 100) {
                    $barra_classe = "vermelho-intenso";
                } else {
                    $barra_classe = "vermelho";
                }
                $barra_largura = min($percentagem_execucao, 100); // Limitar a 100%
            }
            
            // Aplicar cores alternadas
            $cor_classe = ($contador % 2 == 0) ? 'linha-clara' : 'linha-escura';
            $contador++;
            
            echo '<tr class="categoria-row categoria-' . $subcategoria['nivel'] . ' ' . $cor_classe . '">';
            echo '<td>' . htmlspecialchars($subcategoria['numero_conta']) . '</td>';
            
            echo '<td class="nivel-' . $subcategoria['nivel'] . '">';
            // Adicionar ícone de expansão se tiver filhos
            if ($tem_filhos) {
                echo '<span class="toggle-icon expandir" data-id="' . $subcategoria['id'] . '">+</span> ';
            } else {
                echo '<span class="toggle-icon"></span>';
            }
            
            echo htmlspecialchars($subcategoria['descricao']);
            
            // Adicionar link "Ver despesas" para categorias de nível 3 ou superior
            if ($subcategoria['nivel'] >= 3 || !$tem_filhos) {
                echo ' <a href="?projeto_id=' . $projeto_id . '&ver_despesas=' . $subcategoria['id'] . '" class="ver-despesas">
                        <i class="fa fa-eye"></i> Ver despesas
                      </a>';
            }
            
            echo '</td>';
            
            // Coluna de despesas com indicador visual
            echo '<td>';
            if ($subcategoria['total_despesas'] > 0) {
                echo '<div class="mini-progress-container" title="' . number_format($percentagem_execucao, 1) . '% executado">';
                echo '<div class="mini-progress-bar ' . $barra_classe . '" style="width: ' . $barra_largura . '%;"></div>';
                echo '</div>';
            }
            echo number_format($subcategoria['total_despesas'], 2, ',', '.') . ' €</td>';
            
            // Classe para o delta (positivo ou negativo)
            $delta_class = $subcategoria['delta'] >= 0 ? 'positivo' : 'negativo';
            echo '<td class="' . $delta_class . '">' . number_format($subcategoria['delta'], 2, ',', '.') . ' €</td>';
            
            echo '<td>' . number_format($subcategoria['budget'], 2, ',', '.') . ' €</td>';
            echo '</tr>';
            
            // Adicionar linha para possíveis subcategorias (inicialmente oculta)
            echo '<tr class="subcategorias-row subcategorias-' . $subcategoria['id'] . ' oculto">';
            echo '<td colspan="5"><div class="subcategoria-container" id="subcategoria-container-' . $subcategoria['id'] . '"></div></td></tr>';
        }
        
        echo '</table>';
    } else {
        echo '<div class="alert alert-info">Esta categoria não possui subcategorias.</div>';
    }
    
    // Exibir as despesas desta categoria se solicitado
    if ($mostrar_despesas) {
        $despesas = obterDespesasPorCategoria($pdo, $categoria_id);
        
        if (count($despesas) > 0) {
            echo '<div class="despesas-container">';
            echo '<h4>Despesas desta categoria</h4>';
            echo '<table class="despesas-tabela">';
            echo '<thead><tr>
                    <th>Data</th>
                    <th>Tipo</th>
                    <th>Fornecedor</th>
                    <th>Descrição</th>
                    <th>Valor</th>
                    <th>Ações</th>
                  </tr></thead><tbody>';
            
            $contador = 0; // Contador para alternância de cores
            foreach ($despesas as $despesa) {
                // Aplicar cores alternadas
                $cor_classe = ($contador % 2 == 0) ? 'linha-clara' : 'linha-escura';
                $contador++;
                
                echo '<tr class="despesa-item ' . $cor_classe . '">';
                echo '<td>' . date('d/m/Y', strtotime($despesa['data_despesa'])) . '</td>';
                echo '<td>' . ucfirst(htmlspecialchars($despesa['tipo'])) . '</td>';
                echo '<td>' . htmlspecialchars($despesa['fornecedor']) . '</td>';
                echo '<td>' . htmlspecialchars($despesa['descricao']) . '</td>';
                echo '<td>' . number_format($despesa['valor'], 2, ',', '.') . ' €</td>';
                
                // Links para ações: anexo, editar e excluir
                echo '<td class="acoes-container">';
                
                // Link para o anexo, se existir
                if ($despesa['anexo_path']) {
                    echo '<a href="../assets/arquivos/' . htmlspecialchars($despesa['anexo_path']) . '" target="_blank" class="btn-acao visualizar" title="Ver fatura">
                            <i class="fa fa-file-pdf-o"></i>
                          </a> ';
                }
                
                // Adicionar links para editar e excluir
                echo '<a href="../despesas/editar.php?projeto_id=' . $projeto_id . '&despesa_id=' . $despesa['id'] . '" class="btn-acao editar" title="Editar despesa">
                        <i class="fa fa-pencil"></i>
                      </a> ';
                echo '<a href="../despesas/excluir.php?projeto_id=' . $projeto_id . '&despesa_id=' . $despesa['id'] . '" class="btn-acao excluir" title="Excluir despesa">
                        <i class="fa fa-trash"></i>
                      </a>';
                
                echo '</td>';
                
                echo '</tr>';
            }
            
            echo '</tbody></table>';
            echo '</div>';
        } else {
            echo '<div class="sem-despesas">Nenhuma despesa registrada para esta categoria.</div>';
        }
    }
} else {
    echo 'Parâmetros inválidos.';
}
?>

<style>
.mini-progress-container {
    width: 60px;
    height: 8px;
    background-color: #e9ecef;
    border-radius: 4px;
    display: inline-block;
    margin-right: 8px;
    overflow: hidden;
    vertical-align: middle;
}

.mini-progress-bar {
    height: 100%;
    border-radius: 4px;
}

.mini-progress-bar.verde {
    background-color: #28a745;
}

.mini-progress-bar.amarelo {
    background-color: #ffc107;
}

.mini-progress-bar.laranja {
    background-color: #fd7e14;
}

.mini-progress-bar.vermelho {
    background-color: #dc3545;
}

.mini-progress-bar.vermelho-intenso {
    background-color: #b71c1c;
    animation: pulse 1.5s infinite;
}

.acoes-container {
    white-space: nowrap;
}
</style>
