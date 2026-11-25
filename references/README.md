# Proiecte și Biblioteci de Referință (`references/`)

Acest director conține referințe open-source descărcate pentru faza de **proiectare, analiză și inspirație arhitecturală** a aplicației modulare ITP.

> **Notă Git:** Folderele `.git` interne ale repository-urilor clonate au fost eliminate astfel încât Git-ul nostru să urmărească direct fișierele sursă. De asemenea, pentru `Tabler` și `FullCalendar` nu s-au clonat monorepo-urile sursă (~394 MB, respectiv ~31 MB), ci s-au salvat pachetele compilate gata de utilizare offline, păstrând repository-ul rapid și ușor de urcat pe GitHub.

---

## 1. Baza Modulară, FastAPI, RBAC și Audit Log (`references/core-fastapi/`)

| Proiect | Folder local | Sursă GitHub | Commit SHA | Licență | Ce analizăm / preluăm |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **crudadmin** | `core-fastapi/crudadmin` | https://github.com/benavlabs/crudadmin | `7acaf0b` | MIT | Autentificare cu sesiune/cookie, RBAC (admin vs. utilizatori restrânși), **Audit Log / event tracking**, integrare HTML + HTMX. |
| **fastapi-modular-monolith-starter-kit** | `core-fastapi/fastapi-modular-monolith-starter-kit` | https://github.com/arctikant/fastapi-modular-monolith-starter-kit | `9d30883` | MIT | Structură de **monolit modular** în FastAPI, separare curată între nucleu și module. |
| **base-fastApi** | `core-fastapi/base-fastApi` | https://github.com/amariwan/base-fastApi | `8f72b3d` | MIT | Cicluri de viață modulare, RBAC fin, ACL pe resurse și suport SQLite. |
| **fastapi-rbac** | `core-fastapi/fastapi-rbac` | https://github.com/kwantabit-Technologies/fastapi-rbac | `9919914` | MIT | Model de roluri și permisiuni stocate dinamic în baza de date. |
| **fastapi-htmx-starter** | `core-fastapi/fastapi-htmx-starter` | https://github.com/yashhere/fastapi-htmx-starter | `beffaf2` | MIT | Tipare de răspunsuri HTML parțiale cu FastAPI + Jinja2 + HTMX fără build step. |

---

## 2. Domeniu Auto, Inspecții și Programări (`references/auto-domain/`)

| Proiect | Folder local | Sursă GitHub | Commit SHA | Licență | Ce analizăm / preluăm |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **autofix_backend** | `auto-domain/autofix_backend` | https://github.com/faizansaiyed123/autofix_backend | `2a4d6ba` | *Fără licență explicită* *(doar studiu arhitectural)* | Structură de date pentru clienți, vehicule, kilometraj, verificare suprapuneri în calendar, inspecții digitale cu poze/rapoarte. *(Nu se copiază cod direct fără clarificarea licenței.)* |
| **car-carer** | `auto-domain/car-carer` | https://github.com/Greal-dev/car-carer | `aef85c5` | MIT | Exemplu simplu de aplicație locală self-hosted în FastAPI + SQLite pentru evidența mașinilor. |
| **automotive-repair-management-system** | `auto-domain/automotive-repair-management-system` | https://github.com/ChanMeng666/automotive-repair-management-system | `9cf66a6` | MIT | Separarea fluxurilor și ecranelor între Administrator și Tehnician/Inspector. |

---

## 3. Biblioteci UI Offline (`references/ui-libs/`)

Păstrate local pentru funcționare 100% fără internet în rețeaua stației ITP (aspect **modern luminos**, responsive PC + telefon):

| Bibliotecă | Folder local | Sursă | Licență | Rol în proiect |
| :--- | :--- | :--- | :--- | :--- |
| **Tabler Core** (`tabler.min.css`, `tabler.min.js`) | `ui-libs/tabler` | https://github.com/tabler/tabler (`@tabler/core` via jsDelivr) | MIT | Shell HTML/CSS luminos, sidebar PC + meniu mobil, carduri, tabele și formulare responsive. |
| **FullCalendar v6** (`index.global.min.js`) | `ui-libs/fullcalendar` | https://github.com/fullcalendar/fullcalendar (`fullcalendar@6` via jsDelivr) | MIT | Componentă calendar zi/săptămână pentru viitorul modul de programări. |
| **HTMX v2** (`htmx.min.js`) | `ui-libs/htmx` | https://github.com/bigskysoftware/htmx (`htmx.org@2` via jsDelivr) | BSD-2-Clause | Actualizări rapide de interfață din FastAPI fără JavaScript complex. |

---

## 4. Proiectele Tale de pe GitHub (`references/my-projects/`)

*Secțiune pregătită pentru repository-urile personale pe care le vei indica ca referință — le vom descărca aici înainte sau după primul push, pentru analiză comparativă.*
