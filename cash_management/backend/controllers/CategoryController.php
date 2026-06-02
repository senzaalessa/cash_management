<?php
// ============================================================
// controllers/CategoryController.php
// Gestisce le categorie delle SPESE.
// Le categorie sono predefinite e condivise tra tutti gli utenti.
// In questa versione è disponibile solo la lettura (GET).
// ============================================================

// Carica la configurazione del database
require_once __DIR__ . '/../config/database.php';

// Carica le funzioni per le risposte JSON
require_once __DIR__ . '/../helpers/response.php';

// Carica le funzioni per l'autenticazione
require_once __DIR__ . '/../helpers/auth.php';

class CategoryController {

    // ============================================================
    // GET /categories
    // Restituisce la lista di tutte le categorie di spesa.
    // Richiede autenticazione (l'utente deve essere loggato).
    // Le categorie sono condivise tra tutti gli utenti del sistema.
    // ============================================================
    public function index(): void {
        // Verifica che l'utente sia autenticato (lancia 401 se il token non è valido)
        authenticate();

        // Ottiene la connessione al database
        $pdo  = getDBConnection();

        // Recupera tutte le categorie ordinate alfabeticamente per nome
        $stmt = $pdo->query("SELECT id, name, icon, color FROM categories ORDER BY name");

        // Trasforma ogni riga del risultato in un array con i tipi corretti
        $cats = array_map(fn($c) => [
            'id'    => (int)$c['id'], // Cast a intero (il DB restituisce stringhe)
            'name'  => $c['name'],    // Nome della categoria (es. "Alimentari")
            'icon'  => $c['icon'],    // Emoji icona (es. "🛒")
            'color' => $c['color'],   // Colore esadecimale (es. "#4CAF50")
        ], $stmt->fetchAll());

        // Invia la lista delle categorie come risposta JSON
        sendSuccess($cats);
    }
}