# Propuneri de evolutie a produsului si UX

**Aplicatie:** ITP-Programari — ACW Falticeni  
**Data analizei:** 28.11.2026  
**Statut:** document de propuneri; nimic din acest document nu este aprobat sau implementat  
**Scop:** identificarea unor imbunatatiri care fac aplicatia mai informativa si reduc munca operatorilor, fara sa o incarce cu functionalitati inutile.

> Termenii tehnici si etichetele de interfata sunt pastrate asa cum apar in cod; recomandarile sunt puncte de discutie, nu cerinte de implementare.

## 1. Concluzie executiva

Aplicatia are deja un nucleu operational consistent. Operatorul poate consulta agenda si ocuparea liniilor, cauta programari, crea si modifica programari, gestiona reveniri si termene, contacta clientul prin linkuri precompletate si consulta istoricul. Exista administrare a utilizatorilor si permisiunilor, jurnal de securitate si generare/descarcare de backup.

Prin urmare, prioritatea nu este construirea unui modul nou sau a unui CRM separat. Cele mai promitatoare imbunatatiri sunt:

1. sa se vada dintr-o privire cate programari sunt in fiecare stare;
2. sa iasa in evidenta revenirile care se apropie de termen;
3. sa existe, daca operatorii au nevoie, o evidenta manuala si simpla a confirmarilor clientilor;
4. sa fie usor de raportat neprezentarile fara a inventa un flux nou de stergere;
5. sa poata fi restaurat in siguranta un backup.

Primele patru imbunatatesc activitatea curenta sau raportarea. Restaurarea este rara, dar importanta pentru recuperarea datelor.

## 2. Baza analizei: ce exista deja

Constatarile de mai jos provin din interfata curenta si din cod. Nu sunt recomandari de adaugare; sunt functii deja existente, care trebuie pastrate si reutilizate.

### Agenda si programari

- Agenda este selectabila pe data si afiseaza programarile in ordine cronologica.
- Sunt afisate ocuparea si locurile libere pe linii, filtre de status si un filtru dedicat pentru revenirile deschise.
- Programarile viitoare pot fi afisate separat fata de agenda zilei.
- Agenda se reimprospateaza periodic; aplicatia afiseaza starea conexiunii si reactioneaza la revenirea online.
- Formularul verifica locurile disponibile pe data, ora si linie. Daca linia aleasa este plina la deschiderea formularului, poate selecta automat o linie alternativa.
- Verificarea numarului auto poate completa datele ultimei vizite, semnaleaza un duplicat in aceeasi zi si detecteaza o revenire deschisa.
- Exista actiuni rapide pentru apel, SMS si WhatsApp. Mesajele sunt precompletate, dar comunicarea are loc in aplicatia externa.

Referinte: [agenda si fluxurile UI](../assets/js/app.js), [logica de programari](../modules/programari/AppointmentService.php), [interogarile agendei si istoricului](../modules/programari/AppointmentRepo.php).

### Reveniri, istoric si siguranta

- Revenirea are motive, nota, termen si legatura cu reprogramarea ulterioara.
- Termenele expirate sunt marcate in agenda; revenirile deschise pot fi afisate si ordonate dupa termen.
- Modificarile sunt pastrate in istoricul programarii, cu filtre si export CSV.
- Anularea/stergera este soft-delete cu motiv, nu eliminare nedetectabila din istoric.
- Administrarea include utilizatori, permisiuni, setari, backup-uri si jurnal de securitate.

Referinte: [logica revenirilor si istoricului](../modules/programari/AppointmentService.php), [schema programarilor si motivele de stergere](../modules/programari/migrations/001_init.sql), [BackupService](../core/BackupService.php).

### Limite functionale confirmate

- Exista linkuri de contact precompletate, dar nu un status persistent care sa indice ca programarea a fost confirmata de client.
- Motivul „Neprezentare (No-show)” exista la stergere. Datele se pastreaza, dar nu am identificat un raport operational dedicat neprezentarilor.
- Administratorul poate genera si descarca backup-uri. Serviciul curent nu expune restaurare. Pentru restaurare exista deja un document arhitectural separat: [PLAN_BACKUP_RESTORE.md](./PLAN_BACKUP_RESTORE.md).

## 3. Principii de produs

