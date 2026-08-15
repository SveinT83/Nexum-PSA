# Nexum Integration Hub

> Implementasjonsstatus: Nexum-backenden for første read-only leveranse er klar for
> human review på feature-gren. Den private MCP-tjenesten kobles mot de versjonerte
> kontraktene i et separat repo. Ingen produksjonsintegrasjon, provider-mutasjon
> eller generell kommandokanal er aktivert. Én manuell read-only kontroll mot en
> godkjent non-production Plesk-integrasjon gjenstår i `HR-2026-08-15-001`.

## Utvikling

Krav: Node.js 20 eller nyere og pnpm 11.

```bash
pnpm install
pnpm verify
pnpm dev:stdio
```

`pnpm dev:http` binder kun til loopback i første Feature Slice. Nexum execution
grants og den separate service-identiteten er implementert i Nexum-backenden;
ekstern deling og produksjons/provider-tilgang forblir sperret frem til human
review og eksplisitt rollout.

Backendkontrakten er dokumentert i
[`integration-hub-api.md`](integration-hub-api.md), med OpenAPI i
[`integration-hub-v1.yaml`](../openapi/integration-hub-v1.yaml). Driftssteg finnes i
`docs/deployment/integration-hub-*.md`.

Se [leveranseplanen](docs/ROADMAP.md),
[kapabilitetsmatrisen](docs/CAPABILITY_MATRIX.md) og
[API-gaplisten](docs/API_GAPS.md) for gjeldende arbeidsstatus. Første leveranse er
spesifisert i [MCP Protocol Foundation](docs/feature-slices/2026-08-14-protocol-foundation.md),
med manuelle kontroller i [Human Review Register](docs/HUMAN_REVIEW.md).

Dette dokumentet beskriver sluttproduktet vi bygger mot: Nexum som en operasjonell hub for både
Trønder Data og senere andre virksomheter, der Nexum kan lese og utføre arbeid i tilknyttede systemer
gjennom API og MCP.

Dette er en produkt- og gjennomføringsplan. Det er ikke en beskrivelse av funksjonalitet som allerede
er ferdig.

## 1. Målbildet

En tekniker skal kunne bruke Nexum som sitt samlede arbeidssted:

> «Finn kundens nettsted, sjekk hvorfor det er nede, kontroller Plesk, se DNS og SSL-status, rett det
> som kan rettes trygt, og dokumenter hva som ble gjort.»

Teknikeren skal kunne gjøre dette visuelt i Nexum eller gjennom en KI-samtale. Begge veier skal bruke
samme regler, samme tilgang, samme arbeidsflyter og samme etterkontroll.

Nexum skal etter hvert kunne:

- hente informasjon fra Nexum og eksterne systemer;
- forklare status og foreslå neste handling;
- utføre godkjente handlinger;
- stoppe og be om godkjenning når risikoen krever det;
- kjøre forhåndsgodkjente arbeidsflyter automatisk;
- dokumentere hva som skjedde og verifisere sluttresultatet.

## 2. Hva sluttproduktet består av

```text
Tekniker, administrator eller automasjon
                    |
                    v
             KI / MCP-klient
                    |
                    v
       Nexum MCP-protokolltjeneste
                    |
                    v
       Nexum API, policy og Execution-motor
                    |
       +------------+------------+
       |                         |
       v                         v
 Nexum-domener              Integrasjonslag
 tickets, kunder,       Plesk, WordPress, DNS,
 sites, tid, salg,       domener, mail, RMM,
 lager, kunnskap         servere, nettsteder ...
```

MCP-tjenesten er et tynt, kontrollert protokollgrensesnitt. Nexum API, domenelogikk og Execution-motor
er autoriteten og eier all varig tilstand. Integrasjonslaget er den eneste veien til eksterne
systemer. MCP skal aldri være en generell kommandokanal til database, SSH, HTTP eller Plesk.

## 3. MCP-flaten

MCP-flaten deles etter protokollens ansvar:

