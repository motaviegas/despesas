<?php
// Funções de autenticação
function verificarLogin() {
   session_start();
   if (!isset($_SESSION['usuario_id'])) {
       header('Location: ../login.php');
       exit;
   }
}

function ehAdmin() {
   return (isset($_SESSION['tipo_conta']) && $_SESSION['tipo_conta'] == 'admin');
}

// Funções relacionadas às categorias e orçamento
function inserirCategoria($pdo, $projeto_id, $numero_conta, $descricao, $budget, $categoria_pai_id, $nivel) {
   $stmt = $pdo->prepare("INSERT INTO categorias (projeto_id, numero_conta, descricao, budget, categoria_pai_id, nivel) 
                         VALUES (:projeto_id, :numero_conta, :descricao, :budget, :categoria_pai_id, :nivel)");
   $stmt->bindParam(':projeto_id', $projeto_id, PDO::PARAM_INT);
   $stmt->bindParam(':numero_conta', $numero_conta, PDO::PARAM_STR);
   $stmt->bindParam(':descricao', $descricao, PDO::PARAM_STR);
   $stmt->bindParam(':budget', $budget, PDO::PARAM_STR);
   $stmt->bindParam(':categoria_pai_id', $categoria_pai_id, PDO::PARAM_INT);
   $stmt->bindParam(':nivel', $nivel, PDO::PARAM_INT);
   $stmt->execute();
   return $pdo->lastInsertId();
}

function atualizarBudgetCategoria($pdo, $categoria_id, $novo_valor, $usuario_id, $motivo = '') {
   // Obter valor atual
   $stmt = $pdo->prepare("SELECT budget FROM categorias WHERE id = :id");
   $stmt->bindParam(':id', $categoria_id, PDO::PARAM_INT);
   $stmt->execute();
   $categoria = $stmt->fetch();
   $valor_anterior = $categoria['budget'];
   
   // Atualizar valor
   $stmt = $pdo->prepare("UPDATE categorias SET budget = :budget WHERE id = :id");
   $stmt->bindParam(':budget', $novo_valor, PDO::PARAM_STR);
   $stmt->bindParam(':id', $categoria_id, PDO::PARAM_INT);
   $stmt->execute();
   
   // Registrar histórico
   $stmt = $pdo->prepare("INSERT INTO historico_budget (categoria_id, valor_anterior, valor_novo, alterado_por, motivo) 
                         VALUES (:categoria_id, :valor_anterior, :valor_novo, :alterado_por, :motivo)");
   $stmt->bindParam(':categoria_id', $categoria_id, PDO::PARAM_INT);
   $stmt->bindParam(':valor_anterior', $valor_anterior, PDO::PARAM_STR);
   $stmt->bindParam(':valor_novo', $novo_valor, PDO::PARAM_STR);
   $stmt->bindParam(':alterado_por', $usuario_id, PDO::PARAM_INT);
   $stmt->bindParam(':motivo', $motivo, PDO::PARAM_STR);
   $stmt->execute();
   
   // Atualizar categorias pai recursivamente
   atualizarBudgetCategoriasPai($pdo, $categoria_id, $novo_valor - $valor_anterior, $usuario_id);
}

function atualizarBudgetCategoriasPai($pdo, $categoria_id, $diferenca, $usuario_id) {
   // Encontrar categoria pai
   $stmt = $pdo->prepare("SELECT categoria_pai_id FROM categorias WHERE id = :id");
   $stmt->bindParam(':id', $categoria_id, PDO::PARAM_INT);
   $stmt->execute();
   $categoria = $stmt->fetch();
   
   if ($categoria && $categoria['categoria_pai_id']) {
       $pai_id = $categoria['categoria_pai_id'];
       
       // Obter valor atual da categoria pai
       $stmt = $pdo->prepare("SELECT budget FROM categorias WHERE id = :id");
       $stmt->bindParam(':id', $pai_id, PDO::PARAM_INT);
       $stmt->execute();
       $categoria_pai = $stmt->fetch();
       $valor_anterior = $categoria_pai['budget'];
       $novo_valor = $valor_anterior + $diferenca;
       
       // Atualizar valor
       $stmt = $pdo->prepare("UPDATE categorias SET budget = :budget WHERE id = :id");
       $stmt->bindParam(':budget', $novo_valor, PDO::PARAM_STR);
       $stmt->bindParam(':id', $pai_id, PDO::PARAM_INT);
       $stmt->execute();
       
       // Registrar histórico
       $stmt = $pdo->prepare("INSERT INTO historico_budget (categoria_id, valor_anterior, valor_novo, alterado_por, motivo) 
                             VALUES (:categoria_id, :valor_anterior, :valor_novo, :alterado_por, :motivo)");
       $stmt->bindParam(':categoria_id', $pai_id, PDO::PARAM_INT);
       $stmt->bindParam(':valor_anterior', $valor_anterior, PDO::PARAM_STR);
       $stmt->bindParam(':valor_novo', $novo_valor, PDO::PARAM_STR);
       $stmt->bindParam(':alterado_por', $usuario_id, PDO::PARAM_INT);
       $motivo = "Atualização automática baseada em alterações na subcategoria";
       $stmt->bindParam(':motivo', $motivo, PDO::PARAM_STR);
       $stmt->execute();
       
       // Recursivamente atualizar categorias pai acima
       atualizarBudgetCategoriasPai($pdo, $pai_id, $diferenca, $usuario_id);
   }
}

// Funções relacionadas à importação do orçamento
function processarImportacaoCSV($pdo, $arquivo, $projeto_id, $usuario_id) {
   $handle = fopen($arquivo, "r");
   if ($handle !== FALSE) {
       // Pular cabeçalho
       fgetcsv($handle, 1000, "\t");
       
       $categorias = [];
       
       while (($data = fgetcsv($handle, 1000, "\t")) !== FALSE) {
           if (count($data) >= 3) {
               $numero_conta = trim($data[0]);
               $descricao = trim($data[1]);
               $budget = str_replace(['€', '.', ','], ['', '', '.'], trim($data[2]));
               $budget = floatval($budget);
               
               // Determinar nível
               $nivel = substr_count($numero_conta, '.') + 1;
               
               // Encontrar categoria pai
               $categoria_pai_id = null;
               if ($nivel > 1) {
                   $partes = explode('.', $numero_conta);
                   array_pop($partes);
                   $numero_pai = implode('.', $partes);
                   
                   if (isset($categorias[$numero_pai])) {
                       $categoria_pai_id = $categorias[$numero_pai];
                   }
               }
               
               // Inserir categoria e armazenar ID
               $id = inserirCategoria($pdo, $projeto_id, $numero_conta, $descricao, $budget, $categoria_pai_id, $nivel);
               $categorias[$numero_conta] = $id;
           }
       }
       fclose($handle);
       return true;
   }
   return false;
}

// Funções relacionadas às despesas
function registrarDespesa($pdo, $projeto_id, $categoria_id, $fornecedor_nome, $tipo, $valor, $descricao, $data_despesa, $usuario_id, $anexo_path = null) {
   // Verificar se fornecedor existe ou criar novo
   $fornecedor_id = obterOuCriarFornecedor($pdo, $fornecedor_nome);
   
   // Registrar despesa
   $stmt = $pdo->prepare("INSERT INTO despesas (projeto_id, categoria_id, fornecedor_id, tipo, valor, descricao, data_despesa, registrado_por, anexo_path) 
                         VALUES (:projeto_id, :categoria_id, :fornecedor_id, :tipo, :valor, :descricao, :data_despesa, :registrado_por, :anexo_path)");
   $stmt->bindParam(':projeto_id', $projeto_id, PDO::PARAM_INT);
   $stmt->bindParam(':categoria_id', $categoria_id, PDO::PARAM_INT);
   $stmt->bindParam(':fornecedor_id', $fornecedor_id, PDO::PARAM_INT);
   $stmt->bindParam(':tipo', $tipo, PDO::PARAM_STR);
   $stmt->bindParam(':valor', $valor, PDO::PARAM_STR);
   $stmt->bindParam(':descricao', $descricao, PDO::PARAM_STR);
   $stmt->bindParam(':data_despesa', $data_despesa, PDO::PARAM_STR);
   $stmt->bindParam(':registrado_por', $usuario_id, PDO::PARAM_INT);
   $stmt->bindParam(':anexo_path', $anexo_path, PDO::PARAM_STR);
   $stmt->execute();
   
   // Após registrar a despesa, atualizar as somas nas categorias pai
   atualizarSomasDespesasCategoriasPai($pdo, $categoria_id, $valor, $usuario_id);
   
   return $pdo->lastInsertId();
}

// Nova função para atualizar as somas de despesas nas categorias pai
function atualizarSomasDespesasCategoriasPai($pdo, $categoria_id, $valor_despesa, $usuario_id) {
   // Encontrar categoria pai
   $stmt = $pdo->prepare("SELECT categoria_pai_id FROM categorias WHERE id = :id");
   $stmt->bindParam(':id', $categoria_id, PDO::PARAM_INT);
   $stmt->execute();
   $categoria = $stmt->fetch();
   
   if ($categoria && $categoria['categoria_pai_id']) {
       $pai_id = $categoria['categoria_pai_id'];
       
       // Recursivamente atualizar categorias pai acima
       atualizarSomasDespesasCategoriasPai($pdo, $pai_id, $valor_despesa, $usuario_id);
   }
}

function obterOuCriarFornecedor($pdo, $nome) {
   // Verificar se já existe
   $stmt = $pdo->prepare("SELECT id FROM fornecedores WHERE nome = :nome");
   $stmt->bindParam(':nome', $nome, PDO::PARAM_STR);
   $stmt->execute();
   $fornecedor = $stmt->fetch();
   
   if ($fornecedor) {
       return $fornecedor['id'];
   } else {
       // Criar novo fornecedor
       $stmt = $pdo->prepare("INSERT INTO fornecedores (nome) VALUES (:nome)");
       $stmt->bindParam(':nome', $nome, PDO::PARAM_STR);
       $stmt->execute();
       return $pdo->lastInsertId();
   }
}

function buscarFornecedoresPorTermo($pdo, $termo) {
   $termo = "%$termo%";
   $stmt = $pdo->prepare("SELECT id, nome FROM fornecedores WHERE nome LIKE :termo ORDER BY nome LIMIT 10");
   $stmt->bindParam(':termo', $termo, PDO::PARAM_STR);
   $stmt->execute();
   return $stmt->fetchAll();
}

function obterCategoriasPorDescricaoOuNumero($pdo, $projeto_id, $termo) {
   $termo = "%$termo%";
   $stmt = $pdo->prepare("SELECT id, numero_conta, descricao FROM categorias 
                         WHERE projeto_id = :projeto_id AND (descricao LIKE :termo OR numero_conta LIKE :termo) 
                         ORDER BY numero_conta LIMIT 10");
   $stmt->bindParam(':projeto_id', $projeto_id, PDO::PARAM_INT);
   $stmt->bindParam(':termo', $termo, PDO::PARAM_STR);
   $stmt->execute();
   return $stmt->fetchAll();
}

function obterCategoriasDespesas($pdo, $projeto_id) {
   $stmt = $pdo->prepare("
       SELECT c.id, c.numero_conta, c.descricao, c.budget, c.nivel, c.categoria_pai_id,
              COALESCE(SUM(d.valor), 0) as total_despesas,
              (c.budget - COALESCE(SUM(d.valor), 0)) as delta
       FROM categorias c
       LEFT JOIN despesas d ON c.id = d.categoria_id
       WHERE c.projeto_id = :projeto_id
       GROUP BY c.id
       ORDER BY c.numero_conta
   ");
   $stmt->bindParam(':projeto_id', $projeto_id, PDO::PARAM_INT);
   $stmt->execute();
   return $stmt->fetchAll();
}

// Função reescrita para calcular totais corretamente, com foco na soma do budget
function calcularTotaisCategoriasDespesas($categorias) {
   // Organizar categorias por ID, pai e nível para processamento
   $categorias_por_id = [];
   $categorias_por_pai = [];
   $categorias_por_nivel = [];
   $max_nivel = 1;
   
   foreach ($categorias as $categoria) {
       $id = $categoria['id'];
       $pai_id = $categoria['categoria_pai_id'];
       $nivel = $categoria['nivel'];
       
       // Armazenar categoria com valores originais
       $categorias_por_id[$id] = $categoria;
       
       // Organizar por pai
       if ($pai_id) {
           if (!isset($categorias_por_pai[$pai_id])) {
               $categorias_por_pai[$pai_id] = [];
           }
           $categorias_por_pai[$pai_id][] = $id;
       }
       
       // Organizar por nível
       if (!isset($categorias_por_nivel[$nivel])) {
           $categorias_por_nivel[$nivel] = [];
       }
       $categorias_por_nivel[$nivel][] = $id;
       
       // Rastrear o nível máximo
       if ($nivel > $max_nivel) {
           $max_nivel = $nivel;
       }
   }
   
   // Resetar os totais de despesas e budget para categorias que têm filhos
   // Isso garante que não contamos valores em duplicidade
   foreach ($categorias_por_id as $id => $categoria) {
       if (isset($categorias_por_pai[$id])) {
           // Esta é uma categoria pai - vamos recalcular despesas e budget
           $categorias_por_id[$id]['total_despesas'] = 0;
           $categorias_por_id[$id]['budget'] = 0;
       }
   }
   
   // Processar de baixo para cima, do nível mais profundo para o mais alto
   for ($nivel = $max_nivel; $nivel >= 1; $nivel--) {
       if (isset($categorias_por_nivel[$nivel])) {
           foreach ($categorias_por_nivel[$nivel] as $categoria_id) {
               // Se for uma categoria folha (sem filhos) ou já tivermos processado seus filhos:
               if (!isset($categorias_por_pai[$categoria_id])) {
                   // É uma categoria folha (sem filhos)
                   // Seus valores já estão corretos, não precisamos fazer nada
               } else {
                   // É uma categoria pai - somar valores dos filhos
                   $total_despesas = 0;
                   $total_budget = 0;
                   
                   foreach ($categorias_por_pai[$categoria_id] as $filho_id) {
                       $total_despesas += $categorias_por_id[$filho_id]['total_despesas'];
                       $total_budget += $categorias_por_id[$filho_id]['budget'];
                   }
                   
                   // Atualizar os totais
                   $categorias_por_id[$categoria_id]['total_despesas'] = $total_despesas;
                   $categorias_por_id[$categoria_id]['budget'] = $total_budget;
                   $categorias_por_id[$categoria_id]['delta'] = $total_budget - $total_despesas;
               }
           }
       }
   }
   
   // Criar um total global (soma de todos os valores de nível 1)
   $total_global = [
       'id' => 0,
       'numero_conta' => 'TOTAL',
       'descricao' => 'TOTAL GLOBAL',
       'nivel' => 0,
       'categoria_pai_id' => null,
       'budget' => 0,
       'total_despesas' => 0,
       'delta' => 0
   ];
   
   // Calcular o total apenas com as categorias de nível 1 (topo)
   if (isset($categorias_por_nivel[1])) {
       foreach ($categorias_por_nivel[1] as $categoria_id) {
           $categoria = $categorias_por_id[$categoria_id];
           $total_global['budget'] += $categoria['budget'];
           $total_global['total_despesas'] += $categoria['total_despesas'];
       }
       $total_global['delta'] = $total_global['budget'] - $total_global['total_despesas'];
   }
   
   // Adicionar o total global à lista de categorias
   $categorias_por_id[0] = $total_global;
   
   return $categorias_por_id;
}

function obterDespesasPorCategoria($pdo, $categoria_id) {
   $stmt = $pdo->prepare("
       SELECT d.id, d.data_despesa, d.tipo, f.nome as fornecedor, 
              d.descricao, d.valor, d.anexo_path
       FROM despesas d
       JOIN fornecedores f ON d.fornecedor_id = f.id
       WHERE d.categoria_id = :categoria_id
       ORDER BY d.data_despesa DESC
   ");
   $stmt->bindParam(':categoria_id', $categoria_id, PDO::PARAM_INT);
   $stmt->execute();
   return $stmt->fetchAll();
}

// Função para exportar relatório
function gerarCSVRelatorio($pdo, $projeto_id, $categorias_despesas) {
   $output = fopen('php://output', 'w');
   
   // Cabeçalho
   fputcsv($output, ['Nr de conta', 'DESCRIÇÃO DA RUBRICA', 'Tot Despesa', 'Delta Budget', 'Budget']);
   
   // Dados
   foreach ($categorias_despesas as $categoria) {
       fputcsv($output, [
           $categoria['numero_conta'],
           $categoria['descricao'],
           $categoria['total_despesas'],
           $categoria['delta'],
           $categoria['budget']
       ]);
   }
   
   fclose($output);
}

// Função para obter todas as subcategorias de uma categoria, incluindo níveis profundos
function obterTodasSubcategorias($pdo, $categoria_pai_id) {
   $stmt = $pdo->prepare("
       WITH RECURSIVE subcategorias AS (
           SELECT id, numero_conta, descricao, budget, nivel, categoria_pai_id
           FROM categorias
           WHERE id = :categoria_pai_id
           
           UNION ALL
           
           SELECT c.id, c.numero_conta, c.descricao, c.budget, c.nivel, c.categoria_pai_id
           FROM categorias c
           JOIN subcategorias s ON c.categoria_pai_id = s.id
       )
       SELECT * FROM subcategorias WHERE id != :categoria_pai_id ORDER BY numero_conta
   ");
   $stmt->bindParam(':categoria_pai_id', $categoria_pai_id, PDO::PARAM_INT);
   $stmt->execute();
   return $stmt->fetchAll();
}

// Função para obter histórico de edições de despesas
function obterHistoricoEdicoesDespesa($pdo, $despesa_id) {
   $stmt = $pdo->prepare("
       SELECT h.*, 
              c_ant.numero_conta AS numero_conta_anterior, 
              c_ant.descricao AS descricao_categoria_anterior,
              c_novo.numero_conta AS numero_conta_novo, 
              c_novo.descricao AS descricao_categoria_novo,
              u.email AS usuario_email
       FROM historico_edicoes h
       LEFT JOIN categorias c_ant ON h.categoria_id_anterior = c_ant.id
       LEFT JOIN categorias c_novo ON h.categoria_id_novo = c_novo.id
       JOIN usuarios u ON h.editado_por = u.id
       WHERE h.tipo_registro = 'despesa' AND h.registro_id = :despesa_id
       ORDER BY h.data_edicao DESC
   ");
   $stmt->bindParam(':despesa_id', $despesa_id, PDO::PARAM_INT);
   $stmt->execute();
   return $stmt->fetchAll();
}

// Função para obter histórico de exclusões
function obterHistoricoExclusoes($pdo, $projeto_id, $tipo_registro = null) {
   $sql = "
       SELECT h.*, 
              c.numero_conta, 
              c.descricao AS categoria_descricao,
              f.nome AS fornecedor_nome,
              u.email AS usuario_email
       FROM historico_exclusoes h
       LEFT JOIN categorias c ON h.categoria_id = c.id
       LEFT JOIN fornecedores f ON h.fornecedor_id = f.id
       JOIN usuarios u ON h.excluido_por = u.id
       WHERE h.projeto_id = :projeto_id
   ";
   
   if ($tipo_registro) {
       $sql .= " AND h.tipo_registro = :tipo_registro";
   }
   
   $sql .= " ORDER BY h.data_exclusao DESC";
   
   $stmt = $pdo->prepare($sql);
   $stmt->bindParam(':projeto_id', $projeto_id, PDO::PARAM_INT);
   
   if ($tipo_registro) {
       $stmt->bindParam(':tipo_registro', $tipo_registro, PDO::PARAM_STR);
   }
   
   $stmt->execute();
   return $stmt->fetchAll();
}
?>