1. **Imbunatatim agenda existenta inainte sa cream ecrane noi.** Operatorul lucreaza deja in agenda; un dashboard separat ar adauga navigare.
2. **Informatiile noi trebuie sa duca la o actiune clara.** Un numar sau un avertisment trebuie sa deschida lista relevanta, nu doar sa ocupe spatiu.
3. **Nu presupunem automatizari pe care aplicatia nu le poate confirma.** Deschiderea WhatsApp/SMS nu dovedeste livrarea sau confirmarea mesajului.
4. **Nu schimbam fluxurile de date istorice fara nevoie.** Istoricul imutabil si soft-delete-ul sunt parte din siguranta operationala.
5. **Orice stare noua trebuie sa aiba un sens operational stabil.** Operatorii trebuie sa stie cand si de cine se modifica.
6. **Functionalitatile rare, dar cu risc mare, raman separate de fluxul zilnic.** Restaurarea backup-ului este exclusiv administrativa si protejata.
7. **Prioritatile se valideaza cu operatorii.** Nu se adauga pasi obligatorii doar pentru a obtine rapoarte mai frumoase.

## 4. Top 5 propuneri

### Propunerea 1 — Rezumatul de lucru al zilei in agenda

**Prioritate:** P1 — valoare zilnica, schimbare relativ mica  
**Efort estimat:** S–M  
**Utilizatori:** operatori si administrator

#### Situatia actuala

Agenda afiseaza ocuparea pe linii si numarul locurilor libere. Exista butoane de filtrare pentru „Programat”, „Finalizat”, „Revenire” si „Reveniri deschise”. In forma analizata, filtrele obisnuite nu indica numarul de rezultate; doar filtrul revenirilor deschise include un numar.

#### Nevoia

Operatorul trebuie sa scaneze cardurile sau sa apese filtre ca sa inteleaga rapid volumul zilei si cate programari mai trebuie procesate. Aceasta propunere nu cere un ecran nou, ci face mai explicita informatia deja disponibila.

#### Comportament propus

- Afisarea numarului pentru fiecare filtru de status, de exemplu „Programat (N)”, „Finalizat (N)” si „Revenire (N)”.
- Pastilutele raman actionabile: o atingere aplica filtrul respectiv.
- Ocuparea pe linii si locurile libere raman separate de numarul programarilor; „locuri libere” nu trebuie confundat cu „programari ramase”.
- Numaratoarea este pentru data selectata, nu pentru toate datele, si este etichetata corespunzator.
- La cautare globala, interfata fie arata numarul rezultatelor cautarii, fie ascunde sumarul zilei. Nu trebuie sa amestece rezultatele cautarii cu numarul zilei.
- Contoarele raman stabile cand operatorul schimba filtrul; nu se recalculeaza accidental doar din lista deja filtrata.

#### Beneficiu asteptat

- Mai putine atingeri pentru a afla cate vehicule sunt programate, finalizate sau in revenire.
- Operatorul poate observa rapid o zi aglomerata sau o stare care necesita atentie.
- Nu este nevoie de un dashboard separat.

#### Note de implementare

- Datele necesare exista in raspunsul agendei, dar acesta poate fi filtrat dupa status. Contoarele trebuie obtinute dintr-un set nefiltrat sau printr-un rezumat calculat separat; nu trebuie calculate din subsetul filtrat.
- Definiti clar daca o vizita de tip revenire se numara dupa `status`, dupa `visit_type` sau in ambele contexte. Interfata nu trebuie sa dubleze aceeasi vizita.
- Pentru intervale cu programari viitoare, precizati daca sunt incluse in contoarele zilei selectate.
- Nu adaugati un al doilea set de statistici pentru ocupare, deja afisata pe linii.

#### Criterii de acceptare

- Pentru o data selectata, contoarele corespund exact programarilor active ale acelei date, grupate dupa starile afisate.
- Stergerea logica nu este numarata ca programare activa.
- Schimbarea filtrului nu face sa dispara sau sa se modifice totalurile de baza.
- O cautare pe toate zilele nu este prezentata ca si cum ar fi un sumar al zilei selectate.
- Atingerea unei pastilute filtreaza lista corespunzator.
- Nu se introduce scroll orizontal si nu se micsoreaza tintele tactile existente.

#### Decizie de cerut operatorilor