- **Resources** gir avgrenset, strukturert kontekst som kunde/site, domener, integrasjonsstatus og
  Execution-resultater. De skal ikke være en generell eksport av Nexum-data.
- **Tools** leser eller utfører navngitte Nexum-kapabiliteter. Alle verktøy får strenge input- og
  output-skjemaer, risikometadata og server-side tilgangskontroll.
- **Prompts** kan tilby brukerinitierte arbeidsmaler, men kan aldri gi tilgang eller endre policy.
- **Tasks** brukes for langvarige operasjoner når klienten støtter det. En MCP Task speiler en varig
  Nexum Execution; MCP-tilkoblingen er aldri eneste kilde til utførelsesstatus.

Første versjon skal ikke være avhengig av server-initiert modellkjøring eller rekursiv sampling.
Modellen ligger i MCP-klienten eller i Nexums eksplisitte Agent-runtime. Eventuell senere bruk av
MCP-sampling krever en egen sikkerhets- og kostnadsvurdering.

Verktøy navngis stabilt og domenebasert, for eksempel `nexum.clients.list` og
`nexum.hosting.sites.inspect`. Det opprettes ikke verktøy som `run_command`, `http_request`,
`execute_agent_unrestricted` eller andre generelle omveier rundt kapabilitetsmodellen.

## 4. De viktigste produktreglene

### Samme tilgang som brukeren

KI kan aldri gjøre mer enn den identiteten den kjører på vegne av. En tekniker får samme tilgang
gjennom chat som i Nexum. En Agent eller automasjon får bare det som er eksplisitt gitt til denne
Agenten eller automasjonen, og kan ikke heve teknikerens eller kundens tilgang.

### Nexum eier beslutningene

Nexum eier identitet, roller, kunder, sites, arbeidskontekst, integrasjoner, Agents, godkjenninger,
utførelsesstatus, audit og verifisering. Eksterne systemer eier sine egne data, men Nexum bestemmer
hvem som kan be om tilgang og hvilken handling som kan utføres.

### API før MCP

En visuell handling er ikke ferdig støttet før den har en tydelig API-kontrakt. MCP skal bruke disse
API-funksjonene, ikke private controller-metoder eller UI-automatisering. Hvis et system mangler et
egnet API, registreres dette som et gap eller en separat, høyere risikokapabilitet.

API-paritet og MCP-paritet er to forskjellige porter:

1. Forretningshandlingen må finnes som én autoritativ Nexum-handling som både UI og API kan bruke.
2. Handlingen kan først eksponeres gjennom MCP når den har eksplisitt scope, skjema, risikoklasse,
   idempotens, godkjenningsregel og verifiseringskontrakt.

Sluttmålet er bred MCP-paritet, men ingen UI-knapp blir automatisk et modellstyrt verktøy.

### Les først, endre etterpå

Vi starter med lesing og status. Endringer innføres én kapabilitet om gangen med forhåndsvisning,
tilgangsvurdering, audit, idempotens og verifisering etterpå.

### Feil skal være synlige

Nexum skal skille mellom «ingen avvik», «systemet svarte at det er feil», «handlingen feilet»,
«tilgang mangler», «status er ukjent» og «verifisering kunne ikke gjennomføres». KI skal aldri late
som om en ekstern handling lyktes.

## 5. Brukeropplevelsen

### Interaktiv tekniker

Teknikeren beskriver oppgaven med vanlig språk. KI gjør følgende:

1. forstår oppgaven og finner riktig kunde/site;
2. viser hva den har tenkt å undersøke eller endre;
3. sjekker tilgang og nødvendige integrasjoner;
4. utfører bare tillatte steg;
5. ber om bekreftelse når policy eller risiko krever det;
6. verifiserer resultatet;
7. oppsummerer og kan dokumentere arbeidet i Nexum.

### Supervisert Agent

En Agent kan gjennomføre flere steg i en arbeidsflyt. Den stopper ved definerte risikopunkter,
uklarheter, avvik eller manglende tilgang. Teknikeren kan godkjenne, avvise, endre scope eller stoppe
utførelsen.

### Autonom automasjon

