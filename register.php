<?php
// 1.0 INITIALIZATION
session_start();
require_once 'config/db.php';

// 1.1 SECURITY HEADERS
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");

// 2.0 VARIABLE DECLARATIONS
$error = '';
$success = '';
$registration_attempted = false;

// 3.0 SESSION CHECK
if (isset($_SESSION['usuario_id'])) {
    header('Location: dashboard.php');
    exit;
}

// 4.0 REGISTRATION PROCESSING
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $registration_attempted = true;
    
    // 4.1 INPUT SANITIZATION
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $senha = $_POST['senha'];
    $confirmar_senha = $_POST['confirmar_senha'];
    
    // 4.2 INPUT VALIDATION
    if (empty($email) || empty($senha) || empty($confirmar_senha)) {
        $error = "Please fill in all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif ($senha !== $confirmar_senha) {
        $error = "Passwords do not match.";
    } elseif (strlen($senha) < 8) {
        $error = "Password must be at least 8 characters long.";
    } elseif (!preg_match('/[A-Z]/', $senha) || !preg_match('/[0-9]/', $senha)) {
        $error = "Password must contain at least one uppercase letter and one number.";
    } else {
        // 4.3 DATABASE OPERATIONS
        try {
            // 4.3.1 CHECK FOR EXISTING USER
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = :email");
            $stmt->bindParam(':email', $email, PDO::PARAM_STR);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $error = "This email is already registered.";
            } else {
                // 4.3.2 PASSWORD HASHING
                $senha_hash = password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]);
                
                // 4.3.3 ACCOUNT ACTIVATION TOKEN
                $activation_token = bin2hex(random_bytes(32));
                $activation_expiry = date('Y-m-d H:i:s', strtotime('+24 hours'));
                
                // 4.3.4 USER CREATION
                $stmt = $pdo->prepare("INSERT INTO usuarios 
                    (email, senha, tipo_conta, ativo, token_ativacao, token_expira, data_registo) 
                    VALUES (:email, :senha, :tipo_conta, :ativo, :token, :token_expira, NOW())");
                
                $tipo_conta = 'normal';
                $ativo = 0; // Account inactive until email verification
                
                $stmt->bindParam(':email', $email, PDO::PARAM_STR);
                $stmt->bindParam(':senha', $senha_hash, PDO::PARAM_STR);
                $stmt->bindParam(':tipo_conta', $tipo_conta, PDO::PARAM_STR);
                $stmt->bindParam(':ativo', $ativo, PDO::PARAM_INT);
                $stmt->bindParam(':token', $activation_token, PDO::PARAM_STR);
                $stmt->bindParam(':token_expira', $activation_expiry, PDO::PARAM_STR);
                
                if ($stmt->execute()) {
                    // 4.3.5 SEND ACTIVATION EMAIL (placeholder)
                    // sendActivationEmail($email, $activation_token);
                    
                    $success = "Registration successful! Please check your email to activate your account.";
                } else {
                    $error = "Registration failed. Please try again.";
                }
            }
        } catch (PDOException $e) {
            error_log("Registration error: " . $e->getMessage());
            $error = "System error. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- 5.0 PAGE METADATA -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Event Management System Registration">
    
    <!-- 5.1 SECURITY META TAGS -->
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:;">
    
    <!-- 5.2 TITLE AND LINKS -->
    <title>Register - Event Management System</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <!-- 6.0 MAIN CONTAINER -->
    <div class="container">
        <!-- 6.1 HEADER SECTION -->
        <h1>Register</h1>
        
        <!-- 6.2 ERROR DISPLAY -->
        <?php if ($registration_attempted && !empty($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        
        <!-- 6.3 SUCCESS MESSAGE -->
        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        
        <!-- 6.4 REGISTRATION FORM -->
        <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
            <!-- 6.4.1 EMAIL FIELD -->
            <div class="form-group">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" required
                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email'], ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
            
            <!-- 6.4.2 PASSWORD FIELD -->
            <div class="form-group">
                <label for="senha">Password:</label>
                <input type="password" id="senha" name="senha" required minlength="8">
                <small>Must be at least 8 characters with 1 uppercase and 1 number</small>
            </div>
            
            <!-- 6.4.3 PASSWORD CONFIRMATION -->
            <div class="form-group">
                <label for="confirmar_senha">Confirm Password:</label>
                <input type="password" id="confirmar_senha" name="confirmar_senha" required minlength="8">
            </div>
            
            <!-- 6.4.4 SUBMIT BUTTON -->
            <button type="submit" class="btn btn-primary">Register</button>
        </form>
        
        <!-- 6.5 LOGIN LINK -->
        <p>Already have an account? <a href="login.php">Login here</a></p>
    </div>
</body>
</html>