Este suficient sa se vada numerele in filtre sau este util si un singur rezumat compact deasupra agendei? Recomandarea initiala este doar numere in filtre, pentru a evita un panou suplimentar.

---

### Propunerea 2 — Evidentierea revenirilor care expira curand

**Prioritate:** P1 — urmarirea termenelor poate preveni pierderea unei reveniri  
**Efort estimat:** S–M  
**Utilizatori:** operatori cu permisiune de gestionare a revenirilor

#### Situatia actuala

Aplicatia stocheaza termenul revenirii, afiseaza revenirile deschise si marcheaza termenele expirate pe card. Lista de reveniri deschise este ordonata dupa termen. Aceste comportamente trebuie pastrate; nu este necesar un mecanism nou de notificare externa.

#### Nevoia

Un termen deja expirat este vizibil in card, dar operatorul poate sa nu observe o revenire care urmeaza sa expire daca nu deschide lista dedicata. Un indicator sumar poate aduce exceptiile in atentia operatorului fara notificari intruzive.

#### Comportament propus

- In agenda, un rezumat compact al revenirilor deschise cu termen expirat si al celor care se apropie de termen.
- Rezumatul este actionabil si deschide filtrul existent „Reveniri deschise”, ordonat dupa termen.
- Cardurile pastreaza etichetele de termen deja existente; nu se repeta acelasi mesaj in mai multe zone.
- Nu se trimit notificari automate, SMS-uri sau mesaje WhatsApp in cadrul acestei propuneri.

#### Decizii de produs necesare

- Ce inseamna „curand”: de exemplu, termenul de astazi sau urmatoarele 3 zile. Acest interval este o ipoteza, nu o cerinta confirmata.
- Zilele se calculeaza calendaristic sau dupa zilele lucratoare ale statiei?
- Pentru o zi limita inchisa, se foloseste termenul legal/salvat sau ultima zi lucratoare afisata de aplicatie? UI-ul existent poate afisa o nota cand termenul cade intr-o zi inchisa; noul indicator trebuie sa foloseasca aceeasi regula.
- Se afiseaza numerele doar pentru reveniri neexpirate sau si un numar separat pentru cele expirate?

#### Beneficiu asteptat

- Reduce riscul ca operatorul sa observe revenirea abia dupa expirare.
- Pastreaza fluxul in agenda, fara o sectiune sau o notificare suplimentara.
- Se bazeaza pe date deja pastrate, fara introducerea unei stari noi.

#### Note de implementare

- Refolositi termenul si logica deja utilizate de cardurile de revenire.
- Excludeti revenirile rezolvate sau reprogramate si cele fara termen valid.
- Nu recalculati separat termenul in UI si backend; folositi aceeasi regula.
- Lista de destinatie trebuie sa arate motivul, vehiculul, termenul si actiunea permisa utilizatorului.

#### Criterii de acceptare

- Sunt numarate numai revenirile deschise, nereprogramate/nesolutionate, conform regulii de produs aprobate.
- Sunt acoperite cazurile: termen azi, termen in fereastra aleasa, termen expirat, zi inchisa si revenire rezolvata.
- Apasarea sumarului deschide lista filtrata corect.
- Nu sunt trimise mesaje si nu sunt create programari automat.
- Numarul sumarului corespunde listei rezultate, inclusiv dupa polling/reimprospatare.

---

### Propunerea 3 — Urmarirea manuala a confirmarii clientului

**Prioritate:** P2 — utila daca operatorii pierd evidenta confirmarilor  
**Efort estimat:** M  
**Utilizatori:** operatori; administratorul poate analiza starea in istoric

#### Situatia actuala

Pe cardurile programarilor exista linkuri pentru apel, SMS si WhatsApp, iar mesajele sunt precompletate. Linkurile deschid aplicatii externe; aplicatia ITP-Programari nu poate confirma daca mesajul a fost trimis, livrat sau citit. In datele programarii nu am identificat o stare persistenta „confirmata de client”.

#### Nevoia

Daca statia contacteaza clientii pentru confirmarea programarilor, operatorul poate avea nevoie sa stie ce programari mai necesita urmarire. Un marcaj manual poate reduce apelurile repetate sau programarile uitate.

#### Comportament propus

Inainte de implementare, validati cu operatorii daca au nevoie de:

