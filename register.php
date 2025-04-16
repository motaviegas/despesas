<?php
session_start();
require_once 'config/db.php';

$erro = '';
$sucesso = '';

// Verificar se o usuário já está logado
if (isset($_SESSION['usuario_id'])) {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    $senha = $_POST['senha'];
    $confirmar_senha = $_POST['confirmar_senha'];
    
    // Validações básicas
    if (empty($email) || empty($senha) || empty($confirmar_senha)) {
        $erro = "Por favor, preencha todos os campos.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = "Por favor, informe um e-mail válido.";
    } elseif ($senha !== $confirmar_senha) {
        $erro = "As senhas não correspondem.";
    } elseif (strlen($senha) < 6) {
        $erro = "A senha deve ter pelo menos 6 caracteres.";
    } else {
        // Verificar se o e-mail já está em uso
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = :email");
        $stmt->bindParam(':email', $email, PDO::PARAM_STR);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $erro = "Este e-mail já está em uso.";
        } else {
            // Criptografar a senha e cadastrar o usuário
            $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
            $tipo_conta = 'normal'; // Por padrão, usuários registrados são normais
            
            try {
                $stmt = $pdo->prepare("INSERT INTO usuarios (email, senha, tipo_conta) VALUES (:email, :senha, :tipo_conta)");
                $stmt->bindParam(':email', $email, PDO::PARAM_STR);
                $stmt->bindParam(':senha', $senha_hash, PDO::PARAM_STR);
                $stmt->bindParam(':tipo_conta', $tipo_conta, PDO::PARAM_STR);
                $stmt->execute();
                
                $sucesso = "Cadastro realizado com sucesso! Você já pode fazer login.";
                
                // Opcional: Redirecionar para a página de login após um breve intervalo
                // header("refresh:3;url=login.php");
            } catch (PDOException $e) {
                $erro = "Erro ao cadastrar: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro - Gestão de Eventos</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="container">
        <h1>Cadastro</h1>
        
        <?php if (!empty($erro)): ?>
            <div class="alert alert-danger"><?php echo $erro; ?></div>
        <?php endif; ?>
        
        <?php if (!empty($sucesso)): ?>
            <div class="alert alert-info"><?php echo $sucesso; ?></div>
        <?php endif; ?>
        
        <form method="post" action="">
            <div class="form-group">
                <label for="email">E-mail:</label>
                <input type="email" id="email" name="email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
            </div>
            
            <div class="form-group">
                <label for="senha">Senha:</label>
                <input type="password" id="senha" name="senha" required>
                <small>A senha deve ter pelo menos 6 caracteres.</small>
            </div>
            
            <div class="form-group">
                <label for="confirmar_senha">Confirmar Senha:</label>
                <input type="password" id="confirmar_senha" name="confirmar_senha" required>
            </div>
            
            <button type="submit" class="btn btn-primary">Cadastrar</button>
        </form>
        
        <p>Já tem uma conta? <a href="login.php">Faça login</a></p>
    </div>
</body>
</html>