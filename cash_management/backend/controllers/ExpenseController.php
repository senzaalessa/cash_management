<?php
// ============================================================
// controllers/ExpenseController.php
// Gestisce le operazioni CRUD sulle spese dell'utente autenticato.
// Ogni utente può vedere, creare, modificare ed eliminare
// solo le proprie spese (isolamento per user_id).
// ============================================================

// Carica la configurazione del database
require_once __DIR__ . '/../config/database.php';

// Carica le funzioni per le risposte JSON
require_once __DIR__ . '/../helpers/response.php';

// Carica le funzioni per l'autenticazione
require_once __DIR__ . '/../helpers/auth.php';

class ExpenseController {

    // ============================================================
    // GET /expenses
    // Restituisce la lista paginata delle spese dell'utente.
    // Parametri query opzionali:
    //   ?month=2025-04      → filtra per mese
    //   ?category_id=1      → filtra per categoria
    //   ?search=pizza       → ricerca nella descrizione
    //   ?page=1             → numero di pagina
    //   ?per_page=20        → risultati per pagina
    // ============================================================
    public function index(): void {
        // Verifica il token e recupera i dati dell'utente autenticato
        $user = authenticate();

        // Ottiene la connessione al database
        $pdo  = getDBConnection();

        // Array delle condizioni WHERE della query SQL
        // Inizia sempre con il filtro per l'utente corrente (sicurezza: ogni utente vede solo le sue spese)
        $where  = ["e.user_id = :user_id"];

        // Array dei parametri da passare alla query preparata
        $params = [':user_id' => $user['user_id']];

        // Aggiunge il filtro per mese se il parametro è presente nell'URL
        if (!empty($_GET['month'])) {
            // DATE_FORMAT estrae l'anno-mese dalla data (es. "2025-04")
            $where[]          = "DATE_FORMAT(e.expense_date, '%Y-%m') = :month";
            $params[':month'] = $_GET['month'];
        }

        // Aggiunge il filtro per categoria se il parametro è presente nell'URL
        if (!empty($_GET['category_id'])) {
            $where[]               = "e.category_id = :category_id";
            $params[':category_id'] = (int)$_GET['category_id']; // Cast a intero per sicurezza
        }

        // Aggiunge la ricerca testuale nella descrizione se il parametro è presente
        if (!empty($_GET['search'])) {
            // LIKE con % permette di trovare il testo ovunque nella stringa
            $where[]           = "e.description LIKE :search";
            $params[':search'] = '%' . $_GET['search'] . '%'; // % = qualsiasi carattere
        }

        // Unisce tutte le condizioni WHERE con AND per formare la clausola completa
        $whereClause = implode(' AND ', $where);

        // Calcola i parametri di paginazione
        $page    = max(1, (int)($_GET['page'] ?? 1));              // Pagina corrente, minimo 1
        $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 20))); // Elementi per pagina, tra 1 e 100
        $offset  = ($page - 1) * $perPage;                         // Quanti record saltare

        // Query per contare il totale dei record (necessario per calcolare il numero di pagine)
        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM expenses e WHERE $whereClause");
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn(); // fetchColumn restituisce solo il primo valore

        // Query principale per recuperare le spese con i dati della categoria (JOIN)
        $sql = "
            SELECT e.id, e.amount, e.description, e.expense_date, e.notes, e.created_at,
                   c.id AS category_id, c.name AS category_name, c.icon AS category_icon, c.color AS category_color
            FROM expenses e
            JOIN categories c ON e.category_id = c.id  -- Aggiunge i dati della categoria alla riga
            WHERE $whereClause
            ORDER BY e.expense_date DESC, e.created_at DESC  -- Prima per data, poi per ora di inserimento
            LIMIT :limit OFFSET :offset                       -- Paginazione: prende solo la fetta richiesta
        ";

        // Prepara la query e lega i parametri uno per uno
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val); // Lega i parametri filtro
        }
        // I parametri di paginazione richiedono il tipo esplicito PDO::PARAM_INT
        $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);

        // Esegue la query
        $stmt->execute();

        // Trasforma ogni riga del risultato nel formato JSON standard tramite formatExpense()
        $expenses = array_map(fn($e) => $this->formatExpense($e), $stmt->fetchAll());

        // Invia la risposta con le spese e le informazioni di paginazione
        sendSuccess([
            'expenses'   => $expenses,
            'pagination' => [
                'total'    => $total,                          // Totale record trovati
                'page'     => $page,                           // Pagina corrente
                'per_page' => $perPage,                        // Record per pagina
                'pages'    => (int)ceil($total / $perPage),    // Numero totale di pagine
            ]
        ]);
    }

    // ============================================================
    // GET /expenses/{id}
    // Restituisce i dettagli di una singola spesa per ID.
    // Verifica che la spesa appartenga all'utente autenticato.
    // ============================================================
    public function show(string $id): void {
        // Verifica il token e recupera i dati dell'utente
        $user    = authenticate();

        // Cerca la spesa nel database, verifica anche che appartenga all'utente
        $expense = $this->findExpense($id, $user['user_id']);

        // Invia i dati della spesa trovata
        sendSuccess($expense);
    }

    // ============================================================
    // POST /expenses
    // Crea una nuova spesa per l'utente autenticato.
    // Richiede nel corpo JSON: amount, description, category_id, expense_date.
    // Restituisce la spesa appena creata.
    // ============================================================
    public function store(): void {
        // Verifica il token e recupera i dati dell'utente
        $user    = authenticate();

        // Legge i dati JSON dalla richiesta
        $data    = getRequestBody();

        // Verifica che tutti i campi obbligatori siano presenti
        $missing = validateRequired($data, ['amount', 'description', 'category_id', 'expense_date']);
        if (!empty($missing)) {
            sendError('Campi obbligatori mancanti: ' . implode(', ', $missing), 422);
        }

        // Valida e converte l'importo: deve essere un numero float positivo
        $amount = filter_var($data['amount'], FILTER_VALIDATE_FLOAT);
        if ($amount === false || $amount <= 0) {
            sendError('L\'importo deve essere un numero positivo', 422);
        }

        // Valida il formato della data: deve essere YYYY-MM-DD (es. 2025-04-15)
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['expense_date'])) {
            sendError('La data deve essere nel formato YYYY-MM-DD', 422);
        }

        // Ottiene la connessione al database
        $pdo  = getDBConnection();

        // Verifica che la categoria_id esista nella tabella categories
        $stmt = $pdo->prepare("SELECT id FROM categories WHERE id = ?");
        $stmt->execute([(int)$data['category_id']]);
        if (!$stmt->fetch()) {
            sendError('Categoria non valida', 422);
        }

        // Inserisce la nuova spesa nel database
        $stmt = $pdo->prepare("
            INSERT INTO expenses (user_id, category_id, amount, description, expense_date, notes)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user['user_id'],                                               // ID dell'utente autenticato
            (int)$data['category_id'],                                      // ID categoria (intero)
            round($amount, 2),                                              // Importo arrotondato a 2 decimali
            trim($data['description']),                                     // Descrizione senza spazi iniziali/finali
            $data['expense_date'],                                          // Data della spesa
            isset($data['notes']) ? trim($data['notes']) : null,            // Note opzionali (null se non presenti)
        ]);

        // Recupera l'ID della spesa appena inserita
        $newId   = $pdo->lastInsertId();

        // Carica i dati completi della spesa appena creata (inclusa la categoria con JOIN)
        $expense = $this->findExpense($newId, $user['user_id']);

        // Invia la risposta con la spesa creata, codice 201 = Created
        sendSuccess($expense, 'Spesa aggiunta con successo', 201);
    }

    // ============================================================
    // PUT /expenses/{id}
    // Aggiorna una spesa esistente (solo i campi inviati vengono modificati).
    // Verifica che la spesa appartenga all'utente autenticato.
    // ============================================================
    public function update(string $id): void {
        // Verifica il token e recupera i dati dell'utente
        $user = authenticate();

        // Verifica che la spesa esista e appartenga all'utente (lancia 404 se non trovata)
        $this->findExpense($id, $user['user_id']);

        // Legge i dati JSON dalla richiesta
        $data = getRequestBody();

        // Ottiene la connessione al database
        $pdo  = getDBConnection();

        // Array dei campi SQL da aggiornare (es. "amount = ?")
        $fields = [];

        // Array dei valori corrispondenti ai campi da aggiornare
        $params = [];

        // Aggiorna l'importo solo se è stato inviato nella richiesta
        if (isset($data['amount'])) {
            $amount = filter_var($data['amount'], FILTER_VALIDATE_FLOAT);
            if ($amount === false || $amount <= 0) sendError('Importo non valido', 422);
            $fields[] = 'amount = ?';         // Aggiunge il campo alla query
            $params[] = round($amount, 2);    // Aggiunge il valore all'array parametri
        }

        // Aggiorna la descrizione solo se è stata inviata
        if (isset($data['description'])) {
            $fields[] = 'description = ?';
            $params[] = trim($data['description']);
        }

        // Aggiorna la categoria solo se è stata inviata (con validazione)
        if (isset($data['category_id'])) {
            // Verifica che la nuova categoria esista
            $stmt = $pdo->prepare("SELECT id FROM categories WHERE id = ?");
            $stmt->execute([(int)$data['category_id']]);
            if (!$stmt->fetch()) sendError('Categoria non valida', 422);
            $fields[] = 'category_id = ?';
            $params[] = (int)$data['category_id'];
        }

        // Aggiorna la data solo se è stata inviata (con validazione formato)
        if (isset($data['expense_date'])) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['expense_date'])) {
                sendError('Data non valida (formato atteso: YYYY-MM-DD)', 422);
            }
            $fields[] = 'expense_date = ?';
            $params[] = $data['expense_date'];
        }

        // Aggiorna le note se la chiave è presente (anche se il valore è null, per permettere di cancellarle)
        if (array_key_exists('notes', $data)) {
            $fields[] = 'notes = ?';
            // Se notes è null viene salvato null (cancella le note), altrimenti la stringa trimmata
            $params[] = $data['notes'] !== null ? trim($data['notes']) : null;
        }

        // Se non ci sono campi da aggiornare, invia un errore
        if (empty($fields)) {
            sendError('Nessun campo da aggiornare', 422);
        }

        // Aggiunge i parametri per la clausola WHERE (id e user_id garantiscono ownership)
        $params[] = (int)$id;
        $params[] = $user['user_id'];

        // Costruisce e esegue la query UPDATE dinamicamente con i soli campi inviati
        $sql = "UPDATE expenses SET " . implode(', ', $fields) . " WHERE id = ? AND user_id = ?";
        $pdo->prepare($sql)->execute($params);

        // Recupera e invia la spesa aggiornata
        $expense = $this->findExpense($id, $user['user_id']);
        sendSuccess($expense, 'Spesa aggiornata con successo');
    }

    // ============================================================
    // DELETE /expenses/{id}
    // Elimina definitivamente una spesa per ID.
    // Verifica che la spesa appartenga all'utente autenticato.
    // ============================================================
    public function destroy(string $id): void {
        // Verifica il token e recupera i dati dell'utente
        $user = authenticate();

        // Verifica che la spesa esista e appartenga all'utente
        $this->findExpense($id, $user['user_id']);

        // Ottiene la connessione al database
        $pdo  = getDBConnection();

        // Elimina la spesa verificando sia l'ID che l'user_id (sicurezza doppia)
        $stmt = $pdo->prepare("DELETE FROM expenses WHERE id = ? AND user_id = ?");
        $stmt->execute([(int)$id, $user['user_id']]);

        // Invia la risposta di successo senza dati (la spesa non esiste più)
        sendSuccess(null, 'Spesa eliminata con successo');
    }

    // ============================================================
    // METODO PRIVATO: findExpense()
    // Cerca una spesa per ID verificando l'ownership dell'utente.
    // Se non trovata, invia automaticamente un errore 404.
    // Usato internamente da show(), update(), destroy().
    // ============================================================
    private function findExpense(string $id, int $userId): array {
        // Ottiene la connessione al database
        $pdo  = getDBConnection();

        // Query con JOIN per recuperare anche i dati della categoria in un'unica query
        $stmt = $pdo->prepare("
            SELECT e.id, e.amount, e.description, e.expense_date, e.notes, e.created_at,
                   c.id AS category_id, c.name AS category_name, c.icon AS category_icon, c.color AS category_color
            FROM expenses e
            JOIN categories c ON e.category_id = c.id
            WHERE e.id = ? AND e.user_id = ?  -- Filtra per ID e per utente (sicurezza)
        ");
        $stmt->execute([(int)$id, $userId]);
        $expense = $stmt->fetch();

        // Se non trovata (non esiste o appartiene ad altro utente), invia 404
        if (!$expense) {
            sendError('Spesa non trovata', 404);
        }

        // Trasforma e restituisce la spesa nel formato JSON standard
        return $this->formatExpense($expense);
    }

    // ============================================================
    // METODO PRIVATO: formatExpense()
    // Trasforma una riga del database nel formato JSON standard.
    // Esegue i cast di tipo necessari (int, float) e annida la categoria.
    // ============================================================
    private function formatExpense(array $e): array {
        return [
            'id'          => (int)$e['id'],        // Cast a intero (il DB restituisce stringhe)
            'amount'      => (float)$e['amount'],  // Cast a float per la corretta serializzazione JSON
            'description' => $e['description'],
            'date'        => $e['expense_date'],   // Rinominato da expense_date a date per semplicità
            'notes'       => $e['notes'],          // Può essere null se non inserite
            'created_at'  => $e['created_at'],
            'category'    => [                     // Dati della categoria annidati come oggetto
                'id'    => (int)$e['category_id'],
                'name'  => $e['category_name'],
                'icon'  => $e['category_icon'],
                'color' => $e['category_color'],
            ]
        ];
    }
}