<?php
// ============================================================
// controllers/IncomeController.php
// Gestisce le operazioni CRUD sulle entrate dell'utente autenticato.
// Struttura identica a ExpenseController ma per la tabella incomes.
// Contiene anche il metodo categories() per le categorie di entrata.
// ============================================================

// Carica la configurazione del database
require_once __DIR__ . '/../config/database.php';

// Carica le funzioni per le risposte JSON
require_once __DIR__ . '/../helpers/response.php';

// Carica le funzioni per l'autenticazione
require_once __DIR__ . '/../helpers/auth.php';

class IncomeController {

    // ============================================================
    // GET /incomes
    // Restituisce la lista paginata delle entrate dell'utente.
    // Supporta gli stessi filtri di /expenses:
    //   ?month, ?category_id, ?search, ?page, ?per_page
    // ============================================================
    public function index(): void {
        // Verifica il token e recupera i dati dell'utente autenticato
        $user = authenticate();

        // Ottiene la connessione al database
        $pdo  = getDBConnection();

        // Condizione WHERE iniziale: filtra solo le entrate dell'utente corrente
        $where  = ["i.user_id = :user_id"];
        $params = [':user_id' => $user['user_id']];

        // Aggiunge filtro per mese se presente nell'URL
        if (!empty($_GET['month'])) {
            $where[]          = "DATE_FORMAT(i.income_date, '%Y-%m') = :month";
            $params[':month'] = $_GET['month'];
        }

        // Aggiunge filtro per categoria se presente nell'URL
        if (!empty($_GET['category_id'])) {
            $where[]               = "i.category_id = :category_id";
            $params[':category_id'] = (int)$_GET['category_id'];
        }

        // Aggiunge ricerca testuale nella descrizione se presente nell'URL
        if (!empty($_GET['search'])) {
            $where[]           = "i.description LIKE :search";
            $params[':search'] = '%' . $_GET['search'] . '%';
        }

        // Unisce le condizioni WHERE in una stringa SQL
        $whereClause = implode(' AND ', $where);

        // Calcola i parametri di paginazione
        $page    = max(1, (int)($_GET['page'] ?? 1));               // Pagina corrente (minimo 1)
        $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 20))); // Record per pagina (tra 1 e 100)
        $offset  = ($page - 1) * $perPage;                          // Offset per SQL LIMIT

        // Query per il conteggio totale dei record filtrati
        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM incomes i WHERE $whereClause");
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        // Query principale con JOIN alle income_categories per ottenere nome, icona e colore
        $sql = "
            SELECT i.id, i.amount, i.description, i.income_date, i.notes, i.created_at,
                   c.id AS category_id, c.name AS category_name, c.icon AS category_icon, c.color AS category_color
            FROM incomes i
            JOIN income_categories c ON i.category_id = c.id
            WHERE $whereClause
            ORDER BY i.income_date DESC, i.created_at DESC
            LIMIT :limit OFFSET :offset
        ";

        // Prepara ed esegue la query con binding dei parametri
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $val) $stmt->bindValue($key, $val); // Lega i filtri
        $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT); // Lega il limite come intero
        $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT); // Lega l'offset come intero
        $stmt->execute();

        // Formatta ogni riga nel formato JSON standard
        $incomes = array_map(fn($r) => $this->format($r), $stmt->fetchAll());

        // Invia la risposta con entrate e dati di paginazione
        sendSuccess([
            'incomes'    => $incomes,
            'pagination' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int)ceil($total / $perPage), // Numero totale di pagine
            ]
        ]);
    }

    // ============================================================
    // GET /incomes/{id}
    // Restituisce i dettagli di una singola entrata per ID.
    // ============================================================
    public function show(string $id): void {
        // Verifica il token e recupera i dati dell'utente
        $user = authenticate();

        // Cerca l'entrata e invia la risposta (find() gestisce il 404 automaticamente)
        sendSuccess($this->find($id, $user['user_id']));
    }

    // ============================================================
    // POST /incomes
    // Crea una nuova entrata per l'utente autenticato.
    // Richiede nel corpo JSON: amount, description, category_id, income_date.
    // ============================================================
    public function store(): void {
        // Verifica il token e recupera i dati dell'utente
        $user    = authenticate();

        // Legge i dati JSON dalla richiesta
        $data    = getRequestBody();

        // Verifica che tutti i campi obbligatori siano presenti e non vuoti
        $missing = validateRequired($data, ['amount', 'description', 'category_id', 'income_date']);
        if (!empty($missing)) sendError('Campi obbligatori mancanti: ' . implode(', ', $missing), 422);

        // Valida che l'importo sia un numero float positivo
        $amount = filter_var($data['amount'], FILTER_VALIDATE_FLOAT);
        if ($amount === false || $amount <= 0) sendError("L'importo deve essere un numero positivo", 422);

        // Valida il formato della data (YYYY-MM-DD)
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['income_date'])) sendError('Data non valida (YYYY-MM-DD)', 422);

        // Ottiene la connessione al database
        $pdo  = getDBConnection();

        // Verifica che la categoria esista nella tabella income_categories
        $stmt = $pdo->prepare("SELECT id FROM income_categories WHERE id = ?");
        $stmt->execute([(int)$data['category_id']]);
        if (!$stmt->fetch()) sendError('Categoria non valida', 422);

        // Inserisce la nuova entrata nel database
        $stmt = $pdo->prepare("
            INSERT INTO incomes (user_id, category_id, amount, description, income_date, notes)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user['user_id'],                                     // ID utente autenticato
            (int)$data['category_id'],                            // ID categoria (intero)
            round($amount, 2),                                    // Importo arrotondato a 2 decimali
            trim($data['description']),                           // Descrizione senza spazi
            $data['income_date'],                                 // Data dell'entrata
            isset($data['notes']) ? trim($data['notes']) : null,  // Note opzionali
        ]);

        // Recupera e invia l'entrata appena creata con tutti i dati
        $income = $this->find($pdo->lastInsertId(), $user['user_id']);
        sendSuccess($income, 'Entrata aggiunta con successo', 201); // 201 = Created
    }

    // ============================================================
    // PUT /incomes/{id}
    // Aggiorna un'entrata esistente (solo i campi inviati vengono modificati).
    // ============================================================
    public function update(string $id): void {
        // Verifica il token e recupera i dati dell'utente
        $user = authenticate();

        // Verifica che l'entrata esista e appartenga all'utente
        $this->find($id, $user['user_id']);

        // Legge i dati JSON dalla richiesta
        $data = getRequestBody();

        // Ottiene la connessione al database
        $pdo  = getDBConnection();

        // Array dei campi e valori da aggiornare (costruiti dinamicamente)
        $fields = []; $params = [];

        // Aggiorna l'importo solo se presente nella richiesta
        if (isset($data['amount'])) {
            $amount = filter_var($data['amount'], FILTER_VALIDATE_FLOAT);
            if ($amount === false || $amount <= 0) sendError('Importo non valido', 422);
            $fields[] = 'amount = ?'; $params[] = round($amount, 2);
        }

        // Aggiorna la descrizione solo se presente
        if (isset($data['description'])) { $fields[] = 'description = ?'; $params[] = trim($data['description']); }

        // Aggiorna la categoria solo se presente (con validazione esistenza)
        if (isset($data['category_id'])) {
            $stmt = $pdo->prepare("SELECT id FROM income_categories WHERE id = ?");
            $stmt->execute([(int)$data['category_id']]);
            if (!$stmt->fetch()) sendError('Categoria non valida', 422);
            $fields[] = 'category_id = ?'; $params[] = (int)$data['category_id'];
        }

        // Aggiorna la data solo se presente (con validazione formato)
        if (isset($data['income_date'])) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['income_date'])) sendError('Data non valida', 422);
            $fields[] = 'income_date = ?'; $params[] = $data['income_date'];
        }

        // Aggiorna le note se la chiave è presente (anche se null, per permettere la cancellazione)
        if (array_key_exists('notes', $data)) {
            $fields[] = 'notes = ?'; $params[] = $data['notes'] !== null ? trim($data['notes']) : null;
        }

        // Se nessun campo è stato inviato, invia un errore
        if (empty($fields)) sendError('Nessun campo da aggiornare', 422);

        // Aggiunge i parametri per la clausola WHERE (id e user_id)
        $params[] = (int)$id;
        $params[] = $user['user_id'];

        // Costruisce ed esegue la query UPDATE con i soli campi inviati
        $pdo->prepare("UPDATE incomes SET " . implode(', ', $fields) . " WHERE id = ? AND user_id = ?")->execute($params);

        // Invia l'entrata aggiornata come risposta
        sendSuccess($this->find($id, $user['user_id']), 'Entrata aggiornata con successo');
    }

    // ============================================================
    // DELETE /incomes/{id}
    // Elimina definitivamente un'entrata per ID.
    // ============================================================
    public function destroy(string $id): void {
        // Verifica il token e recupera i dati dell'utente
        $user = authenticate();

        // Verifica che l'entrata esista e appartenga all'utente
        $this->find($id, $user['user_id']);

        // Ottiene la connessione al database
        $pdo = getDBConnection();

        // Elimina l'entrata verificando sia ID che user_id (sicurezza doppia)
        $pdo->prepare("DELETE FROM incomes WHERE id = ? AND user_id = ?")->execute([(int)$id, $user['user_id']]);

        // Invia la risposta di successo
        sendSuccess(null, 'Entrata eliminata con successo');
    }

    // ============================================================
    // GET /income-categories
    // Restituisce la lista di tutte le categorie di entrata disponibili.
    // Richiede autenticazione ma le categorie sono condivise tra tutti.
    // ============================================================
    public function categories(): void {
        // Verifica il token (richiede l'autenticazione ma non usa i dati utente)
        authenticate();

        // Ottiene la connessione al database
        $pdo  = getDBConnection();

        // Recupera tutte le categorie ordinate per nome
        $stmt = $pdo->query("SELECT id, name, icon, color FROM income_categories ORDER BY name");

        // Trasforma ogni riga nel formato JSON con i cast corretti
        sendSuccess(array_map(fn($c) => [
            'id'    => (int)$c['id'], // Cast a intero
            'name'  => $c['name'],
            'icon'  => $c['icon'],
            'color' => $c['color'],
        ], $stmt->fetchAll()));
    }

    // ============================================================
    // METODO PRIVATO: find()
    // Cerca un'entrata per ID verificando l'ownership dell'utente.
    // Se non trovata, invia automaticamente un errore 404.
    // ============================================================
    private function find(string $id, int $userId): array {
        // Ottiene la connessione al database
        $pdo  = getDBConnection();

        // Query con JOIN per recuperare anche i dati della categoria
        $stmt = $pdo->prepare("
            SELECT i.id, i.amount, i.description, i.income_date, i.notes, i.created_at,
                   c.id AS category_id, c.name AS category_name, c.icon AS category_icon, c.color AS category_color
            FROM incomes i
            JOIN income_categories c ON i.category_id = c.id
            WHERE i.id = ? AND i.user_id = ?  -- Verifica sia ID che ownership
        ");
        $stmt->execute([(int)$id, $userId]);
        $r = $stmt->fetch();

        // Se non trovata, termina con errore 404
        if (!$r) sendError('Entrata non trovata', 404);

        // Trasforma e restituisce nel formato standard
        return $this->format($r);
    }

    // ============================================================
    // METODO PRIVATO: format()
    // Trasforma una riga del database nel formato JSON standard.
    // Esegue i cast di tipo e annida i dati della categoria.
    // ============================================================
    private function format(array $r): array {
        return [
            'id'          => (int)$r['id'],        // Cast a intero
            'amount'      => (float)$r['amount'],  // Cast a float
            'description' => $r['description'],
            'date'        => $r['income_date'],    // Rinominato da income_date a date
            'notes'       => $r['notes'],          // Può essere null
            'created_at'  => $r['created_at'],
            'category'    => [                     // Dati categoria annidati come oggetto
                'id'    => (int)$r['category_id'],
                'name'  => $r['category_name'],
                'icon'  => $r['category_icon'],
                'color' => $r['category_color'],
            ]
        ];
    }
}