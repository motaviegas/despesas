<?php
require_once '../config/db.php';
require_once '../includes/functions.php';
verificarLogin();

$mensagem = '';
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

// Processar edição de orçamento
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Verificar se está adicionando uma nova categoria
	// Verificar se está adicionando uma nova categoria
	   if (isset($_POST['adicionar_categoria'])) {
	       $numero_conta = trim($_POST['numero_conta']);
	       $descricao = trim($_POST['descricao']);
	       $budget = str_replace([',', '€'], ['.', ''], trim($_POST['budget']));
	       $usuario_id = $_SESSION['usuario_id'];
       
	       // Validar dados
	       if (empty($numero_conta) || empty($descricao) || !is_numeric($budget)) {
	           $mensagem = "Por favor, preencha todos os campos corretamente.";
	       } else {
	           // Verificar se o número de conta já existe
	           $stmt = $pdo->prepare("SELECT id FROM categorias WHERE projeto_id = :projeto_id AND numero_conta = :numero_conta");
	           $stmt->bindParam(':projeto_id', $projeto_id, PDO::PARAM_INT);
	           $stmt->bindParam(':numero_conta', $numero_conta, PDO::PARAM_STR);
	           $stmt->execute();
           
	           if ($stmt->rowCount() > 0) {
	               $mensagem = "Este número de conta já existe. Por favor, use outro.";
	           } else {
	               // Determinar nível e categoria pai
	               $nivel = substr_count($numero_conta, '.') + 1;
	               $categoria_pai_id = null;
               
	               if ($nivel > 1) {
	                   $partes = explode('.', $numero_conta);
	                   array_pop($partes);
	                   $numero_pai = implode('.', $partes);
                   
	                   // Buscar categoria pai
	                   $stmt = $pdo->prepare("SELECT id FROM categorias WHERE projeto_id = :projeto_id AND numero_conta = :numero_conta");
	                   $stmt->bindParam(':projeto_id', $projeto_id, PDO::PARAM_INT);
	                   $stmt->bindParam(':numero_conta', $numero_pai, PDO::PARAM_STR);
	                   $stmt->execute();
	                   $categoria_pai = $stmt->fetch();
                   
	                   if ($categoria_pai) {
	                       $categoria_pai_id = $categoria_pai['id'];
	                   } else {
	                       $mensagem = "Categoria pai não encontrada. Certifique-se de criar as categorias em ordem hierárquica.";
	                   }
	               }
               
	               if (empty($mensagem)) {
	                   try {
	                       $id = inserirCategoria($pdo, $projeto_id, $numero_conta, $descricao, $budget, $categoria_pai_id, $nivel);
	                       $mensagem = "Categoria adicionada com sucesso!";
	                   } catch (PDOException $e) {
	                       $mensagem = "Erro ao adicionar categoria: " . $e->getMessage();
	                   }
	               }
	           }
	       }
	   }
   
	   // Verificar se está atualizando o orçamento de uma categoria
	   elseif (isset($_POST['atualizar_budget'])) {
	       $categoria_id = intval($_POST['categoria_id']);
	       $novo_budget = str_replace([',', '€'], ['.', ''], trim($_POST['novo_budget']));
	       $motivo = trim($_POST['motivo']);
	       $usuario_id = $_SESSION['usuario_id'];
       
	       if (!is_numeric($novo_budget)) {
	           $mensagem = "Por favor, informe um valor válido para o orçamento.";
	       } elseif (empty($motivo)) {
	           $mensagem = "Por favor, informe o motivo da alteração.";
	       } else {
	           try {
	               // Obter informações sobre a categoria 
	               $stmt = $pdo->prepare("SELECT nivel FROM categorias WHERE id = :id");
	               $stmt->bindParam(':id', $categoria_id, PDO::PARAM_INT);
	               $stmt->execute();
	               $categoria = $stmt->fetch();
               
	               // Apenas categorias folha (normalmente nível 3+) podem ter orçamento editado diretamente
	               if ($categoria && $categoria['nivel'] >= 3) {
	                   atualizarBudgetCategoria($pdo, $categoria_id, $novo_budget, $usuario_id, $motivo);
	                   $mensagem = "Orçamento atualizado com sucesso!";
	               } else {
	                   $mensagem = "Apenas categorias de nível 3 ou superior podem ter orçamento editado diretamente.";
	               }
	           } catch (PDOException $e) {
	               $mensagem = "Erro ao atualizar orçamento: " . $e->getMessage();
	           }
	       }
	   }
	}

	// Obter todas as categorias do projeto com despesas usando a função corrigida
	$categorias = obterCategoriasDespesas($pdo, $projeto_id);
	$categorias_com_totais = calcularTotaisCategoriasDespesas($categorias);

	// Remover o total global (id 0) para a exibição na tabela
	if (isset($categorias_com_totais[0])) {
	   unset($categorias_com_totais[0]);
	}

	// Ordenar por número da conta
	uasort($categorias_com_totais, function($a, $b) {
	   return strnatcmp($a['numero_conta'], $b['numero_conta']);
	});
	?>
	<!DOCTYPE html>
	<html lang="pt">
	<head>
	   <meta charset="UTF-8">
	   <meta name="viewport" content="width=device-width, initial-scale=1.0">
	   <title>Editar Orçamento - Gestão de Eventos</title>
	   <link rel="stylesheet" href="../assets/css/style.css">
	   <style>
	       .categoria-1 { font-weight: bold; }
	       .categoria-2 { padding-left: 20px; }
	       .categoria-3 { padding-left: 40px; }
	       .categoria-4 { padding-left: 60px; }
	       .categoria-5 { padding-left: 80px; }
	       .editar-budget {
	           cursor: pointer;
	           color: #0056b3;
	           text-decoration: underline;
	       }
	       #editar-form {
	           display: none;
	           margin-top: 20px;
	           padding: 20px;
	           border: 1px solid #ddd;
	           border-radius: 4px;
	           background-color: #f9f9f9;
	       }
       
	       .progress-mini {
	           display: inline-block;
	           width: 50px;
	           height: 8px;
	           background-color: #e9ecef;
	           border-radius: 4px;
	           margin-right: 8px;
	           overflow: hidden;
	           vertical-align: middle;
	       }
       
	       .progress-fill {
	           height: 100%;
	           border-radius: 4px;
	       }
       
	       .verde { background-color: #28a745; }
	       .amarelo { background-color: #ffc107; }
	       .laranja { background-color: #fd7e14; }
	       .vermelho { background-color: #dc3545; }
       
	       .table-actions {
	           display: flex;
	           justify-content: space-between;
	           align-items: center;
	           margin-bottom: 20px;
	       }
       
	       .search-box {
	           position: relative;
	           width: 300px;
	       }
       
	       .search-input {
	           width: 100%;
	           padding: 8px 12px;
	           padding-left: 35px;
	           border: 1px solid #ddd;
	           border-radius: 4px;
	       }
       
	       .search-icon {
	           position: absolute;
	           left: 10px;
	           top: 50%;
	           transform: translateY(-50%);
	           color: #6c757d;
	       }
       
	       /* Indicador para valores calculados vs. editáveis */
	       .calculated {
	           color: #6c757d;
	           font-style: italic;
	           cursor: not-allowed;
	       }
       
	       .editable {
	           color: #0056b3;
	           cursor: pointer;
	       }
	   </style>
	</head>
	<body>
	   <?php include '../includes/header.php'; ?>
   
	   <div class="container">
	       <h1>Editar Orçamento</h1>
	       <h2>Projeto: <?php echo htmlspecialchars($projeto['nome']); ?></h2>
       
	       <?php if (!empty($mensagem)): ?>
	           <div class="alert alert-info"><?php echo $mensagem; ?></div>
	       <?php endif; ?>
       
	       <div class="actions">
	           <button id="mostrar-adicionar" class="btn btn-primary">Adicionar Nova Categoria</button>
	           <a href="../orcamento/importar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary">Importar Orçamento</a>
	           <a href="../relatorios/gerar.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-secondary">Ver Relatório</a>
	       </div>
       
	       <!-- Formulário para adicionar categoria (inicialmente oculto) -->
	       <div id="adicionar-form" style="display: none; margin-top: 20px; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">
	           <h3>Adicionar Nova Categoria</h3>
           
	           <form method="post" action="">
	               <div class="form-group">
	                   <label for="numero_conta">Número de Conta:</label>
	                   <input type="text" id="numero_conta" name="numero_conta" required placeholder="Ex: 1 ou 1.1 ou 1.1.1">
	                   <small>Use a notação com pontos para indicar hierarquia (ex: 1.1, 1.1.1)</small>
	               </div>
               
	               <div class="form-group">
	                   <label for="descricao">Descrição:</label>
	                   <input type="text" id="descricao" name="descricao" required>
	               </div>
               
	               <div class="form-group">
	                   <label for="budget">Orçamento (€):</label>
	                   <input type="text" id="budget" name="budget" placeholder="0,00" required>
	                   <small>Para categorias de nível 1 e 2, o orçamento será calculado automaticamente como a soma das subcategorias.</small>
	               </div>
               
	               <button type="submit" name="adicionar_categoria" class="btn btn-primary">Adicionar</button>
	               <button type="button" id="cancelar-adicionar" class="btn btn-secondary">Cancelar</button>
	           </form>
	       </div>
       
	       <!-- Formulário para editar orçamento (inicialmente oculto) -->
	       <div id="editar-form">
	           <h3>Atualizar Orçamento</h3>
           
	           <form method="post" action="">
	               <input type="hidden" id="categoria_id" name="categoria_id">
               
	               <div class="form-group">
	                   <label for="categoria_info">Categoria:</label>
	                   <div id="categoria_info"></div>
	               </div>
               
	               <div class="form-group">
	                   <label for="budget_atual">Orçamento Atual:</label>
	                   <div id="budget_atual"></div>
	               </div>
               
	               <div class="form-group">
	                   <label for="nivel_info">Nível:</label>
	                   <div id="nivel_info"></div>
	                   <small id="nivel_aviso" style="color: #dc3545; display: none;">
	                       Categorias de nível 1 e 2 têm orçamento calculado automaticamente a partir das subcategorias.
	                   </small>
	               </div>
               
	               <div class="form-group">
	                   <label for="novo_budget">Novo Orçamento (€):</label>
	                   <input type="text" id="novo_budget" name="novo_budget" placeholder="0,00" required>
	               </div>
               
	               <div class="form-group">
	                   <label for="motivo">Motivo da Alteração:</label>
	                   <textarea id="motivo" name="motivo" rows="3" required></textarea>
	               </div>
               
	               <button type="submit" name="atualizar_budget" class="btn btn-primary">Atualizar</button>
	               <button type="button" id="cancelar-editar" class="btn btn-secondary">Cancelar</button>
	           </form>
	       </div>
       
	       <h3>Categorias Atuais</h3>
       
	       <div class="table-actions">
	           <div class="search-box">
	               <i class="fa fa-search search-icon"></i>
	               <input type="text" id="busca-categoria" class="search-input" placeholder="Buscar categoria...">
	           </div>
           
	           <div>
	               <a href="../orcamento/historico.php?projeto_id=<?php echo $projeto_id; ?>" class="btn btn-sm">Ver Histórico de Alterações</a>
	           </div>
	       </div>
       
	       <?php if (count($categorias_com_totais) > 0): ?>
	           <table id="tabela-categorias">
	               <thead>
	                   <tr>
	                       <th>Número</th>
	                       <th>Descrição</th>
	                       <th>Orçamento</th>
	                       <th>Despesas</th>
	                       <th>% Exec.</th>
	                       <th>Saldo</th>
	                       <th>Ações</th>
	                   </tr>
	               </thead>
	               <tbody>
	                   <?php foreach ($categorias_com_totais as $categoria): ?>
	                       <?php 
	                           // Calcular percentagem de execução
	                           $percentagem = ($categoria['budget'] > 0) ? 
	                               ($categoria['total_despesas'] / $categoria['budget']) * 100 : 0;
                           
	                           // Determinar a classe da barra de progresso
	                           if ($percentagem <= 50) {
	                               $classe_barra = "verde";
	                           } elseif ($percentagem <= 75) {
	                               $classe_barra = "amarelo";
	                           } elseif ($percentagem <= 95) {
	                               $classe_barra = "laranja";
	                           } else {
	                               $classe_barra = "vermelho";
	                           }
                           
	                           // Limitar a largura a 100% para exibição
	                           $largura_barra = min($percentagem, 100);
                           
	                           // Determinar se é uma categoria editável ou calculada
	                           $e_calculada = $categoria['nivel'] < 3;
	                           $classe_editavel = $e_calculada ? 'calculated' : 'editable';
	                       ?>
	                       <tr class="categoria-<?php echo $categoria['nivel']; ?>">
	                           <td><?php echo htmlspecialchars($categoria['numero_conta']); ?></td>
	                           <td><?php echo htmlspecialchars($categoria['descricao']); ?></td>
	                           <td>
	                               <?php if ($e_calculada): ?>
	                                   <span class="<?php echo $classe_editavel; ?>" title="Valor calculado automaticamente">
	                                       <?php echo number_format($categoria['budget'], 2, ',', '.'); ?> €
	                                   </span>
	                               <?php else: ?>
	                                   <span class="editar-budget <?php echo $classe_editavel; ?>" data-id="<?php echo $categoria['id']; ?>" 
	                                         data-numero="<?php echo htmlspecialchars($categoria['numero_conta']); ?>" 
	                                         data-descricao="<?php echo htmlspecialchars($categoria['descricao']); ?>" 
	                                         data-budget="<?php echo $categoria['budget']; ?>"
	                                         data-nivel="<?php echo $categoria['nivel']; ?>">
	                                       <?php echo number_format($categoria['budget'], 2, ',', '.'); ?> €
	                                   </span>
	                               <?php endif; ?>
	                           </td>
	                           <td>
	                               <?php echo number_format($categoria['total_despesas'], 2, ',', '.'); ?> €
	                           </td>
	                           <td>
	                               <div class="progress-mini">
	                                   <div class="progress-fill <?php echo $classe_barra; ?>" style="width: <?php echo $largura_barra; ?>%;"></div>
	                               </div>
	                               <?php echo number_format($percentagem, 1, ',', '.'); ?>%
	                           </td>
	                           <td class="<?php echo $categoria['delta'] >= 0 ? 'positivo' : 'negativo'; ?>">
	                               <?php echo number_format($categoria['delta'], 2, ',', '.'); ?> €
	                           </td>
	                           <td>
	                               <a href="../historico/ver.php?categoria_id=<?php echo $categoria['id']; ?>" class="btn btn-sm">Histórico</a>
	                               <a href="../orcamento/editar_categoria.php?projeto_id=<?php echo $projeto_id; ?>&categoria_id=<?php echo $categoria['id']; ?>" class="btn btn-sm">Editar</a>
	                           </td>
	                       </tr>
	                   <?php endforeach; ?>
	               </tbody>
	           </table>
	       <?php else: ?>
	           <p>Nenhuma categoria encontrada. Adicione uma nova categoria ou importe o orçamento.</p>
	       <?php endif; ?>
	   </div>
   
	   <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
	   <script>
	       $(document).ready(function() {
	           // Mostrar/ocultar formulário de adição de categoria
	           $('#mostrar-adicionar').click(function() {
	               $('#adicionar-form').show();
	               $('#editar-form').hide();
	           });
           
	           $('#cancelar-adicionar').click(function() {
	               $('#adicionar-form').hide();
	           });
           
	           // Mostrar/ocultar formulário de edição de orçamento
	           $('.editar-budget').click(function() {
	               const id = $(this).data('id');
	               const numero = $(this).data('numero');
	               const descricao = $(this).data('descricao');
	               const budget = $(this).data('budget');
	               const nivel = $(this).data('nivel');
               
	               $('#categoria_id').val(id);
	               $('#categoria_info').text(numero + ' - ' + descricao);
	               $('#budget_atual').text(parseFloat(budget).toLocaleString('pt-PT', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' €');
	               $('#novo_budget').val(budget.replace('.', ','));
	               $('#nivel_info').text('Nível ' + nivel);
               
	               // Mostrar aviso se for categoria calculada automaticamente
	               if (nivel < 3) {
	                   $('#nivel_aviso').show();
	                   $('#novo_budget').attr('disabled', true);
	                   $('#motivo').attr('disabled', true);
	               } else {
	                   $('#nivel_aviso').hide();
	                   $('#novo_budget').attr('disabled', false);
	                   $('#motivo').attr('disabled', false);
	               }
               
	               $('#editar-form').show();
	               $('#adicionar-form').hide();
               
	               // Scroll para o formulário
	               $('#editar-form')[0].scrollIntoView({behavior: 'smooth'});
	           });
           
	           $('#cancelar-editar').click(function() {
	               $('#editar-form').hide();
	           });
           
	           // Busca de categorias
	           $('#busca-categoria').on('input', function() {
	               const termo = $(this).val().toLowerCase().trim();
               
	               $('#tabela-categorias tbody tr').each(function() {
	                   const numero = $(this).find('td:first').text().toLowerCase();
	                   const descricao = $(this).find('td:nth-child(2)').text().toLowerCase();
                   
	                   if (numero.includes(termo) || descricao.includes(termo)) {
	                       $(this).show();
	                   } else {
	                       $(this).hide();
	                   }
	               });
	           });
           
	           // Formatação do campo de orçamento
	           $('#budget, #novo_budget').on('input', function() {
	               let valor = $(this).val().replace(/[^\d,]/g, '');
	               if (valor) {
	                   // Verificar se o valor já tem uma vírgula
	                   if (valor.includes(',')) {
	                       const partes = valor.split(',');
	                       // Garantir que há no máximo 2 casas decimais
	                       if (partes[1] && partes[1].length > 2) {
	                           partes[1] = partes[1].substring(0, 2);
	                           valor = partes.join(',');
	                       }
	                   }
	                   $(this).val(valor);
	               }
	           });
	       });
	   </script>
   
	   <?php include '../includes/footer.php'; ?>
	</body>
	</html>
