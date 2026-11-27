# Aurora su Upsun — runbook della sessione con 2R Studio

Durata: 2 ore. Sette scene, nell'ordine sotto. Per ogni scena: comandi esatti,
cosa vede il pubblico, e la frase che la collega alle loro preoccupazioni.

Convenzioni:

- `$URL` è l'URL di produzione: `URL=$(upsun url --primary --pipe -e main)`.
- I comandi si lanciano dalla radice del repository, con il progetto collegato
  (`upsun project:set-remote <PROJECT_ID>`).
- Tutti i dati sono inventati. L'interruzione del broker è **simulata** e va
  presentata come tale.

| # | Scena | Minuti |
|---|---|---|
| 0 | Apertura: cosa vedremo | 5 |
| 1 | Tour del repository: un file YAML al posto dei server | 15 |
| 2 | Un `git push` fa il deploy | 10 |
| 3 | Dall'issue alla pull request con Upsun Dispatch (opzionale), ambiente di anteprima con i dati di produzione | 20 |
| 4 | Picco serale: carico k6 e ridimensionamento | 25 |
| 5 | Calcolo provvigioni, più veloce dopo il ridimensionamento | 15 |
| 6 | Broker non disponibile (simulato) e ripristino | 15 |
| 7 | Log, SSH, dump, cron, e quello che non devono più fare | 10 |
| | Domande | 5 |

---

## Preparazione (il giorno prima)

Da fare con calma, non davanti al cliente.

1. **Progetto e deploy iniziale** (vedi README, "Deploying to Upsun"): progetto
   `aurora-demo` in regione UE, `APP_KEY` come variabile di progetto, integrazione
   GitHub attiva con `--resources-init parent`, primo deploy di `main` riuscito.
2. **Object storage per le fatture** su `main`:
   `upsun resources:set --object-storage oms:524288 -e main`
3. **Dati di volume** (50.000 incaricate, 500.000 ordini), circa 1 minuto:
   `upsun ssh -e main --app nfw -- php artisan aurora:seed-volume --force`
4. **Dimensioni di partenza** (piccole, per far vedere la differenza dopo):
   ```bash
   upsun resources:get -e main          # annotare i nomi esatti di app e worker
   upsun resources:set -e main --size nfw:0.5 --count nfw:1 --size nfw--commissions:0.5
   ```
   Il nome del worker (`nfw--commissions`) va verificato nell'output di
   `resources:get`; vedi ASSUMPTIONS.md.
5. **Prova generale delle scene 5 e 6** su `main`: lanciare un calcolo provvigioni
   e annotare la durata a 0,5 CPU. Questo è il numero da battere in scena.
6. **Protezione**: `upsun httpaccess -e main --auth demo:<password>`. Ricordarsi
   di passare le credenziali a k6 (scena 4).
7. **Upsun Dispatch** (scena 3). Una tantum, dalla console di Upsun Dispatch:
   - accedere, creare un'organizzazione;
   - **Settings → GitHub**: installare la GitHub App di Upsun Dispatch su
     `platformista/aurora` (serve un admin del repository; Issue to code richiede
     anche il permesso di push);
   - **Workflows**: Code review in modalità automatica; per Issue to code attivare
     **Plan approval**.

   Poi, il giorno prima, preparare la PR della scena 3 (una run può durare fino a
   20 minuti, per questo non si avvia a freddo davanti al cliente):
   1. Aprire su GitHub l'issue con il testo riportato nella scena 3.
   2. Aggiungere un commento il cui testo sia **esattamente**
      `@upsun-dispatch implement` (qualsiasi altro testo nel commento lo invalida).
   3. Leggere il piano che Dispatch pubblica sull'issue e approvarlo dalla console.
   4. Attendere la PR (branch `upsun-dispatch/issue-<numero>`), il controllo
      GitHub Actions, la revisione di Dispatch e l'ambiente `pr-<numero>`.
   5. Verificare nell'anteprima che la promozione funzioni (3 prodotti Viso nel
      carrello). **Non fare merge.**

   Se la run fallisce o il codice non convince, preparare la stessa PR a mano
   (vedi "Piano B"): la scena 3 funziona anche senza Dispatch.
