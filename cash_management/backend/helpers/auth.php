<?php
// ============================================================
// helpers/auth.php
// Funzioni per la gestione dell'autenticazione tramite Bearer Token.
// Ogni richiesta autenticata deve includere l'header:
//   Authorization: Bearer <token>
// ============================================================

// Include la configurazione del database (necessaria per le query sui token)
require_once __DIR__ . '/../config/database.php';

// ============================================================
// Funzione: generateToken()
// Genera un token di autenticazione casuale e sicuro.
// Restituisce una stringa esadecimale da 64 caratteri (256 bit).
// ============================================================
function generateToken(): string {
    // random_bytes(32) genera 32 byte casuali crittograficamente sicuri
    // bin2hex() li converte in una stringa esadecimale di 64 caratteri
    return bin2hex(random_bytes(32));
}

// ============================================================
// Funzione: authenticate()
// Verifica che la richiesta HTTP corrente abbia un token Bearer valido.
// Se il token è valido, restituisce i dati dell'utente autenticato.
// Se non valido o assente, invia un errore 401 e termina l'esecuzione.
// ============================================================
function authenticate(): array {
    // Recupera tutti gli header HTTP della richiesta corrente
    $headers = getallheaders();

    // Legge l'header Authorization, controllando sia la versione normale che quella in minuscolo
    // (alcuni server web passano gli header in minuscolo)
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    // Verifica che l'header inizi con "Bearer " (spazio incluso)
    if (!str_starts_with($authHeader, 'Bearer ')) {
        // Header mancante o formato errato: invia errore 401 Unauthorized
        sendError('Token mancante. Esegui il login.', 401);
    }

    // Estrae il token rimuovendo il prefisso "Bearer " (7 caratteri)
    $token = substr($authHeader, 7);

    // Ottiene la connessione al database
    $pdo = getDBConnection();

    // Prepara la query che cerca il token nel database
    // Controlla anche che il token non sia scaduto (expires_at > NOW())
    // Fa JOIN con la tabella users per recuperare i dati dell'utente
    $stmt = $pdo->prepare("
        SELECT t.user_id, t.expires_at, u.username, u.email
        FROM tokens t
        JOIN users u ON t.user_id = u.id
        WHERE t.token = ? AND t.expires_at > NOW()
    ");

    // Esegue la query passando il token come parametro (sicuro, niente SQL injection)
    $stmt->execute([$token]);

    // Recupera il risultato della query
    $result = $stmt->fetch();

    // Se non trova nessun risultato, il token non esiste o è scaduto
    if (!$result) {
        // Invia errore 401 Unauthorized e termina l'esecuzione
        sendError('Token non valido o scaduto. Esegui di nuovo il login.', 401);
    }

    // Token valido: restituisce l'array con i dati dell'utente (user_id, username, email, expires_at)
    return $result;
}

// ============================================================
// Funzione: revokeExpiredTokens()
// Elimina dal database tutti i token scaduti.
// Viene chiamata ad ogni login per mantenere la tabella pulita.
// ============================================================
function revokeExpiredTokens(): void {
    // Ottiene la connessione al database
    $pdo = getDBConnection();

    // Esegue direttamente la query di eliminazione dei token con data di scadenza nel passato
    $pdo->exec("DELETE FROM tokens WHERE expires_at <= NOW()");
}