En automasjon kan starte på tid, hendelse eller signal uten en aktiv samtale. Den må ha:

- tydelig formål;
- definert kunde-/site-scope;
- navngitte tillatte kapabiliteter;
- maksimumsgrenser og tidsavbrudd;
- utløpsdato eller regelmessig revurdering;
- feilhåndtering og nødstopp;
- krav til logging og verifisering.

Autonomi er altså en godkjenning av en begrenset arbeidsflyt, ikke en generell tillatelse til å «gjøre
alt».

## 6. Agent-modellen

Agenter videreføres som førstegangsobjekter i Nexum. En Agent er en spesialisert arbeidsprofil som
kombinerer KI-instruksjoner med avgrensede kapabiliteter. Agent-runtime og varig arbeidsflyttilstand
ligger i Nexum, ikke i MCP-protokolltjenesten.

En Agent skal ha:

- navn, formål og eier;
- tillatte Nexum-domener;
- tillatte eksterne integrasjoner og kapabiliteter;
- kunde-/site-scope;
- valgt modell eller lokal kjøringsmodus;
- data- og privacy-policy;
- utførelsesmodus;
- godkjenningsregler;
- grenser for antall, tid, kostnad og parallelle handlinger;
- audit- og retensjonsregler.

Eksempler på senere Agenter:

- Nettsted-helse-agent: undersøker nettsted, DNS, SSL, Plesk og overvåking.
- Onboarding-agent: oppretter avtalte kundestrukturer og sjekklister.
- Backup-agent: kontrollerer backupstatus og oppretter avvik, men sletter aldri backup.
- Ticket-agent: leser signaler, foreslår neste steg og bruker Nexums ticket-workflow.
- Publiserings-agent: klargjør innhold, men publiserer bare etter fastsatt godkjenning.

Agenter skal være spesialiserte og avgrensede. En «superagent» med ubegrenset tilgang er ikke
sluttmålet.

## 7. Integrasjonene

Alle eksterne systemer kobles til gjennom en eksplisitt adapter. Hver integrasjon beskriver:

- hvem som eier forbindelsen;
- om den er intern, kunde- eller site-spesifikk;
- hvilket system og miljø den gjelder;
- hvilke credentials som brukes, uten at secrets blir synlige;
- hvilke lese- og endringskapabiliteter som finnes;
- nødvendige Nexum-abilities;
- risiko og reverserbarhet;
- rate limits, timeout og retry-regler;
- hvordan resultatet verifiseres.

Første eksterne adapter blir Plesk i read-only modus. Den skal gi oss en nyttig, sammenhengende første
leveranse med hostingkonto, site, domene, SSL og grunnleggende nettstedstatus. Deretter følger
WordPress, DNS/domener, mail, overvåking/RMM og serveroperasjoner etter kapabilitetskartlegging og
API-tilgjengelighet.

## 8. Risikomodellen

Bekreftelse avgjøres av bruker, Agent, scope og handling — ikke av én global bryter.

| Nivå | Eksempler | Normal behandling |
|---|---|---|
| Lavt | lese status, hente SSL-info, liste domener | automatisk hvis tilgang finnes |
| Moderat | opprette intern sak, starte synk, lage staging-utkast | policy eller teknikerbekreftelse |
| Høyt | endre DNS, publisere, sende mail, endre produksjonsoppsett | eksplisitt bekreftelse |
| Kritisk | slette domene/site, endre credentials, irreversible driftsendringer | sperret eller sterk, separat godkjenning |

Autonome Agenter kan bare bruke kapabiliteter som er forhåndsgodkjent for autonom kjøring. Nye eller
ukjente mottakere, scopes og handlinger stopper arbeidsflyten.

En interaktiv godkjenning bindes til en konkret, uforanderlig handlingsplan med mål, scope,
parametere, risikosammendrag, utløpstid og digest. Endres planen, bortfaller godkjenningen. KI kan
aldri godkjenne sin egen handling. En autonom policy er en forhåndsgodkjenning av et avgrenset
handlingsrom, ikke en løpende egenbekreftelse fra Agenten.