- o stare minima „Neconfirmata / Confirmata”; sau
- o stare in trei etape „De contactat / Contactat / Confirmat”.

Recomandarea initiala este sa se inceapa cu cea mai simpla stare care rezolva problema reala. Actiunile posibile ar putea fi:

1. Operatorul deschide mesajul precompletat existent.
2. Dupa discutia cu clientul, operatorul marcheaza manual programarea ca „Confirmata” sau o lasa „Neconfirmata”.
3. Un filtru optional afiseaza programarile neconfirmate.
4. Schimbarea este inregistrata cu utilizatorul si momentul modificarii.

#### Limite si riscuri

- Nu marcati programarea ca trimisa automat doar pentru ca s-a deschis WhatsApp/SMS.
- Starea manuala poate deveni invechita daca operatorul confirma telefonic si uita sa o actualizeze.
- O actiune obligatorie pentru fiecare programare poate incetini lucrul; nu o faceti obligatorie fara validare.
- Mesajele contin date personale; pastrati comunicarea in canalele si permisiunile existente.

#### Note de implementare

- Daca se aproba, schimbarea trebuie persistata si jurnalizata; nu folositi doar o stare in browser.
- Stabiliti cum se comporta starea la reprogramare, anulare, schimbare de numar sau creare de vizita revenire.
- Pastrati distincte „contactat” si „confirmat”; apelul initiat nu inseamna confirmare.
- Evaluati daca evenimentul intra in `appointment_history` sau intr-un camp dedicat, fara a permite modificarea istoricului imutabil.

#### Criterii de acceptare

- Un status nu se schimba fara actiune explicita a operatorului.
- Se poate filtra lista neconfirmata fara a pierde filtrele datei/liniei relevante.
- Istoricul indica cine a schimbat statusul si cand.
- Modificarea numarului sau reprogramarea nu prezinta o confirmare veche ca fiind valida fara o regula explicita.
- Fluxurile existente de apel, SMS si WhatsApp raman disponibile.

---

### Propunerea 4 — Raport pentru neprezentari si anulări

**Prioritate:** P2 — valoare de organizare/raportare, nu actiune zilnica pentru fiecare card  
**Efort estimat:** S–M daca se folosesc datele existente  
**Utilizatori:** administrator si operatori care urmaresc gradul de prezentare

#### Situatia actuala

Motivul „Neprezentare (No-show)” exista printre motivele de stergere. Programarile sunt sterse logic, iar evenimentele raman in istoricul imutabil. Prin urmare, no-show-ul este deja inregistrabil, dar nu am identificat o vedere sumara dedicata pentru a-l compara pe zile sau perioade.

#### Nevoia

Daca statia vrea sa inteleaga cate programari se pierd prin neprezentare si ce intervale sunt afectate, cautarea manuala prin istoricul fiecarei programari este prea lenta.

#### Comportament propus

- Adaugati in Istoric un filtru sau un sumar pentru motivul existent „Neprezentare (No-show)”.
- Permiteti alegerea unei perioade si afisati totalul, cu acces la lista inregistrarilor.
- Diferentiati neprezentarea de anularea de catre client si de stergerea din greseala.
- Refolositi exportul CSV existent pentru rezultate filtrate daca formatul poate include clar motivul.
- Nu introduceti un status nou „Neprezentat” in agenda daca motivul actual acopera raportarea.

#### Note de implementare

- Agregati dupa motivul structurat al evenimentului de stergere, nu prin potrivire fragila a textului liber din rezumat.
- Folositi data programarii sau data anularii? Raportul trebuie sa eticheteze explicit alegerea; ideal, ambele pot fi disponibile ca filtre distincte.
- Restrictionati datele personale afisate la ce este necesar raportului.
- Nu afisati programarea stearsa inapoi in agenda activa doar pentru ca este inclusa in raport.

#### Criterii de acceptare

- Totalul coincide cu numarul inregistrarilor care au motivul selectat si se incadreaza in perioada.
- Modificarea motivelor administrative nu rescrie evenimente istorice existente.
- Exportul include motivul si datele necesare reconcilierii cu lista.
- Anularile obisnuite nu sunt clasificate automat ca no-show.

#### Decizie de cerut

Raportul este necesar pentru operarea zilnica sau doar pentru analiza lunara? Daca nu exista o decizie de business legata de date, se poate amana.

