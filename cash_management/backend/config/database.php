<?php
// ============================================================
// config/database.php
// Configurazione della connessione al database MySQL
// Questo file viene incluso da tutti i controller
// ============================================================

// Indirizzo del server MySQL (localhost = stesso computer del server web)
define('DB_HOST', 'localhost');

// Nome utente MySQL per la connessione
define('DB_USER', 'root');

// Password MySQL per la connessione (vuoto se non impostata)
define('DB_PASS', '');

// Nome del database da utilizzare
define('DB_NAME', 'cash_management');

// Durata in ore dei token di autenticazione prima che scadano
define('TOKEN_EXPIRY_HOURS', 24);

// ============================================================
// Funzione: getDBConnection()
// Restituisce un'istanza PDO connessa al database.
// Usa il pattern Singleton: crea la connessione solo la
// prima volta, poi riutilizza sempre la stessa istanza.
// ============================================================
function getDBConnection(): PDO {
    // Variabile statica: mantiene il valore tra le chiamate alla funzione
    static $pdo = null;

    // Se la connessione non esiste ancora, la crea
    if ($pdo === null) {
        try {
            // Crea la connessione PDO con i parametri configurati sopra
            $pdo = new PDO(
                // Stringa DSN: specifica driver, host, nome DB e charset
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,   // Nome utente MySQL
                DB_PASS,   // Password MySQL
                [
                    // Lancia eccezioni in caso di errori SQL (invece di restituire false silenziosamente)
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    // Restituisce i risultati come array associativi (es. $row['nome']) invece che numerici
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // Disabilita l'emulazione dei prepared statement per maggiore sicurezza anti SQL injection
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            // In caso di errore di connessione, restituisce un errore JSON e termina l'esecuzione
            http_response_code(500); // Codice HTTP 500 = Internal Server Error
            echo json_encode(['success' => false, 'message' => 'Errore di connessione al database']);
            exit; // Interrompe l'esecuzione dello script
        }
    }

    // Restituisce la connessione (già esistente o appena creata)
    return $pdo;
}