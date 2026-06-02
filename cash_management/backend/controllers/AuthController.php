<?php
// ============================================================
// controllers/AuthController.php
// Gestisce la registrazione, il login, il logout e il profilo utente.
// Tutti gli endpoint di autenticazione passano da questa classe.
// ============================================================

// Carica la configurazione del database e la funzione di connessione
require_once __DIR__ . '/../config/database.php';

// Carica le funzioni per le risposte JSON standardizzate
require_once __DIR__ . '/../helpers/response.php';

// Carica le funzioni per la gestione dei token di autenticazione
require_once __DIR__ . '/../helpers/auth.php';

class AuthController {

    // ============================================================
    // POST /auth/register
    // Registra un nuovo utente nel sistema.
    // Richiede: username, email, password nel corpo JSON.
    // Restituisce: token di autenticazione e dati utente.
    // ============================================================
    public function register(): void {
        // Legge e decodifica il corpo JSON della richiesta
        $data = getRequestBody();

        // Verifica che tutti i campi obbligatori siano presenti e non vuoti
        $missing = validateRequired($data, ['username', 'email', 'password']);

        // Se mancano campi, invia errore 422 con l'elenco dei campi mancanti
        if (!empty($missing)) {
            sendError('Campi obbligatori mancanti: ' . implode(', ', $missing), 422);
        }

        // Rimuove spazi iniziali e finali da username ed email
        $username = trim($data['username']);
        $email    = trim($data['email']);
        $password = $data['password']; // La password non viene trimmata per non alterarla

        // Verifica che lo username abbia una lunghezza accettabile (tra 3 e 50 caratteri)
        if (strlen($username) < 3 || strlen($username) > 50) {
            sendError('Username deve essere tra 3 e 50 caratteri', 422);
        }

        // Verifica che l'email abbia un formato valido (es. mario@example.com)
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendError('Email non valida', 422);
        }

        // Verifica che la password abbia almeno 6 caratteri (sicurezza minima)
        if (strlen($password) < 6) {
            sendError('La password deve avere almeno 6 caratteri', 422);
        }

        // Ottiene la connessione al database
        $pdo = getDBConnection();

        // Controlla se esiste già un utente con la stessa email o lo stesso username
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? OR username = ?");
        $stmt->execute([$email, $username]);

        // Se trova un risultato, email o username sono già in uso
        if ($stmt->fetch()) {
            sendError('Email o username già in uso', 409); // 409 = Conflict
        }

        // Genera l'hash sicuro della password usando bcrypt (algoritmo moderno e sicuro)
        // La password originale non viene mai salvata nel database
        $hash = password_hash($password, PASSWORD_BCRYPT);

        // Inserisce il nuovo utente nel database con l'hash della password
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
        $stmt->execute([$username, $email, $hash]);

        // Recupera l'ID auto-generato dell'utente appena inserito
        $userId = $pdo->lastInsertId();

        // Genera un token di autenticazione casuale sicuro
        $token = generateToken();

        // Calcola la data di scadenza del token (ora corrente + ore definite nella configurazione)
        $expiresAt = date('Y-m-d H:i:s', time() + TOKEN_EXPIRY_HOURS * 3600);

        // Salva il token nel database associato all'utente appena creato
        $stmt = $pdo->prepare("INSERT INTO tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $token, $expiresAt]);

        // Invia la risposta di successo con il token e i dati utente
        // Codice 201 = Created (risorsa creata con successo)
        sendSuccess([
            'token'      => $token,      // Token da usare nelle richieste future
            'expires_at' => $expiresAt,  // Data di scadenza del token
            'user'       => [
                'id'       => (int)$userId,  // Cast a intero per compatibilità JSON
                'username' => $username,
                'email'    => $email,
            ]
        ], 'Registrazione completata con successo', 201);
    }

    // ============================================================
    // POST /auth/login
    // Autentica un utente esistente con email e password.
    // Restituisce un nuovo token di autenticazione.
    // ============================================================
    public function login(): void {
        // Legge i dati JSON dalla richiesta
        $data = getRequestBody();

        // Verifica che email e password siano presenti
        $missing = validateRequired($data, ['email', 'password']);
        if (!empty($missing)) {
            sendError('Email e password sono obbligatori', 422);
        }

        // Ottiene la connessione al database
        $pdo = getDBConnection();

        // Cerca l'utente per email (la email viene trimmata per sicurezza)
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([trim($data['email'])]);
        $user = $stmt->fetch(); // Recupera tutti i dati dell'utente

        // Verifica che l'utente esista e che la password corrisponda all'hash salvato
        // password_verify() confronta in modo sicuro la password con l'hash bcrypt
        if (!$user || !password_verify($data['password'], $user['password_hash'])) {
            // Messaggio generico per non rivelare se l'email esiste o meno (sicurezza)
            sendError('Credenziali non valide', 401);
        }

        // Pulisce i token scaduti dal database per mantenere la tabella in ordine
        revokeExpiredTokens();

        // Genera un nuovo token di autenticazione per questa sessione
        $token = generateToken();

        // Calcola la data di scadenza del nuovo token
        $expiresAt = date('Y-m-d H:i:s', time() + TOKEN_EXPIRY_HOURS * 3600);

        // Salva il nuovo token nel database associato all'utente
        $stmt = $pdo->prepare("INSERT INTO tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$user['id'], $token, $expiresAt]);

        // Invia la risposta di successo con il token e i dati utente
        sendSuccess([
            'token'      => $token,
            'expires_at' => $expiresAt,
            'user'       => [
                'id'       => (int)$user['id'],
                'username' => $user['username'],
                'email'    => $user['email'],
            ]
        ], 'Login effettuato con successo');
    }

    // ============================================================
    // POST /auth/logout
    // Invalida il token dell'utente corrente.
    // Dopo il logout, il token non sarà più accettato.
    // ============================================================
    public function logout(): void {
        // Recupera tutti gli header HTTP della richiesta
        $headers    = getallheaders();

        // Legge l'header Authorization (controlla sia maiuscolo che minuscolo)
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        // Procede solo se l'header è nel formato corretto "Bearer <token>"
        if (str_starts_with($authHeader, 'Bearer ')) {
            // Estrae il token rimuovendo il prefisso "Bearer "
            $token = substr($authHeader, 7);

            // Ottiene la connessione al database
            $pdo  = getDBConnection();

            // Elimina il token dal database, rendendolo immediatamente non valido
            $stmt = $pdo->prepare("DELETE FROM tokens WHERE token = ?");
            $stmt->execute([$token]);
        }

        // Invia sempre una risposta di successo (anche se il token non c'era)
        sendSuccess(null, 'Logout effettuato con successo');
    }

    // ============================================================
    // GET /auth/me
    // Restituisce i dati dell'utente attualmente autenticato.
    // Richiede un token Bearer valido nell'header Authorization.
    // ============================================================
    public function me(): void {
        // Verifica il token e recupera i dati dell'utente (termina con errore 401 se non valido)
        $user = authenticate();

        // Invia i dati dell'utente autenticato come risposta
        sendSuccess([
            'id'       => (int)$user['user_id'], // ID numerico dell'utente
            'username' => $user['username'],
            'email'    => $user['email'],
        ]);
    }
}