---

### Propunerea 5 — Restaurare sigura a backup-ului

**Prioritate:** P1 pentru continuitatea datelor; utilizare rara  
**Efort estimat:** L  
**Utilizatori:** Administrator exclusiv

#### Situatia actuala

Administratorul poate genera si descarca snapshot-uri. Codul curent listeaza, creeaza si serveste fisiere backup, dar nu expune un flux de restaurare. Exista deja o proiectare detaliata in [PLAN_BACKUP_RESTORE.md](./PLAN_BACKUP_RESTORE.md); recomandarea este sa fie reutilizata, nu sa se proiecteze un al doilea mecanism.

#### Scop

Sa poata fi recuperate datele operationale dupa o eroare grava, cu un flux care previne suprascrierea accidentala si confirma inainte ce date urmeaza sa fie afectate.

#### Flux recomandat

1. Doar Administratorul acceseaza functia.
2. Fisierul JSON este incarcat si validat fara scrieri in baza de date.
3. Interfata prezinta data/autorul backup-ului, versiunea, numarul de inregistrari si avertismentele de compatibilitate.
4. Administratorul vede o comparatie cu baza curenta si alege explicit restaurare completa sau completare/merge, daca aceasta ramane sustinuta.
5. Serverul face automat un backup pre-restaurare.
6. Pentru executie, Administratorul confirma cu PIN-ul cerut si o actiune explicita.
7. Operatia se executa atomic; la eroare, baza revine la starea anterioara.
8. Incercarile reusite si esuate sunt inregistrate in jurnal, iar sesiunile invalide sunt inchise conform proiectarii.

#### Cerinte de siguranta

- Verificati structura, tabelele permise, versiunea si integritatea fisierului; refuzati fisiere invalide inainte de orice scriere.
- Creati backup-ul pre-restaurare inainte de modificarea bazei.
- Pastrati hash-urile PIN actuale, deoarece snapshot-urile actuale le exclud, si nu restaurati sesiuni active.
- Folositi tranzactie/rollback si tratati corect trigger-ele si cheile straine.
- Cereti confirmare explicita pentru o restaurare completa si descrieti clar pierderea potentiala a datelor mai noi.
- Nu expuneti fisierul uploadat sau continutul lui utilizatorilor neautorizati.

#### Criterii de acceptare

- Dry-run-ul nu modifica baza de date.
- Backup invalid, incomplet sau incompatibil este refuzat cu mesaj clar si fara schimbari partiale.
- Backup-ul pre-restaurare exista inaintea operatiei de scriere.
- O eroare simulata produce rollback complet.
- Restaurarea pastreaza credentele conform politicii si nu reactiveaza sesiuni vechi.
- Jurnalul consemneaza reusita/esecul fara a inregistra secrete.
- Sunt testate separat scenariile de restaurare completa si merge, daca ambele sunt pastrate.

Referinta existenta: [BackupService.php](../core/BackupService.php), [AdminController.php](../core/controllers/AdminController.php) si [planul de restaurare](./PLAN_BACKUP_RESTORE.md).

## 5. Idei secundare, conditionate de nevoia operatorilor

### Istoric sumar al vehiculului in formularul de programare

Cautarea dupa numarul auto completeaza deja datele ultimei vizite si semnaleaza o revenire deschisa sau un duplicat. Istoricul complet poate fi cautat in ecranul Istoric. O sectiune restransa cu ultimele 2–3 vizite ar putea evita navigarea intre ecrane, dar ar dubla partial functionalitati existente si ar expune mai multe date clientului pe ecran. Se recomanda doar daca operatorii spun ca au nevoie frecvent de aceasta informatie inainte de programare.

### Nota generala pentru programare

Datele actuale au campuri pentru motivul revenirii si verificari specifice revenirii, dar nu o nota operationala generala pentru orice programare. Un astfel de camp poate ajuta la instructiuni concrete, dar nu trebuie folosit pentru date sensibile sau note libere fara reguli de retentie si vizibilitate. Intai se valideaza exemple reale care nu pot fi reprezentate in campurile existente.

### Tiparire/export agenda

Istoricul si jurnalul au exporturi, dar in rutele analizate nu am identificat un export al agendei zilei. Este util numai daca operatorii tiparesc sau transmit regulat lista zilnica; altfel, adauga intretinere fara beneficiu clar.

