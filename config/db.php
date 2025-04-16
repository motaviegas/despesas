<?php
$host = '10.1.1.9';
$db_name = 'gestao_eventos';
$username = 'fatura'; // Altere conforme seu ambiente
$password = 'PdFs1974!'; // Altere conforme seu ambiente
// Adicione isso ao db.php
$base_url = '/gestao-eventos';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Erro de conexão: " . $e->getMessage());
}
?>
