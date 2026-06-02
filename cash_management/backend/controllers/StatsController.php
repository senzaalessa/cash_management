<?php
// ============================================================
// controllers/StatsController.php
// Calcola e restituisce statistiche aggregate sulle spese
// e sulle entrate dell'utente autenticato.
// Tutti i metodi richiedono autenticazione.
// ============================================================

// Carica la configurazione del database
require_once __DIR__ . '/../config/database.php';

// Carica le funzioni per le risposte JSON
require_once __DIR__ . '/../helpers/response.php';

// Carica le funzioni per l'autenticazione
require_once __DIR__ . '/../helpers/auth.php';

class StatsController {

    // ============================================================
    // GET /stats/summary?year=2025
    // Restituisce un riepilogo finanziario del mese corrente
    // e dell'anno selezionato (spese, entrate, saldo, medie).
    // ============================================================
    public function summary(): void {
        // Verifica il token e recupera i dati dell'utente
        $user = authenticate();

        // Ottiene la connessione al database
        $pdo  = getDBConnection();

        // Anno da analizzare (default: anno corrente)
        $year = (int)($_GET['year'] ?? date('Y'));

        // ID dell'utente autenticato
        $uid  = $user['user_id'];

        // Mese corrente nel formato YYYY-MM (es. "2025-04")
        $curMonth = date('Y-m');

        // SPESE DEL MESE CORRENTE
        // Somma tutte le spese del mese corrente per l'utente
        // COALESCE restituisce 0 se non ci sono spese (evita NULL)
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE user_id=? AND DATE_FORMAT(expense_date,'%Y-%m')=?");
        $stmt->execute([$uid, $curMonth]);
        $monthExpenses = (float)$stmt->fetchColumn(); // fetchColumn() recupera il primo valore della prima riga

        // ENTRATE DEL MESE CORRENTE
        // Somma tutte le entrate del mese corrente per l'utente
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM incomes WHERE user_id=? AND DATE_FORMAT(income_date,'%Y-%m')=?");
        $stmt->execute([$uid, $curMonth]);
        $monthIncomes = (float)$stmt->fetchColumn();

        // CONTEGGIO SPESE DEL MESE
        // Conta il numero di spese registrate nel mese corrente
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE user_id=? AND DATE_FORMAT(expense_date,'%Y-%m')=?");
        $stmt->execute([$uid, $curMonth]);
        $monthExpCount = (int)$stmt->fetchColumn();

        // CONTEGGIO ENTRATE DEL MESE
        // Conta il numero di entrate registrate nel mese corrente
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM incomes WHERE user_id=? AND DATE_FORMAT(income_date,'%Y-%m')=?");
        $stmt->execute([$uid, $curMonth]);
        $monthIncCount = (int)$stmt->fetchColumn();

        // TOTALE SPESE ANNO
        // Somma tutte le spese dell'anno selezionato
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE user_id=? AND YEAR(expense_date)=?");
        $stmt->execute([$uid, $year]);
        $yearExpenses = (float)$stmt->fetchColumn();

        // TOTALE ENTRATE ANNO
        // Somma tutte le entrate dell'anno selezionato
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM incomes WHERE user_id=? AND YEAR(income_date)=?");
        $stmt->execute([$uid, $year]);
        $yearIncomes = (float)$stmt->fetchColumn();

        // MEDIA MENSILE SPESE (nell'anno selezionato)
        // Calcola la media dei totali mensili di spesa
        // La subquery raggruppa per mese e calcola il totale, la query esterna fa la media
        $stmt = $pdo->prepare("
            SELECT COALESCE(AVG(t),0) FROM (
                SELECT SUM(amount) AS t FROM expenses WHERE user_id=? AND YEAR(expense_date)=?
                GROUP BY DATE_FORMAT(expense_date,'%Y-%m')  -- Raggruppa per mese
            ) sub
        ");
        $stmt->execute([$uid, $year]);
        $avgExpenses = round((float)$stmt->fetchColumn(), 2); // Arrotonda a 2 decimali

        // MEDIA MENSILE ENTRATE (nell'anno selezionato)
        // Stessa logica della media mensile spese, ma per le entrate
        $stmt = $pdo->prepare("
            SELECT COALESCE(AVG(t),0) FROM (
                SELECT SUM(amount) AS t FROM incomes WHERE user_id=? AND YEAR(income_date)=?
                GROUP BY DATE_FORMAT(income_date,'%Y-%m')
            ) sub
        ");
        $stmt->execute([$uid, $year]);
        $avgIncomes = round((float)$stmt->fetchColumn(), 2);

        // Invia tutti i dati calcolati come risposta JSON
        sendSuccess([
            'year'                 => $year,                                         // Anno analizzato
            'current_month'        => $curMonth,                                     // Mese corrente (YYYY-MM)
            'month_expenses'       => $monthExpenses,                                // Totale spese mese corrente
            'month_incomes'        => $monthIncomes,                                 // Totale entrate mese corrente
            'month_balance'        => round($monthIncomes - $monthExpenses, 2),      // Saldo mese (entrate - spese)
            'month_expense_count'  => $monthExpCount,                                // Numero spese mese corrente
            'month_income_count'   => $monthIncCount,                                // Numero entrate mese corrente
            'year_expenses'        => $yearExpenses,                                 // Totale spese anno
            'year_incomes'         => $yearIncomes,                                  // Totale entrate anno
            'year_balance'         => round($yearIncomes - $yearExpenses, 2),        // Saldo anno (entrate - spese)
            'avg_monthly_expenses' => $avgExpenses,                                  // Media mensile spese
            'avg_monthly_incomes'  => $avgIncomes,                                   // Media mensile entrate
        ]);
    }

    // ============================================================
    // GET /stats/monthly?year=2025
    // Restituisce i totali mensili di spese, entrate e saldo
    // per ogni mese dell'anno selezionato in cui ci sono dati.
    // ============================================================
    public function monthly(): void {
        // Verifica il token e recupera i dati dell'utente
        $user = authenticate();

        // Ottiene la connessione al database
        $pdo  = getDBConnection();

        // Anno da analizzare (default: anno corrente)
        $year = (int)($_GET['year'] ?? date('Y'));

        // ID dell'utente autenticato
        $uid  = $user['user_id'];

        // Query per le spese mensili: raggruppa per mese e calcola totale e conteggio
        $stmt = $pdo->prepare("
            SELECT DATE_FORMAT(expense_date,'%Y-%m') AS month,  -- Estrae YYYY-MM dalla data
                   SUM(amount) AS total,                         -- Somma degli importi del mese
                   COUNT(*) AS count                             -- Numero di spese del mese
            FROM expenses WHERE user_id=? AND YEAR(expense_date)=?
            GROUP BY month ORDER BY month                        -- Un risultato per mese, ordinato
        ");
        $stmt->execute([$uid, $year]);

        // Costruisce un dizionario indicizzato per mese per un accesso rapido (es. $expByMonth["2025-04"])
        $expByMonth = [];
        foreach ($stmt->fetchAll() as $r) {
            $expByMonth[$r['month']] = ['total' => (float)$r['total'], 'count' => (int)$r['count']];
        }

        // Stessa query per le entrate mensili
        $stmt = $pdo->prepare("
            SELECT DATE_FORMAT(income_date,'%Y-%m') AS month,
                   SUM(amount) AS total,
                   COUNT(*) AS count
            FROM incomes WHERE user_id=? AND YEAR(income_date)=?
            GROUP BY month ORDER BY month
        ");
        $stmt->execute([$uid, $year]);

        // Costruisce il dizionario per mese delle entrate
        $incByMonth = [];
        foreach ($stmt->fetchAll() as $r) {
            $incByMonth[$r['month']] = ['total' => (float)$r['total'], 'count' => (int)$r['count']];
        }

        // Raccoglie tutti i mesi presenti (sia in spese che entrate) ed elimina i duplicati
        $allMonths = array_unique(array_merge(array_keys($expByMonth), array_keys($incByMonth)));

        // Ordina i mesi in ordine crescente (es. 2025-01, 2025-02, ...)
        sort($allMonths);

        // Costruisce l'array finale combinando dati spese e entrate per ogni mese
        $months = array_map(function($m) use ($expByMonth, $incByMonth) {
            // Se non ci sono spese per il mese, usa valori di default a 0
            $exp = $expByMonth[$m] ?? ['total' => 0.0, 'count' => 0];

            // Se non ci sono entrate per il mese, usa valori di default a 0
            $inc = $incByMonth[$m] ?? ['total' => 0.0, 'count' => 0];

            return [
                'month'         => $m,                                       // Mese (YYYY-MM)
                'expenses'      => $exp['total'],                            // Totale spese del mese
                'expense_count' => $exp['count'],                            // Numero spese del mese
                'incomes'       => $inc['total'],                            // Totale entrate del mese
                'income_count'  => $inc['count'],                            // Numero entrate del mese
                'balance'       => round($inc['total'] - $exp['total'], 2),  // Saldo del mese
            ];
        }, $allMonths);

        // Invia i dati mensili
        sendSuccess(['year' => $year, 'months' => $months]);
    }

    // ============================================================
    // GET /stats/category?month=2025-04
    // Restituisce i totali per categoria di spesa e di entrata
    // per il mese specificato (default: mese corrente).
    // Include anche le categorie con importo 0 (LEFT JOIN).
    // ============================================================
    public function byCategory(): void {
        // Verifica il token e recupera i dati dell'utente
        $user  = authenticate();

        // Ottiene la connessione al database
        $pdo   = getDBConnection();

        // Mese da analizzare (default: mese corrente in formato YYYY-MM)
        $month = $_GET['month'] ?? date('Y-m');

        // ID dell'utente autenticato
        $uid   = $user['user_id'];

    // SPESE PER CATEGORIA
    // Usa una subquery per filtrare prima le spese dell'utente nel mese,
    // poi fa il LEFT JOIN con le categorie per includere anche quelle a 0
    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.icon, c.color,
               COALESCE(SUM(e.amount),0) AS total, COUNT(e.id) AS count
        FROM categories c
        LEFT JOIN (
            SELECT * FROM expenses
            WHERE user_id = ? AND DATE_FORMAT(expense_date,'%Y-%m') = ?
        ) e ON c.id = e.category_id
        GROUP BY c.id ORDER BY total DESC
    ");
    $stmt->execute([$uid, $month]);

        // Trasforma ogni riga nel formato JSON standard con categoria annidata
        $expCats = array_map(fn($r) => [
            'category' => ['id'=>(int)$r['id'],'name'=>$r['name'],'icon'=>$r['icon'],'color'=>$r['color']],
            'total'    => (float)$r['total'], // Totale spese per questa categoria nel mese
            'count'    => (int)$r['count'],   // Numero di spese per questa categoria nel mese
        ], $stmt->fetchAll());

        // ENTRATE PER CATEGORIA
        // Stessa logica con subquery per le entrate
        $stmt = $pdo->prepare("
            SELECT c.id, c.name, c.icon, c.color,
                   COALESCE(SUM(i.amount),0) AS total, COUNT(i.id) AS count
            FROM income_categories c
            LEFT JOIN (
                SELECT * FROM incomes
                WHERE user_id = ? AND DATE_FORMAT(income_date,'%Y-%m') = ?
            ) i ON c.id = i.category_id
            GROUP BY c.id ORDER BY total DESC
        ");
        $stmt->execute([$uid, $month]);

        // Trasforma le righe delle entrate nel formato standard
        $incCats = array_map(fn($r) => [
            'category' => ['id'=>(int)$r['id'],'name'=>$r['name'],'icon'=>$r['icon'],'color'=>$r['color']],
            'total'    => (float)$r['total'],
            'count'    => (int)$r['count'],
        ], $stmt->fetchAll());

        // Invia entrambi gli array (spese e entrate per categoria) nella risposta
        sendSuccess([
            'month'              => $month,    // Mese analizzato
            'expense_categories' => $expCats,  // Dettaglio spese per categoria
            'income_categories'  => $incCats,  // Dettaglio entrate per categoria
        ]);
    }

    // ============================================================
    // GET /stats/years?type=income|expense
    // Restituisce la lista di tutti gli anni in cui l'utente
    // ha almeno una registrazione (spesa o entrata).
    // Usato dal frontend per popolare il selettore degli anni.
    // ============================================================
    public function years(): void {
        // Verifica il token e recupera i dati dell'utente
        $user = authenticate();

        // ID dell'utente autenticato
        $uid  = $user['user_id'];

        // Tipo di dato richiesto: "income" per entrate, qualsiasi altro valore per spese
        $type = $_GET['type'] ?? 'expense';

        // Ottiene la connessione al database
        $pdo  = getDBConnection();

        // Seleziona la query in base al tipo richiesto
        if ($type === 'income') {
            // Recupera gli anni distinti delle entrate, ordinati dal più recente
            $stmt = $pdo->prepare("SELECT DISTINCT YEAR(income_date) AS y FROM incomes WHERE user_id=? ORDER BY y DESC");
        } else {
            // Recupera gli anni distinti delle spese, ordinati dal più recente
            $stmt = $pdo->prepare("SELECT DISTINCT YEAR(expense_date) AS y FROM expenses WHERE user_id=? ORDER BY y DESC");
        }

        // Esegue la query con l'ID dell'utente
        $stmt->execute([$uid]);

        // Trasforma i risultati in un array semplice di interi (es. [2025, 2024, 2023])
        $years = array_map(fn($r) => (int)$r['y'], $stmt->fetchAll());

        // Invia la lista degli anni come risposta
        sendSuccess($years);
    }
}