<?php
// 1.1 Inclusão de arquivos necessários
require_once '../config/db.php';
require_once '../includes/functions.php';

// 1.2 Verificação de login
verificarLogin();

// 1.3 Verificação de privilégios de admin
if (!ehAdmin()) {
    header('Location: ../dashboard.php');
    exit;
}

// 2.1 Variável para mensagens de feedback
$mensagem = '';

// 2.2 Verificação de método POST
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // 2.2.1 Promoção de usuário para admin
    if (isset($_POST['promover']) && isset($_POST['usuario_id'])) {
        $usuario_id = intval($_POST['usuario_id']);
        try {
            $stmt = $pdo->prepare("UPDATE usuarios SET tipo_conta = 'admin' WHERE id = :id");
            $stmt->bindParam(':id', $usuario_id, PDO::PARAM_INT);
            $stmt->execute();
            $mensagem = "Usuário promovido a administrador com sucesso.";
        } catch (PDOException $e) {
            $mensagem = "Erro ao promover usuário: " . $e->getMessage();
        }
    }

    // 2.2.2 Rebaixamento de usuário para normal
    elseif (isset($_POST['rebaixar']) && isset($_POST['usuario_id'])) {
        $usuario_id = intval($_POST['usuario_id']);
        $admin_atual = $_SESSION['usuario_id'];
        if ($usuario_id == $admin_atual) {
            $mensagem = "Você não pode rebaixar a si mesmo.";
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE usuarios SET tipo_conta = 'normal' WHERE id = :id");
                $stmt->bindParam(':id', $usuario_id, PDO::PARAM_INT);
                $stmt->execute();
                $mensagem = "Privilégios de administrador removidos com sucesso.";
            } catch (PDOException $e) {
                $mensagem = "Erro ao rebaixar usuário: " . $e->getMessage();
            }
        }
    }

    // 2.2.3 Adição de novo usuário
    elseif (isset($_POST['adicionar'])) {
        $email = trim($_POST['email']);
        $senha = $_POST['senha'];
        $tipo_conta = $_POST['tipo_conta'];
        if (empty($email) || empty($senha)) {
            $mensagem = "Por favor, preencha todos os campos.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $mensagem = "Por favor, informe um e-mail válido.";
        } else {
            // Verificação de e-mail existente
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = :email");
            $stmt->bindParam(':email', $email, PDO::PARAM_STR);
            $stmt->execute();
            if ($stmt->rowCount() > 0) {
                $mensagem = "Este e-mail já está em uso.";
            } else {
                // Criptografia de senha e inserção
                $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
                try {
                    $stmt = $pdo->prepare("INSERT INTO usuarios (email, senha, tipo_conta) VALUES (:email, :senha, :tipo_conta)");
                    $stmt->bindParam(':email', $email, PDO::PARAM_STR);
                    $stmt->bindParam(':senha', $senha_hash, PDO::PARAM_STR);
                    $stmt->bindParam(':tipo_conta', $tipo_conta, PDO::PARAM_STR);
                    $stmt->execute();
                    $mensagem = "Usuário adicionado com sucesso!";
                } catch (PDOException $e) {
                    $mensagem = "Erro ao adicionar usuário: " . $e->getMessage();
                }
            }
        }
    }

    // 2.2.4 Redefinição de senha
    elseif (isset($_POST['redefinir_senha']) && isset($_POST['usuario_id'])) {
        $usuario_id = intval($_POST['usuario_id']);
        $nova_senha = $_POST['nova_senha'];
        if (empty($nova_senha) || strlen($nova_senha) < 6) {
            $mensagem = "A senha deve ter pelo menos 6 caracteres.";
        } else {
            $senha_hash = password_hash($nova_senha, PASSWORD_DEFAULT);
            try {
                $stmt = $pdo->prepare("UPDATE usuarios SET senha = :senha WHERE id = :id");
                $stmt->bindParam(':senha', $senha_hash, PDO::PARAM_STR);
                $stmt->bindParam(':id', $usuario_id, PDO::PARAM_INT);
                $stmt->execute();
                $mensagem = "Senha redefinida com sucesso.";
            } catch (PDOException $e) {
                $mensagem = "Erro ao redefinir senha: " . $e->getMessage();
            }
        }
    }
}
// 3.1 Obter todos os usuários do banco de dados
$stmt = $pdo->prepare("SELECT id, email, tipo_conta, data_criacao FROM usuarios ORDER BY data_criacao DESC");
$stmt->execute();
$usuarios = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Usuários - Gestão de Eventos</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* 4.1.1 Estilos inline para formulários ocultos */
        .admin { color: #0056b3; font-weight: bold; }
        .normal { color: #6c757d; }
        #adicionar-form, #redefinir-form {
            display: none;
            margin-top: 20px;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: #f9f9f9;
        }
    </style>
</head>

<body>
    <!-- 5.1 Inclusão do cabeçalho -->
    <?php include '../includes/header.php'; ?>

    <!-- 5.2 Container principal -->
    <div class="container">
        <h1>Gerenciar Usuários</h1>

        <!-- 5.2.1 Exibição de mensagens -->
        <?php if (!empty($mensagem)): ?>
            <div class="alert alert-info"><?php echo $mensagem; ?></div>
        <?php endif; ?>

        <!-- 5.2.2 Botão para adicionar usuário -->
        <div class="actions">
            <button id="mostrar-adicionar" class="btn btn-primary">Adicionar Novo Usuário</button>
        </div>

        <!-- 5.2.3 Formulário de adição de usuário (oculto) -->
        <div id="adicionar-form">
            <h3>Adicionar Novo Usuário</h3>
            <form method="post" action="">
                <div class="form-group">
                    <label for="email">E-mail:</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <div class="form-group">
                    <label for="senha">Senha:</label>
                    <input type="password" id="senha" name="senha" required>
                    <small>A senha deve ter pelo menos 6 caracteres.</small>
                </div>
                <div class="form-group">
                    <label for="tipo_conta">Tipo de Conta:</label>
                    <select id="tipo_conta" name="tipo_conta">
                        <option value="normal">Normal</option>
                        <option value="admin">Administrador</option>
                    </select>
                </div>
                <button type="submit" name="adicionar" class="btn btn-primary">Adicionar</button>
                <button type="button" id="cancelar-adicionar" class="btn btn-secondary">Cancelar</button>
            </form>
        </div>

        <!-- 5.2.4 Formulário de redefinição de senha (oculto) -->
        <div id="redefinir-form">
            <h3>Redefinir Senha</h3>
            <form method="post" action="">
                <input type="hidden" id="redefinir_usuario_id" name="usuario_id">
                <div class="form-group">
                    <label for="usuario_email">Usuário:</label>
                    <div id="usuario_email"></div>
                </div>
                <div class="form-group">
                    <label for="nova_senha">Nova Senha:</label>
                    <input type="password" id="nova_senha" name="nova_senha" required>
                    <small>A senha deve ter pelo menos 6 caracteres.</small>
                </div>
                <button type="submit" name="redefinir_senha" class="btn btn-primary">Redefinir</button>
                <button type="button" id="cancelar-redefinir" class="btn btn-secondary">Cancelar</button>
            </form>
        </div>

        <!-- 5.2.5 Lista de usuários -->
        <h2>Lista de Usuários</h2>
        <?php if (count($usuarios) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>E-mail</th>
                        <th>Tipo de Conta</th>
                        <th>Data de Criação</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usuarios as $usuario): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($usuario['email']); ?></td>
                            <td class="<?php echo $usuario['tipo_conta']; ?>">
                                <?php echo ucfirst($usuario['tipo_conta']); ?>
                            </td>
                            <td><?php echo date('d/m/Y H:i', strtotime($usuario['data_criacao'])); ?></td>
                            <td>
                                <?php if ($usuario['tipo_conta'] == 'normal'): ?>
                                    <form method="post" action="" style="display: inline;">
                                        <input type="hidden" name="usuario_id" value="<?php echo $usuario['id']; ?>">
                                        <button type="submit" name="promover" class="btn btn-sm">Promover a Admin</button>
                                    </form>
                                <?php elseif ($usuario['id'] != $_SESSION['usuario_id']): ?>
                                    <form method="post" action="" style="display: inline;">
                                        <input type="hidden" name="usuario_id" value="<?php echo $usuario['id']; ?>">
                                        <button type="submit" name="rebaixar" class="btn btn-sm">Rebaixar para Normal</button>
                                    </form>
                                <?php endif; ?>
                                <button class="btn btn-sm redefinir-senha" 
                                        data-id="<?php echo $usuario['id']; ?>" 
                                        data-email="<?php echo htmlspecialchars($usuario['email']); ?>">
                                    Redefinir Senha
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>Nenhum usuário encontrado.</p>
        <?php endif; ?>
    </div>
	
	<script>
	    // 6.1 Controle de exibição do formulário de adição
	    document.getElementById('mostrar-adicionar').addEventListener('click', function() {
	        document.getElementById('adicionar-form').style.display = 'block';
	        document.getElementById('redefinir-form').style.display = 'none';
	    });
	    document.getElementById('cancelar-adicionar').addEventListener('click', function() {
	        document.getElementById('adicionar-form').style.display = 'none';
	    });

	    // 6.2 Controle de exibição do formulário de redefinição
	    document.querySelectorAll('.redefinir-senha').forEach(function(element) {
	        element.addEventListener('click', function() {
	            const id = this.getAttribute('data-id');
	            const email = this.getAttribute('data-email');
	            document.getElementById('redefinir_usuario_id').value = id;
	            document.getElementById('usuario_email').textContent = email;
	            document.getElementById('redefinir-form').style.display = 'block';
	            document.getElementById('adicionar-form').style.display = 'none';
	            document.getElementById('redefinir-form').scrollIntoView({behavior: 'smooth'});
	        });
	    });
	    document.getElementById('cancelar-redefinir').addEventListener('click', function() {
	        document.getElementById('redefinir-form').style.display = 'none';
	    });
	</script>
		
    <!-- 7.1 Inclusão do rodapé -->
    <?php include '../includes/footer.php'; ?>
</body>
</html>