## 9. Identitet, sikkerhet og sporbarhet

Produksjonstjenesten bruker MCP Streamable HTTP over HTTPS. `stdio` kan brukes lokalt under utvikling,
men er ikke driftsmodellen for et delt produkt.

For interaktive brukere skal Nexum være autorisasjonskilde og bruke en OAuth 2.1-kompatibel flyt med
PKCE, korte levetider, minste nødvendige scopes og token bundet til MCP-tjenesten som audience. For
automasjoner brukes en egen workload-identitet. MCP-tokenet videresendes aldri til Nexum API eller en
ekstern leverandør.

Når MCP-tjenesten kaller Nexum, bruker den en separat tjenesteidentitet og et kortlivet, signert
execution grant utstedt av Nexum for den aktuelle brukeren eller workloaden. Grantet inneholder bare
nødvendig identitet, scope, kunde/site, kapabilitet og korrelasjons-ID. Plesk-, WordPress-, DNS- og
andre provider-credentials forblir i Nexums beskyttede integrasjonslag.

Alle utførelser skal kunne besvares med:

- hvem startet handlingen;
- hvilken Agent, klient eller automasjon som ble brukt;
- hvilken kunde/site/integrasjon som ble berørt;
- hvilke kapabiliteter som ble kalt;
- hvilken policy og godkjenning som gjaldt;
- hva systemet svarte;
- hva som ble verifisert;
- hva som eventuelt fortsatt er ukjent.

Secrets skal aldri sendes til KI som unødvendig kontekst, returneres gjennom MCP eller lagres i
vanlig audittekst. Privacy- og data-egress-reglene gjelder både chat, Agents, API og bakgrunnsjobber.

MCP-verktøyenes `readOnly`, `destructive`, `idempotent` og `open world`-annotasjoner brukes som nyttige
hints for kompatible klienter, men aldri som sikkerhetskontroll. Nexum håndhever alltid policyen på
serveren.

## 10. Utførelsesmodellen

Alle mutasjoner følger samme livssyklus:

```text
resolve scope -> inspect -> plan -> authorize -> execute -> verify -> record
```

En varig Nexum Execution inneholder aktør, Agent/workload, kapabilitetsversjon, mål, normaliserte
parametere, policybeslutning, eventuell godkjenning, idempotency key, status, provider-referanser,
verifisering og sanert feilinformasjon.

Korte read-only kall kan returneres direkte. Langvarige eller muterende kall blir Tasks/Executions
med statusene `working`, `input_required`, `completed`, `failed` eller `cancelled`. Klienten kan hente
status, resultat og avbryte der operasjonen faktisk kan avbrytes. En kansellering skal aldri fremstilles
som tilbakeføring hvis et eksternt steg allerede er utført.

Eksterne systemer kan ikke omfattes av én databasetransaksjon. Flerstegsarbeid bruker derfor
idempotens, checkpoints og eksplisitte kompensasjonshandlinger der trygg tilbakeføring finnes.

## 11. Gjennomføringsplan

### Fase 0 — Produktgrunnlag

- godkjenne denne planen og RFC-en;
- kartlegge visuelle Nexum-handlinger mot eksisterende API;
- lage kapabilitetsmatrise og gap-liste;
- spesifisere første Plesk read-only kontrakt;
- definere execution grant, OAuth-grense og MCP-tjenestens driftsgrense;
- definere versjonering og kompatibilitet for kapabiliteter, API og MCP-verktøy.

### Fase 1 — Read-only hub

- identitet og effektiv tilgang;
- kunder, sites, domener og integrasjoner;
- integrasjons- og helsestatus;
- Plesk read-only adapter;
- MCP-ressurser og verktøy for inspeksjon;
- audit av lesetilgang og tydelige avvik.

### Fase 2 — Første superviserte mutasjon

- én lav- eller moderat-risiko handling;
- preview før utførelse;
- eksplisitt bekreftelse;
- idempotens og timeout;
- post-action-verifisering;
- dokumentasjon av resultat i Nexum.

