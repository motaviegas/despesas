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
$login_attempted = false;

// 3.0 LOGIN PROCESSING
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $login_attempted = true;
    
    // 3.1 INPUT SANITIZATION
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $senha = $_POST['senha']; // Password will be verified, not stored
    
    // 3.2 INPUT VALIDATION
    if (empty($email) || empty($senha)) {
        $error = "Please fill in all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } else {
        // 3.3 DATABASE QUERY
        try {
            $stmt = $pdo->prepare("SELECT id, email, senha, tipo_conta, ativo FROM usuarios WHERE email = :email");
            $stmt->bindParam(':email', $email, PDO::PARAM_STR);
            $stmt->execute();
            
            // 3.4 USER AUTHENTICATION
            if ($usuario = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // 3.4.1 ACCOUNT STATUS CHECK
                if (!$usuario['ativo']) {
                    $error = "Account not activated. Please check your email.";
                }
                // 3.4.2 PASSWORD VERIFICATION
                elseif (password_verify($senha, $usuario['senha'])) {
                    // 3.4.3 SESSION CREATION
                    $_SESSION['usuario_id'] = $usuario['id'];
                    $_SESSION['email'] = $usuario['email'];
                    $_SESSION['tipo_conta'] = $usuario['tipo_conta'];
                    $_SESSION['logged_in'] = true;
                    $_SESSION['last_activity'] = time();
                    
                    // 3.4.4 SESSION REGENERATION
                    session_regenerate_id(true);
                    
                    // 3.4.5 UPDATE LAST LOGIN
                    $update_stmt = $pdo->prepare("UPDATE usuarios SET ultimo_login = NOW() WHERE id = :id");
                    $update_stmt->bindParam(':id', $usuario['id'], PDO::PARAM_INT);
                    $update_stmt->execute();
                    
                    // 3.4.6 REDIRECTION
                    header('Location: dashboard.php');
                    exit;
                } else {
                    $error = "Invalid credentials.";
                }
            } else {
                $error = "Invalid credentials.";
            }
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            $error = "System error. Please try again later.";
        }
    }
    
    // 3.5 FAILED LOGIN HANDLING
    if (!empty($error) {
        error_log("Failed login attempt for email: " . $email);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- 4.0 PAGE METADATA -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Event Management System Login">
    
    <!-- 4.1 SECURITY META TAGS -->
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:;">
    
    <!-- 4.2 TITLE AND LINKS -->
    <title>Login - Event Management System</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <!-- 5.0 MAIN CONTAINER -->
    <div class="container">
        <!-- 5.1 HEADER SECTION -->
        <h1>Login</h1>
        
        <!-- 5.2 ERROR DISPLAY -->
        <?php if ($login_attempted && !empty($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        
        <!-- 5.3 LOGIN FORM -->
        <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
            <!-- 5.3.1 EMAIL FIELD -->
            <div class="form-group">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" required 
                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email'], ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
            
            <!-- 5.3.2 PASSWORD FIELD -->
            <div class="form-group">
                <label for="senha">Password:</label>
                <input type="password" id="senha" name="senha" required minlength="8">
            </div>
            
            <!-- 5.3.3 SUBMIT BUTTON -->
            <button type="submit" class="btn btn-primary">Login</button>
        </form>
        
        <!-- 5.4 REGISTRATION LINK -->
        <p>Don't have an account? <a href="register.php">Register here</a></p>
        
        <!-- 5.5 PASSWORD RECOVERY LINK -->
        <p><a href="forgot_password.php">Forgot your password?</a></p>
    </div>
</body>
</html>