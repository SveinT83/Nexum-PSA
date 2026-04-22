# UniFi Controller Integrasjon (v1)

Denne dokumentasjonen beskriver integrasjonen mellom tdPSA og Ubiquiti UniFi Controller for innhenting av nettverksenheter og varsler.

---

## 1. Oversikt
Versjon 1 av integrasjonen er strengt **skrivebeskyttet (read-only)**. Systemet poller UniFi Controller API for å:
*   Opprette og oppdatere Assets (nettverksutstyr).
*   Hente inn varsler og hendelser som mates inn i tdPSA Rule Engine.

---

## 2. Tekniske Krav
For å aktivere integrasjonen kreves følgende:
*   **Controller URL:** Fullstendig adresse til UniFi Controller (f.eks. `https://unifi.eksempel.no`).
*   **Brukernavn/Passord:** En lokalt opprettet bruker eller API-nøkkel (avhengig av controller-versjon).
*   **Site Name:** Navnet på siten i UniFi (f.eks. `default`).

---

## 3. Asset Mapping
Enheter fra UniFi blir mappet til tdPSA Assets etter følgende logikk:

| UniFi Felt | Asset Felt | Kommentar |
| :--- | :--- | :--- |
| _id / device_id | external_id | Unik identifikator fra controller |
| name / hostname | name | Navn konfigurert i UniFi |
| ip | ip_address | Primær IP-adresse |
| mac | mac_address | Brukes for matching |
| model | model | F.eks. U6-Pro, USW-Lite-16-PoE |
| state | status | Online/Offline/Pending mapping |

### Matching-regler
1.  Først forsøkes det å matche på `external_id` + `integration_id`.
2.  Fallback er matching på `mac_address` eller `hostname`.
3.  Eksisterende assets blir oppdatert med siste kjente data.

---

## 4. Varslingshåndtering
Varsler (Alerts) hentes periodisk og prosesseres slik:
1.  **Ingestion:** Varsler hentes og lagres rått i `integration_alerts`.
2.  **Deduplisering:** Basert på UniFi sin hendelses-ID.
3.  **Rule Engine:** Varslet sendes til regelmotoren for automatisk ticket-håndtering basert på alvorlighetsgrad og type.

---

## 5. Konfigurasjon og Synkronisering
*   **Polling Intervall:** Standard er 60 sekunder.
*   **Default Site:** Alle enheter fra denne integrasjonen blir knyttet til denne siten i tdPSA.
*   **Default Queue:** Køen hvor Tickets opprettet fra UniFi-varsler lander.

---

## 6. Veien Videre (v2+)
*   Støtte for UniFi OS (UDM/UDR) spesifikke funksjoner.
*   Webhooks for sanntidsoppdateringer.
*   Remote reboot av aksesspunkter og switcher.
*   Port-statistikk og klient-oversikt.