### Fase 3 — Agent-orkestrering

- Agent kan kombinere flere read-only kapabiliteter;
- pausepunkter og godkjenninger;
- håndtering av uklarhet og manglende tilgang;
- samlet execution-status;
- stopp og gjenoppta.

### Fase 4 — Begrenset autonomi

- planlagte og hendelsesstyrte arbeidsflyter;
- streng scope og maksimumsgrenser;
- automatisk verifisering;
- feilkø og manuell overtakelse;
- nødstopp og regelmessig policygjennomgang.

### Fase 5 — Produktisering

- kundeisolasjon og tenant-regler;
- onboarding av integrasjoner;
- kundevennlig Agent- og policyadministrasjon;
- standardiserte provider-adaptere;
- lisensiering, support, målinger og livssyklus;
- dokumentert sikkerhets- og driftsmodell.

Hver fase krever beståtte sikkerhets-, isolasjons- og driftskriterier før neste fase. Vi øker ikke
autonomien fordi modellen virker flink i en demonstrasjon; vi øker den når kapabiliteten er
deterministisk avgrenset, observerbar og verifiserbar.

## 12. Når er sluttproduktet ferdig?

Sluttproduktet er ikke ferdig når MCP-serveren kan svare på en prompt. Det er ferdig når en bruker
kan stole på at Nexum:

- forstår riktig kunde og site;
- aldri overskrider brukerens eller Agentens tilgang;
- skiller mellom forslag, godkjenning og utførelse;
- bruker riktige API-er og integrasjoner;
- stopper ved usikkerhet;
- verifiserer eksterne resultater;
- dokumenterer hele handlingen;
- kan kjøres interaktivt eller autonomt etter samme regler;
- kan driftes sikkert av Trønder Data;
- kan isoleres og tilbys andre Nexum-kunder senere.

## 13. Valgte arkitekturbeslutninger

- MCP-serveren blir en separat, tynn protokolltjeneste som bruker Nexum API.
- Nexum er autoritativ hub for tilgang, scope, policy, Agents og audit.
- Nexum eier varige Executions; MCP Tasks speiler dem ved behov.
- MCP får ikke direkte database- eller generell provider-tilgang.
- Produksjonstransport er Streamable HTTP over HTTPS; `stdio` er kun lokal utvikling.
- Interaktiv identitet bruker audience-bundne, kortlivede OAuth-grants; token passthrough er forbudt.
- Interne Trønder Data-integrasjoner støttes fra starten av modellen.
- Agenter videreføres og får tilgang til eksterne kapabiliteter gjennom samme policykjede.
- Interaktiv, supervisert og autonom kjøring er forskjellige utførelsesmoduser.
- Første eksterne leveranse er Plesk read-only.
- Mutasjoner krever preview, riktig policy, audit og verifisering.
- API-paritet er et mål; MCP-eksponering krever i tillegg en godkjent kapabilitetskontrakt.
- KI kan aldri godkjenne sin egen handling.
- Intern drift kommer først; produktisering skjer etter at driftsmodellen er bevist.

## 14. Standardgrunnlag

Implementasjonen følger MCP-revisjon `2026-07-28` gjennom den offisielle
TypeScript SDK-en v2. Overgangsstøtte for `2025-11-25` beholdes som en eksplisitt
kompatibilitetsbane og skal kontrakttestes; se
[ADR-0001](docs/adr/0001-typescript-sdk-v2-and-dual-era-serving.md).

MCP utvikler seg fortsatt. Implementasjonen skal forhandle protokollversjon, ha kontraktstester mot
støttede klienter og aldri knytte Nexums interne domenemodell direkte til én midlertidig
protokolldetalj.

## Relaterte dokumenter

- [RFC: Nexum Integration Hub And MCP Server](docs/rfc/2026-08-14-nexum-integration-hub-mcp.md)
- [ADR-0001: TypeScript SDK v2 og dual-era serving](docs/adr/0001-typescript-sdk-v2-and-dual-era-serving.md)
