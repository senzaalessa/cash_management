<?php
// ============================================================
// controllers/IncomeCategoryController.php
// Gestisce le categorie delle ENTRATE.
// Le categorie sono predefinite e condivise tra tutti gli utenti.
// In questa versione è disponibile solo la lettura (GET).
// ============================================================

// Carica la configurazione del database e la funzione di connessione
require_once __DIR__ . '/../config/database.php';

// Carica le funzioni per le risposte JSON standardizzate (sendSuccess, sendError, ecc.)
require_once __DIR__ . '/../helpers/response.php';

// Carica le funzioni per l'autenticazione (authenticate, generateToken, ecc.)
require_once __DIR__ . '/../helpers/auth.php';

class IncomeCategoryController {

    // ============================================================
    // GET /income-categories
    // Restituisce la lista di tutte le categorie di entrata.
    // Richiede autenticazione (l'utente deve essere loggato).
    // Le categorie sono condivise tra tutti gli utenti del sistema.
    // ============================================================
    public function index(): void {

        // Verifica che l'utente sia autenticato (lancia errore 401 se il token non è valido)
        authenticate();

        // Ottiene la connessione al database (singleton: non crea una nuova connessione)
        $pdo  = getDBConnection();

        // Esegue la query per recuperare tutte le categorie di entrata ordinate alfabeticamente
        $stmt = $pdo->query("SELECT id, name, icon, color FROM income_categories ORDER BY name");

        // Trasforma ogni riga del risultato in un array con i tipi corretti
        $cats = array_map(fn($c) => [
            'id'    => (int)$c['id'], // Cast a intero (il database restituisce stringhe)
            'name'  => $c['name'],    // Nome della categoria (es. "Stipendio")
            'icon'  => $c['icon'],    // Emoji icona (es. "💼")
            'color' => $c['color'],   // Colore esadecimale (es. "#5cc9a0")
        ], $stmt->fetchAll()); // fetchAll() recupera tutte le righe come array

        // Invia la lista delle categorie di entrata come risposta JSON
        sendSuccess($cats);
    }
}