## 6. Ce nu recomand in etapa urmatoare

- **Portal public de programari:** necesita reguli de acces, confirmare, protectie contra spamului si suport pentru clienti; nu este necesar pentru a simplifica fluxul intern.
- **Trimitere automata WhatsApp/SMS:** linkurile precompletate exista deja; automatizarea ar cere furnizor, acorduri, tratarea esecurilor si gestionarea consimtamantului.
- **CRM complet, atasamente sau documente pentru fiecare vehicul:** ar duplica datele care se pot cauta in istoric si ar creste volumul de date personale.
- **Coada de scrieri offline:** aplicatia afiseaza starea offline, dar doua dispozitive pot rezerva acelasi slot. Sincronizarea offline ar necesita rezolvarea conflictelor si nu este o imbunatatire simpla.
- **Dashboard separat:** informatiile de baza sunt deja in agenda; e preferabil ca sumarul sa fie integrat acolo.
- **Status nou pentru fiecare situatie rara:** folositi motivele si evenimentele existente daca ele pot sustine raportarea, inainte de a modifica modelul de date.

## 7. Ordine recomandata de validare si livrare

### Faza A — Confirmare cu operatorii

Discutati scurt cu persoanele care folosesc aplicatia:

- Ce intrebare operationala isi pun de mai multe ori pe zi?
- Se uita clientii confirmarile programarilor?
- Ce termene de revenire trebuie urmarite in avans si cu cate zile?
- Este necesara raportarea neprezentarilor?
- Cine ar folosi restaurarea si in ce scenariu?

Nu se implementeaza o stare manuala pana cand echipa confirma ca o va actualiza consecvent.

### Faza B — Castiguri mici, fara modificari de flux

1. Adaugati contoare de status in agenda, dupa definirea exacta a numaratorii.
2. Adaugati sumarul termenelor apropiate numai dupa alegerea ferestrei si a regulii zilelor inchise.

Acestea au cel mai mic risc deoarece folosesc date deja disponibile si nu cer operatorului o actiune noua la fiecare programare.

### Faza C — Raportare si contact

1. Validati daca evidenta manuala a confirmarilor ar fi folosita.
2. Daca da, alegeti cea mai simpla stare si pastrati jurnalul modificarilor.
3. Adaugati raportul de no-show numai daca exista o decizie operationala care se va baza pe el.

### Faza D — Restaurare

Tratarea backup-ului este un proiect separat, cu revizie de siguranta, testarea backup-urilor reale anonimizate si test de recuperare. Nu se amesteca cu mici ajustari UI.

## 8. Cum se masoara daca imbunatatirile ajuta

Inainte de implementare, stabiliti o perioada scurta de observare a fluxului curent si o modalitate simpla de comparatie. Nu se inventeaza tinte numerice fara o baza de masurare.

Masuratori posibile:

- timpul si numarul de atingeri pentru a afla cate programari mai sunt de procesat;
- timpul pentru a gasi revenirea cu termenul cel mai apropiat;
- numarul de programari neconfirmate ramase la sfarsitul zilei, daca echipa decide sa le urmareasca;
- timpul necesar pentru a obtine un total de neprezentari pe o perioada;
- reusita restaurarii intr-un mediu de test, timpul si completitudinea datelor recuperate.

Masuratorile nu trebuie sa colecteze continutul mesajelor clientilor sau date personale suplimentare doar pentru analiza UX.

## 9. Decizii necesare inainte de orice implementare

1. Sunt utile contoarele de status in agenda? Ce stari trebuie afisate?
2. Ce inseamna „revenire care expira curand” si se folosesc zile calendaristice sau lucratoare?
3. Operatorii vor marca manual o programare ca fiind confirmata? Este suficienta o stare „Neconfirmata/Confirmata”?
4. Este raportul de neprezentari folosit pentru planificare, sau motivul existent din istoric este suficient?
5. Restaurarea backup-ului trebuie sa suporte atat restaurare completa, cat si merge, conform planului existent?

**Regula de lucru:** documentul nu autorizeaza modificari. Fiecare propunere se aproba separat; se implementeaza un singur flux odata, se masoara inainte/dupa si se pastreaza comportamentele deja utile.
