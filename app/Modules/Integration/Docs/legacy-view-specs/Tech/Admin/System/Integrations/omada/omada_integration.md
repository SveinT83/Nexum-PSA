# Omada Controller Integrasjon (v1)

Denne dokumentasjonen beskriver integrasjonen mellom tdPSA og TP-Link Omada Controller for innhenting av nettverksenheter og varsler.

---

## 1. Oversikt
Versjon 1 av integrasjonen er strengt **skrivebeskyttet (read-only)**. Systemet poller Omada Controller API for å:
*   Opprette og oppdatere Assets (nettverksutstyr).
*   Hente inn varsler og hendelser som mates inn i tdPSA Rule Engine.

---

## 2. Tekniske Krav
For å aktivere integrasjonen kreves følgende:
*   **Controller URL:** Fullstendig adresse til Omada Controller (f.eks. `https://omada.eksempel.no`).
*   **Brukernavn/Passord:** En bruker med lesetilgang til de aktuelle sitene.
*   **Site Name/ID:** Spesifikk site som skal overvåkes (v1 støtter 1 site per integrasjon).

---

## 3. Asset Mapping
Enheter fra Omada blir mappet til tdPSA Assets etter følgende logikk:

| Omada Felt | Asset Felt | Kommentar |
| :--- | :--- | :--- |
| Device ID | external_id | Unik identifikator fra controller |
| Name | name | Navn konfigurert i Omada |
| IP Address | ip_address | Primær IP-adresse |
| MAC | mac_address | Brukes for matching hvis ID mangler |
| Model | model | F.eks. EAP650, TL-SG3428X |
| Status | status | Online/Offline mapping |

### Matching-regler
1.  Først forsøkes det å matche på `external_id` + `integration_id`.
2.  Fallback er matching på `mac_address`.
3.  Eksisterende assets blir oppdatert med siste kjente data (IP, status, etc.).

---

## 4. Varslingshåndtering
Varsler (Alerts) hentes periodisk og prosesseres slik:
1.  **Ingestion:** Varsler hentes og lagres rått i `integration_alerts`.
2.  **Deduplisering:** Basert på Omada sin `external_id`.
3.  **Rule Engine:** Varslet sendes til regelmotoren som avgjør om det skal opprettes en Ticket, merges med eksisterende, eller ignoreres.

---

## 5. Konfigurasjon og Synkronisering
*   **Polling Intervall:** Standard er 60 sekunder (konfigurerbart).
*   **Default Site:** Alle enheter fra denne integrasjonen blir knyttet til denne siten i tdPSA hvis ikke spesifikk mapping finnes.
*   **Default Queue:** Køen hvor Tickets opprettet fra Omada-varsler lander.

---

## 6. Veien Videre (v2+)
*   Støtte for flere siter per integrasjon.
*   Webhooks for sanntidsoppdateringer.
*   Fjernstyring (reboot, port management) direkte fra tdPSA.
*   Topologi-visualisering.
