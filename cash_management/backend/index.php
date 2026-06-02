<?php
// ============================================================
// index.php — Router principale dell'API REST
// Questo è il punto di ingresso unico di tutta l'applicazione.
// Ogni richiesta HTTP passa da qui grazie al file .htaccess.
// Si occupa di: impostare gli header, analizzare l'URL,
// e instradare la richiesta al controller corretto.
// ============================================================

// Imposta il tipo di contenuto della risposta come JSON con codifica UTF-8
header('Content-Type: application/json; charset=utf-8');

// Permette richieste da qualsiasi origine (necessario per le API pubbliche e frontend separati)
header('Access-Control-Allow-Origin: *');

// Elenca i metodi HTTP accettati dall'API
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

// Elenca gli header HTTP che il client può inviare
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Gestione delle richieste preflight CORS (OPTIONS):
// I browser moderni inviano una richiesta OPTIONS prima di quella reale
// per verificare se il server accetta la richiesta cross-origin
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); // 204 = No Content (risposta vuota, tutto ok)
    exit; // Termina senza fare altro
}

// Carica le funzioni helper per le risposte JSON (sendSuccess, sendError, ecc.)
require_once __DIR__ . '/helpers/response.php';

// Carica le funzioni helper per l'autenticazione (authenticate, generateToken, ecc.)
require_once __DIR__ . '/helpers/auth.php';

// Carica la configurazione e la funzione di connessione al database
require_once __DIR__ . '/config/database.php';

// ============================================================
// PARSING DELL'URL
// Estrae la risorsa e l'ID dall'URL della richiesta
// Esempi:
//   /cash_management/expenses       → resource="expenses", id=null
//   /cash_management/expenses/42    → resource="expenses", id="42"
//   /cash_management/auth/login     → resource="auth",     id="login"
// ============================================================

// Estrae solo il percorso dall'URL (esclude query string e fragment)
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Percorso base della cartella del progetto in htdocs
$basePath = '/cash_management';

// Rimuove il percorso base dall'URL e toglie gli slash iniziali/finali
$path = trim(str_replace($basePath, '', $requestUri), '/');

// Divide il percorso in segmenti separati dallo slash
// Es: "expenses/42" → ["expenses", "42"]
$segments = explode('/', $path);

// Recupera il metodo HTTP della richiesta corrente (GET, POST, PUT, DELETE)
$method = $_SERVER['REQUEST_METHOD'];

// Il primo segmento è la risorsa (expenses, incomes, auth, ecc.)
$resource = $segments[0] ?? '';

// Il secondo segmento è l'ID o il sotto-endpoint (42, "login", "register", ecc.)
$id = $segments[1] ?? null;

// ============================================================
// ROUTING
// In base alla risorsa richiesta, carica il controller
// appropriato e chiama il metodo corrispondente all'azione
// ============================================================
switch ($resource) {

    // AUTENTICAZIONE (/auth/...)
    case 'auth':
        // Carica il controller responsabile di login, registrazione e logout
        require_once __DIR__ . '/controllers/AuthController.php';
        $controller = new AuthController();

        // Abbina la combinazione sotto-endpoint + metodo HTTP alla funzione del controller
        match (true) {
            $id === 'register' && $method === 'POST' => $controller->register(), // Registrazione nuovo utente
            $id === 'login'    && $method === 'POST' => $controller->login(),    // Login con email e password
            $id === 'logout'   && $method === 'POST' => $controller->logout(),   // Logout (invalida il token)
            $id === 'me'       && $method === 'GET'  => $controller->me(),       // Profilo dell'utente autenticato
            default => sendError('Endpoint auth non trovato', 404),              // Endpoint non riconosciuto
        };
        break;

    // SPESE (/expenses/...)
    case 'expenses':
        // Carica il controller responsabile della gestione delle spese
        require_once __DIR__ . '/controllers/ExpenseController.php';
        $controller = new ExpenseController();

        // Abbina la combinazione ID + metodo HTTP all'azione CRUD corrispondente
        match (true) {
            $id === null && $method === 'GET'    => $controller->index(),        // Lista tutte le spese (con filtri)
            $id === null && $method === 'POST'   => $controller->store(),        // Crea una nuova spesa
            $id !== null && $method === 'GET'    => $controller->show($id),      // Mostra una spesa specifica per ID
            $id !== null && $method === 'PUT'    => $controller->update($id),    // Aggiorna una spesa esistente per ID
            $id !== null && $method === 'DELETE' => $controller->destroy($id),   // Elimina una spesa per ID
            default => sendError('Endpoint spese non trovato', 404),             // Combinazione non riconosciuta
        };
        break;

    // ENTRATE (/incomes/...)
    case 'incomes':
        // Carica il controller responsabile della gestione delle entrate
        require_once __DIR__ . '/controllers/IncomeController.php';
        $controller = new IncomeController();

        // Stessa struttura CRUD delle spese, ma per le entrate
        match (true) {
            $id === null && $method === 'GET'    => $controller->index(),        // Lista tutte le entrate
            $id === null && $method === 'POST'   => $controller->store(),        // Crea una nuova entrata
            $id !== null && $method === 'GET'    => $controller->show($id),      // Mostra una entrata per ID
            $id !== null && $method === 'PUT'    => $controller->update($id),    // Aggiorna una entrata per ID
            $id !== null && $method === 'DELETE' => $controller->destroy($id),   // Elimina una entrata per ID
            default => sendError('Endpoint entrate non trovato', 404),
        };
        break;

    // CATEGORIE ENTRATE (/income-categories)
    case 'income-categories':
        // Carica il controller delle entrate (contiene anche il metodo categories())
        require_once __DIR__ . '/controllers/IncomeController.php';
        $controller = new IncomeController();

        match (true) {
            $id === null && $method === 'GET' => $controller->categories(), // Lista le categorie di entrata
            default => sendError('Endpoint non trovato', 404),
        };
        break;

    // CATEGORIE SPESE (/categories)
    case 'categories':
        // Carica il controller responsabile delle categorie di spesa
        require_once __DIR__ . '/controllers/CategoryController.php';
        $controller = new CategoryController();

        match (true) {
            $id === null && $method === 'GET' => $controller->index(), // Lista tutte le categorie di spesa
            default => sendError('Endpoint categorie non trovato', 404),
        };
        break;

    // STATISTICHE (/stats/...)
    case 'stats':
        // Carica il controller responsabile di statistiche e grafici
        require_once __DIR__ . '/controllers/StatsController.php';
        $controller = new StatsController();

        match (true) {
            $id === 'summary'  && $method === 'GET' => $controller->summary(),    // Riepilogo mensile e annuale
            $id === 'monthly'  && $method === 'GET' => $controller->monthly(),    // Dati mensili entrate/spese/saldo
            $id === 'category' && $method === 'GET' => $controller->byCategory(), // Totali per categoria
            $id === 'years'    && $method === 'GET' => $controller->years(),       // Lista anni con dati disponibili
            default => sendError('Endpoint statistiche non trovato', 404),
        };
        break;

    // RISORSA NON TROVATA
    default:
        // Nessuna risorsa corrisponde all'URL richiesto: invia errore 404
        sendError('Risorsa non trovata. Endpoints: /auth, /expenses, /incomes, /income-categories, /categories, /stats', 404);
}