8. **Postazione**: k6 installato (`k6 version`), CLI Upsun autenticata
   (`upsun auth:info`), schede del browser aperte su: repository GitHub,
   console Upsun del progetto, `$URL/admin`, `$URL/horizon`, `$URL/ordini`
   (con un'incaricata scelta da `/accedi`).

---

## Scena 0 — Apertura (5 min)

**Cosa dire:** "Questo è un Aurora in miniatura: le tre applicazioni del
capitolato (NFW/Bridge, OMS, Promotion Engine), lo stesso stack (PHP 8.5,
Laravel 13, Inertia e React, MariaDB, Redis/Horizon, Kafka, object storage).
Oggi non installiamo niente: scriviamo codice e facciamo push."

**Cosa vede il pubblico:** `$URL/admin`, il pannello demo con i contatori a zero
backlog.

> Collegamento: Aurora resta un progetto Laravel come quelli che fate già; cambia
> chi si occupa di quello che c'è sotto.

---

## Scena 1 — Tour del repository (15 min)

**Comandi / schermate:**

```bash
tree -L 2 -a -I 'vendor|node_modules' .
cat .upsun/config.yaml
cat apps/nfw/.environment
```

Percorso consigliato in `.upsun/config.yaml`:

1. `applications:` tre app, ognuna con `source.root` nella propria cartella.
2. `type: "php:8.5"` e `runtime.extensions: [redis, rdkafka]`: niente `apt-get`,
   niente `pecl`.
3. `hooks.build` (composer, build Vite) e `hooks.deploy` (migrazioni).
4. `workers:` di `nfw`: `horizon`, `commissions`, `relay`, `consumer`.
   **Questo è l'equivalente di Supervisor**: ogni processo ha il suo container,
   se termina viene riavviato, al deploy riceve SIGTERM e poi, dopo 15 secondi,
   SIGKILL. Nessun `&`, nessun file `supervisord.conf`.
5. `crons:` il calcolo provvigioni notturno (accoda soltanto i job; il lavoro lo
   fa Horizon).
6. `relationships:` `promotions` ed `oms` sono raggiungibili da NFW come
   `http://promotions.internal` e `http://oms.internal`, sulla rete interna del
   progetto. L'OMS e il Promotion Engine **non hanno una rotta pubblica**.
7. `services:` una MariaDB con tre schemi e tre utenti (ognuno vede solo il
   proprio), Valkey per code e cache, Kafka.
8. `routes:` una sola rotta pubblica.

Poi `apps/nfw/.environment`: le credenziali non sono nel codice, arrivano dalle
relazioni come variabili d'ambiente.

**Cosa vede il pubblico:** un solo file di circa 200 righe che descrive tutto
quello che oggi starebbe su più server, in più file di configurazione e in uno
script di provisioning.

> Collegamento: con 15 sviluppatori e nessun sistemista dedicato, l'infrastruttura
> diventa un file revisionato in pull request come il resto del codice.

---

## Scena 2 — Un `git push` fa il deploy (10 min)

**Comandi:**

```bash
git checkout main && git pull
# modifica visibile: il titolo della pagina "Nuovo ordine"
sed -i 's/title="Nuovo ordine"/title="Nuovo ordine · fine ciclo"/' apps/nfw/resources/js/Pages/Ordini/Nuovo.jsx
git commit -am "Titolo pagina ordine per la chiusura del ciclo"
git push origin main
upsun activity:log -e main          # segue il deploy in tempo reale
```

**Cosa vede il pubblico:** GitHub riceve il push; nella console Upsun parte
l'attività; nel terminale scorrono build (composer, `npm run build`) e deploy
(migrazioni, topic Kafka). Ricaricando `$URL/ordini/nuovo` il titolo è cambiato.
I worker sono stati riavviati senza intervento.

**Da sottolineare:** solo l'app modificata viene ricostruita; le altre due
riusano l'immagine precedente.

> Collegamento: il rilascio diventa un push revisionato, ripetibile e uguale per
> tutti, invece di una procedura manuale.

---

## Scena 3 — Dall'issue alla pull request con Upsun Dispatch, ambiente di anteprima (20 min)

**Premessa da dire, prima di mostrare Dispatch:** "Upsun Dispatch è
**facoltativo**. È un servizio separato, che potete attivare oppure no: tutto
quello che avete visto e vedrete oggi (deploy, ambienti per ogni pull request,
risorse, worker, servizi) funziona allo stesso modo con pull request scritte a
mano. Ve lo mostriamo perché si inserisce nello stesso flusso di lavoro."

**L'issue usata (aperta il giorno prima):**

> **Titolo:** Promozione COFANETTO-VISO
>
> Aggiungere al Promotion Engine la promozione `COFANETTO-VISO`: 20% di sconto
> sui prodotti della categoria Viso quando nel carrello ci sono almeno 3 articoli
> Viso.
>
> - Usare il tipo di regola esistente `category_quantity` con i parametri
>   `{"category": "Viso", "min_qty": 3, "percent": 20}` e l'etichetta
>   "20% su 3 o più prodotti Viso".
> - Inserire la regola con una **nuova** migrazione in
>   `apps/promotions/database/migrations`, senza modificare quelle esistenti.
> - Aggiungere un test in `apps/promotions/tests/Feature` che chiami
>   `POST /api/quote` e verifichi lo sconto, anche in combinazione con
>   `SOGLIA-80`.
> - Non toccare NFW né OMS.

Commento di avvio, da solo: `@upsun-dispatch implement`

**Percorso (5 minuti su Dispatch, poi l'anteprima):**

1. **L'issue su GitHub:** una richiesta come quelle che riceveranno dal cliente,
   in italiano. Sotto, il **piano** pubblicato da Dispatch (riassunto, file
   coinvolti, complessità, assunzioni, rischi) e l'approvazione di una persona:
   il controllo resta al team.
2. **La pull request:** il diff (una migrazione e un test, solo nel Promotion
   Engine), il controllo GitHub Actions verde (test su PHP 8.5 e MariaDB), i
   commenti di revisione di Dispatch e il link all'ambiente Upsun.
3. **Facoltativo, dal vivo:** commentare sulla PR, da solo,
   `@upsun-dispatch review`. Proseguire senza aspettare; il risultato si guarda a
   fine scena o dopo la scena 4.
4. **L'ambiente di anteprima:**

   ```bash
   upsun environment:list                     # compare pr-<numero>
   upsun url -e pr-<numero> --primary
   upsun ssh -e pr-<numero> --app nfw -- php artisan tinker --execute 'echo App\Models\Order::count();'
   ```

   Nell'anteprima: accedere come un'incaricata, mettere nel carrello 3 prodotti
   Viso e premere **Calcola prezzo**: compare "20% su 3 o più prodotti Viso".
   Su `main` la stessa operazione non applica lo sconto.

**Cosa vede il pubblico:**

- Un ambiente completo (tre app, worker, MariaDB, Valkey, Kafka) creato per la
  pull request, con gli stessi 500.000 ordini di produzione.
- Solo l'app `promotions` è stata ricostruita; NFW e OMS riusano l'immagine di
  `main`.
- La modifica funziona sui dati reali prima di arrivare in produzione.

**Chiusura:** fare merge della PR → l'ambiente `pr-<numero>` viene eliminato,
`main` si aggiorna e la migrazione aggiunge la promozione in produzione.

**Domande probabili su Dispatch (risposte brevi):**

- *È obbligatorio?* No. È un servizio a parte; la piattaforma non dipende da esso.
- *I nostri dati addestrano i modelli?* Nel piano gratuito contenuti e risultati
  possono essere usati per l'addestramento; nel piano a pagamento no. Per il
  codice di un cliente serve il piano a pagamento.
- *Chi decide?* Le persone: il piano si approva prima che venga scritto codice,
  la PR passa da revisione e test, il merge lo fa il team.
- *Quanto costa?* Gratuito fino a 20 minuti di agente al giorno; a pagamento a
  consumo (vedi la pagina prezzi di Upsun Dispatch).

**Nota sui costi dell'anteprima:** l'ambiente nasce con le dimensioni di
produzione (`--resources-init parent`) e con il bucket clonato. Fare merge o
chiudere la PR lo stesso giorno.

> Collegamento: il collaudo con Yves Rocher Italia avviene su una copia reale del
> sistema, per ogni modifica, senza mantenere a mano un ambiente di staging; e,
> se lo vorranno, una parte del lavoro ripetitivo può partire da un'issue.

---

## Scena 4 — Picco serale: carico e ridimensionamento (25 min)

**Contesto:** due sere ogni tre settimane, alla chiusura del ciclo, le incaricate
ordinano tutte insieme.

**Passo 1, carico sulle dimensioni di partenza:**

```bash
upsun resources:get -e main
k6 run -e BASE_URL="https://demo:<password>@${URL#https://}" -e PEAK_RPS=40 -e HOLD=3m load/k6/peak-evening.js
```

(se `LOAD_TEST_TOKEN` è impostato, aggiungere `-e TOKEN=<token>`)

Mentre gira, mostrare:

```bash
upsun metrics -e main               # CPU e memoria per app e servizio
upsun log -e main --app nfw access --tail
```

e `$URL/admin`: il backlog dell'outbox di NFW sale e scende, ordini e spedizioni
restano allineati.

**Passo 2, ridimensionamento manuale (CLI):**

```bash
upsun resources:set -e main --size nfw:2 --count nfw:3
```

In alternativa dalla console: progetto → ambiente `main` → **Configure resources**,
riga `nfw`, CPU e numero di istanze, **Save**.

**Passo 3:** rilanciare lo stesso k6 e confrontare p95 e richieste fallite.

**Cosa vede il pubblico:** a parità di carico, latenze più basse dopo il
ridimensionamento; il ridimensionamento è un comando, con un redeploy senza
modifiche al codice. Dopo il picco si torna indietro con lo stesso comando
(`--size nfw:0.5 --count nfw:1`).

**Da dire chiaramente:** oggi mostriamo il ridimensionamento **manuale**, che si
pianifica per le due sere di picco. L'autoscaling esiste, ma non è l'oggetto di
questa demo.

> Collegamento: il picco di fine ciclo si affronta aumentando le risorse per due
> sere e riducendole il giorno dopo, pagando le risorse solo per il tempo in cui
> servono.

---

## Scena 5 — Calcolo provvigioni, prima e dopo (15 min)

**Passo 1, a 0,5 CPU:** su `$URL/admin` premere **"Calcola ora"** (oppure
`upsun ssh -e main --app nfw -- php artisan commissions:run`). Aprire
`$URL/horizon` → coda `commissions`: decine di job in parallelo.

**Cosa vede il pubblico:** in "Ultimi calcoli provvigioni", circa 400.000 ordini
del ciclo, 51 job, circa 50.000 beneficiarie, e la durata. Misurato su `main` il
27/11/2026 a 0,5 CPU: **102 secondi**.

**Attenzione:** premere "Calcola ora" **una sola volta** e attendere. Due calcoli
accodati insieme si dividono lo stesso worker e falsano entrambe le durate (in
prova: 204 s e 110 s invece di 102 s).

**Passo 2, ridimensionare solo il worker delle provvigioni:**

```bash
upsun resources:set -e main --size nfw--commissions:2
```

Attendere che il deploy delle risorse finisca (circa un minuto), poi rilanciare
**"Calcola ora"**. La durata attesa scende, in proporzione alla CPU, a circa un
quarto (stima: 25–30 secondi; da misurare nella prova generale). Il totale in euro
è identico al centesimo (in prova: 9.607.701,51 €).

**Da spiegare:** il worker `commissions` è un container separato con il proprio
Horizon. Si ridimensiona da solo, senza toccare il sito o le code ordinarie.
Il calcolo notturno parte dal cron in `.upsun/config.yaml`; il pulsante e il
comando artisan usano lo stesso codice.

**Trasparenza:** le regole demo sono leggere. Il parametro
`COMMISSION_COMPLEXITY` ripete la valutazione delle regole per rendere il batch
abbastanza pesante da vedere l'effetto. È scritto nel codice e nel README.

> Collegamento: il batch delle provvigioni sulla gerarchia multilivello ha il suo
> worker, che si potenzia per la notte di chiusura senza toccare il resto.

---

## Scena 6 — Broker non disponibile (simulato) e ripristino (15 min)

**Premessa da dire:** "Su Upsun Cloud Kafka è un singolo container per
ambiente, non un cluster a tre nodi. Allora progettiamo l'applicazione in modo
che, se il broker non risponde, gli eventi arrivino in ritardo ma non vadano mai
persi o duplicati. Non possiamo spegnere un servizio gestito a comando:
simuliamo l'interruzione."

**Passo 1:** su `$URL/admin` premere **"Simula broker non disponibile"**. Il
riquadro diventa rosso.

**Passo 2:** generare ordini, a mano da `/ordini/nuovo` o con un piccolo carico:

```bash
k6 run -e BASE_URL="https://demo:<password>@${URL#https://}" -e PEAK_RPS=5 -e RAMP=10s -e HOLD=50s load/k6/peak-evening.js
```

**Cosa vede il pubblico:**

- Gli ordini vengono accettati normalmente (risposte 201, pagina "Ordine registrato").
- Il backlog dell'outbox di NFW cresce; compare l'errore reale di librdkafka
  ("Message timed out").
- "Ordini in attesa di spedizione" cresce; le spedizioni OMS sono ferme.
- Nei log del relay il backoff esponenziale:
  `upsun log -e main --app nfw --worker relay --tail` (oppure
  `upsun ssh -e main --worker nfw--relay` se il filtro `--worker` vuole il nome completo).

**Passo 3:** premere **"Ripristina il broker"**.

**Cosa vede il pubblico:** entro pochi secondi il backlog si svuota (in prova su
`main`: 60 ordini, svuotato in 11 secondi; il relay riprova con attese crescenti
fino a 30 secondi, quindi dopo un'interruzione lunga può servire mezzo minuto), le spedizioni
raggiungono gli ordini, "Duplicati scartati" mostra eventuali ripetizioni
assorbite, e compare "Tutti gli ordini sono arrivati all'OMS, nessuno due volte".
Su `/ordini` gli stati passano a "Spedito" e le fatture sono scaricabili.

**Spiegazione in 4 punti (con il codice aperto in `apps/nfw/app/Messaging`):**
outbox nella stessa transazione; relay che segna inviato solo dopo la conferma
del broker; consumer idempotenti con `processed_events`; worker riavviati dalla
piattaforma.

> Collegamento: il requisito del broker HA in tre nodi diventa un requisito
> sull'applicazione: un'interruzione produce solo ritardo, e il comportamento si
> può dimostrare e testare.

---

## Scena 7 — Operatività quotidiana (10 min)

```bash
# Log
upsun log -e main --app nfw app --lines 50
upsun log -e main --app nfw cron                  # esecuzioni del cron provvigioni
upsun log -e main --app oms --worker consumer --tail

# SSH in un container applicativo e in un worker
upsun ssh -e main --app nfw -- php artisan about
upsun ssh -e main --app oms --worker horizon -- php artisan horizon:status

# Dump del database di un'app (solo il suo schema)
upsun db:dump -e main --app oms --relationship database --gzip

# Cron e attività
upsun activity:list -e main --limit 10

# Backup
upsun backup:create -e main --live
upsun backup:list -e main
```

Mostrare anche, nella console, la pagina dell'ambiente: risorse, metriche, attività.

**Cosa non devono fare (da elencare):**

- installare o aggiornare sistema operativo, PHP, MariaDB, Valkey, Kafka;
- applicare patch di sicurezza ai servizi;
- configurare Nginx, PHP-FPM, Supervisor, logrotate;
- costruire e mantenere ambienti di staging;
- organizzare i backup;
- gestire i certificati TLS.

> Collegamento: il tempo dei 15 sviluppatori resta sul prodotto per il cliente,
> non sulla manutenzione dei server.

---

## Piano B

| Problema | Cosa fare |
|---|---|
| Il deploy della scena 2 è lento | Mostrare un'attività precedente con `upsun activity:log <id>`; riprendere più tardi |
| L'ambiente PR non è pronto | Proseguire con la scena 4 e tornare alla 3 alla fine |
| La run di Dispatch è fallita o il codice non convince | Preparare la stessa PR a mano dal branch `feature/cofanetto-viso`: una migrazione che inserisce la regola (come `2026_01_01_000002_seed_promotion_rules.php`) e un test. Nella scena dire che la PR è scritta a mano: è proprio il caso in cui Dispatch non si usa |
| Dispatch non risponde al commento | Il commento deve contenere solo `@upsun-dispatch implement` (o `review`), senza altro testo; controllare la run nella console di Dispatch |
| k6 non raggiunge il sito | Verificare credenziali HTTP e `$URL`; in alternativa generare ordini dal browser |
| Il calcolo provvigioni resta "queued" | `upsun ssh -e main --app nfw --worker commissions -- php artisan horizon:status`; controllare il nome e la dimensione del worker con `upsun resources:get` |
| Il backlog non si svuota dopo il ripristino | Controllare che il pannello mostri "broker raggiungibile"; il relay riprova al massimo ogni 30 secondi |

## Dopo la sessione

```bash
upsun resources:set -e main --size nfw:0.5 --count nfw:1 --size nfw--commissions:0.5
upsun environment:list            # eliminare eventuali ambienti PR rimasti
```
