<?php
// ============================================================
// helpers/response.php
// Funzioni di utilità per inviare risposte JSON standardizzate
// Ogni risposta segue sempre il formato:
// { "success": true/false, "message": "...", "data": {...} }
// ============================================================

// ============================================================
// Funzione: sendJSON()
// Funzione base che invia qualsiasi array come risposta JSON
// con il codice HTTP specificato, poi termina l'esecuzione
// ============================================================
function sendJSON(array $data, int $statusCode = 200): void {
    // Imposta il codice di stato HTTP della risposta (es. 200, 404, 500)
    http_response_code($statusCode);

    // Codifica l'array PHP in formato JSON e lo stampa come output
    // JSON_UNESCAPED_UNICODE: mantiene i caratteri speciali (es. lettere accentate) leggibili
    // JSON_PRETTY_PRINT: formatta il JSON con indentazione per leggibilità
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    // Termina l'esecuzione dello script dopo aver inviato la risposta
    exit;
}

// ============================================================
// Funzione: sendSuccess()
// Invia una risposta JSON di successo con dati opzionali.
// Usata da tutti i controller quando un'operazione va a buon fine.
// ============================================================
function sendSuccess(mixed $data = null, string $message = 'Operazione completata', int $code = 200): void {
    // Costruisce la struttura base della risposta di successo
    $response = ['success' => true, 'message' => $message];

    // Aggiunge il campo "data" solo se ci sono dati da restituire (evita "data: null" inutili)
    if ($data !== null) $response['data'] = $data;

    // Delega l'invio alla funzione base sendJSON
    sendJSON($response, $code);
}

// ============================================================
// Funzione: sendError()
// Invia una risposta JSON di errore con messaggio e codice HTTP.
// Usata da tutti i controller quando qualcosa va storto.
// ============================================================
function sendError(string $message, int $code = 400, array $errors = []): void {
    // Costruisce la struttura base della risposta di errore
    $response = ['success' => false, 'message' => $message];

    // Aggiunge il campo "errors" solo se sono stati forniti dettagli sugli errori
    if (!empty($errors)) $response['errors'] = $errors;

    // Delega l'invio alla funzione base sendJSON con il codice di errore
    sendJSON($response, $code);
}

// ============================================================
// Funzione: getRequestBody()
// Legge e decodifica il corpo della richiesta HTTP in JSON.
// Usata nei metodi POST e PUT per ottenere i dati inviati dal client.
// ============================================================
function getRequestBody(): array {
    // Legge il corpo grezzo della richiesta HTTP dal flusso di input standard
    $json = file_get_contents('php://input');

    // Decodifica il JSON in un array PHP associativo
    // L'operatore ?? restituisce un array vuoto se il JSON non è valido o mancante
    return json_decode($json, true) ?? [];
}

// ============================================================
// Funzione: validateRequired()
// Controlla che tutti i campi obbligatori siano presenti
// e non vuoti nell'array di dati fornito.
// Restituisce la lista dei campi mancanti (array vuoto se tutto ok).
// ============================================================
function validateRequired(array $data, array $fields): array {
    // Array che raccoglierà i nomi dei campi mancanti
    $missing = [];

    // Itera ogni campo obbligatorio da verificare
    foreach ($fields as $field) {
        // Verifica che il campo esista E che il suo valore non sia una stringa vuota
        if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
            // Il campo manca o è vuoto: lo aggiunge alla lista dei mancanti
            $missing[] = $field;
        }
    }

    // Restituisce l'array dei campi mancanti (vuoto se tutti presenti)
    return $missing;
}