<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

date_default_timezone_set('America/Bogota');

class Database {

    private $hostname = "localhost";
    private $database = "biblioteca";
    private $username = "root";
    private $password = "";
    private $charset = "utf8mb4"; 

    function conectar()
    {
        try {
            

            $dsn = "mysql:host={$this->hostname};dbname={$this->database};charset={$this->charset}";

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ];

            $pdo = new PDO($dsn, $this->username, $this->password, $options);
            return $pdo;
            
        } catch(PDOException $e) {
            // Mostrar error detallado en desarrollo
            echo '<strong>Error de Conexión:</strong> ' . $e->getMessage();
            echo '<br><strong>Código:</strong> ' . $e->getCode();
            // En producción: registrar en log en lugar de mostrar
            // error_log($e->getMessage());
            exit;
        }
    }
}
?>