# Rezervácie — `REZ`

Jadro systému. Rezervácia hovorí, že **konkrétny zdroj je v konkrétnom
čase obsadený konkrétnym človekom**.

Nahrádza pôvodný Google Sheet, kde sa termíny zapisovali ručne a
prekryvy sa riešili až keď si dvaja ľudia prišli po tú istú loď.

Súvisí: [00-conventions.md](00-conventions.md) (čas, osobné údaje),
01-resources.md (čo sa dá rezervovať),
10-availability.md (dostupnosť).

## Model

| Pole | Význam |
|---|---|
| `resourceId` | Rezervovaný zdroj. Povinné. |
| `eventId` | Väzba na klubovú akciu, ak rezervácia patrí k nej. |
| `customerName` | Meno rezervujúceho. Povinné, osobný údaj. |
| `customerContact` | Kontakt. Voliteľný, osobný údaj. |
| `createdById` | Účet, ktorý rezerváciu vytvoril. `null` pri anonymnej. |
| `memberId` | Interné členské ID, na ktoré je rezervácia napojená. |
| `startsAt`, `endsAt` | Polootvorený interval `[od, do)`. |
| `note` | Poznámka. |
| `status` | `CONFIRMED`, `PENDING_APPROVAL`, `CANCELLED` alebo `REJECTED` — pozri Schvaľovanie. |
| `decidedById`, `decidedAt` | Kto a kedy o čakajúcej rezervácii rozhodol. `null`, kým sa nerozhodne. |
| `decisionNote` | Voliteľná poznámka schvaľovateľa k rozhodnutiu. Vidí ju iba potvrdený člen. |

## Vytvorenie

**REZ-001** — Rezerváciu MÔŽE vytvoriť **ktokoľvek**, aj neprihlásený
návštevník. Je to vedomé rozhodnutie: bariéra pri rezervovaní by ľudí
vrátila späť k papieru a telefonátom.
Vynútené: `POST /api/v1/reservations` je mimo `auth:sanctum`
Test: `ReservationsApiTest::test_create_reservation`

**REZ-002** — Rezervácia MUSÍ mať meno rezervujúceho (1–200 znakov).
Vynútené: `CreateReservationRequest`
Test: —

**REZ-003** — Keď rezerváciu vytvára prihlásený používateľ a meno alebo
kontakt nevyplní, systém MUSÍ doplniť jeho vlastné meno a e-mail. Keď ich
vyplní, majú prednosť — člen tak môže rezervovať za niekoho iného.
Vynútené: `CreateReservationRequest::prepareForValidation`
Test: —

**REZ-004** — Rezervovať NEMOŽNO neaktívny zdroj.
Vynútené: `ReservationsService::create` → `InactiveResourceException`
Test: `ReservationsApiTest::test_create_returns_400_on_inactive_resource`

**REZ-005** — Rezervovať NEMOŽNO neexistujúci zdroj; požiadavka vráti 404.
Vynútené: `ReservationsService::create` → `NotFoundDomainException`
Test: —

**REZ-006** — Novovytvorená rezervácia má stav `CONFIRMED`; pri zdroji
vyžadujúcom schválenie `PENDING_APPROVAL` (pozri REZ-052). Stav sa pri
vytváraní nedá zadať.
Vynútené: `ReservationsService::create`
Test: `ReservationsApiTest::test_create_reservation`,
`ReservationApprovalApiTest::test_member_booking_of_a_gated_resource_waits_for_approval`

## Prekryvy — najdôležitejšie pravidlo modulu

**REZ-010** — Dve rezervácie toho istého zdroja v **blokujúcom stave**
(`CONFIRMED` alebo `PENDING_APPROVAL`) sa NESMÚ časovo prekrývať.
Vynútené: `ReservationsService::assertNoOverlap` **a** databázový
EXCLUDE constraint `reservations_no_overlap_excl`, `ReservationStatus::blocksSlot`
Test: `ReservationsApiTest::test_create_returns_409_on_overlap`

**REZ-011** — Zákaz prekryvu MUSÍ byť vynútený aj **v databáze**, nielen
v aplikácii. Dva súbežné zápisy prejdú kontrolou v aplikácii obidva;
zastaví ich až constraint.
Vynútené: `EXCLUDE USING gist (resourceId WITH =, tsrange(startsAt, endsAt, '[)') WITH &&) WHERE status IN ('CONFIRMED', 'PENDING_APPROVAL')`
Test: —
Poznámka: vyžaduje Postgres s rozšírením `btree_gist`. Testy bežiace na
SQLite toto pravidlo overiť nevedia — pozri `tests/` s prípojkou `pgsql_test`.

**REZ-012** — Zrušená alebo zamietnutá rezervácia prekryv **netvorí**.
Uvoľnený termín sa dá obsadiť znova.
Vynútené: `WHERE status IN ('CONFIRMED','PENDING_APPROVAL')` v constrainte, `ReservationStatus::blocksSlot`
Test: `ReservationsApiTest::test_cancel_then_create_same_slot_succeeds`

**REZ-013** — Rezervácie nadväzujúce na seba (koniec jednej = začiatok
druhej) sa NEPOVAŽUJÚ za prekryv. Odovzdanie lode na poludnie je bežná
prevádzka, nie konflikt.
Vynútené: polootvorený interval, [KON-003](00-conventions.md)
Test: `ReservationsApiTest::test_back_to_back_bookings_are_allowed`

**REZ-014** — Pokus o prekryv vracia **HTTP 409** s podrobnosťami o
kolidujúcej rezervácii.
Vynútené: `ReservationOverlapException`
Test: `ReservationsApiTest::test_create_returns_409_on_overlap`

## Úprava a zrušenie

**REZ-020** — Upraviť, zrušiť alebo zmazať existujúcu rezerváciu MÔŽE iba
**potvrdený člen**. Anonymný návštevník ju vytvoriť môže, ale meniť už nie
— cudzie rezervácie patria iným ľuďom.
Vynútené: skupina `['auth:sanctum', 'member']` v `routes/api.php`
Test: —

**REZ-021** — Pri úprave sa dá meniť meno, kontakt, poznámka, väzba na
akciu a časový rozsah; stav iba v medziach REZ-058 (čakajúca ani
zamietnutá rezervácia stav cez úpravu nemení). Zdroj sa meniť **nedá** —
presun na inú loď znamená zrušiť a založiť novú.
Vynútené: `ReservationsService::update` (`array_intersect_key`,
`assertStatusChangeAllowed`)
Test: `ReservationApprovalApiTest::test_patch_status_still_works_on_a_normal_resource`

**REZ-022** — Pri zmene rozsahu sa kontrola prekryvu MUSÍ zopakovať,
pričom upravovaná rezervácia sa zo seba samej vylučuje.
Vynútené: `ReservationsService::update` → `assertNoOverlap(..., $id)`
Test: —

**REZ-023** — Zrušenie mení stav na `CANCELLED`; záznam sa **nemaže**.
História rezervácií je prevádzkovo užitočná.
Vynútené: `ReservationsService::cancel`
Test: —

**REZ-024** — Opakované zrušenie už zrušenej alebo zamietnutej rezervácie
NESMIE zmeniť stav ani zapísať ďalší audit záznam.
Vynútené: `ReservationsService::cancel` (`blocksSlot()` guard)
Test: `ReservationApprovalApiTest::test_cancelling_a_rejected_reservation_is_a_no_op`

**REZ-025** — Správca MÔŽE rezerváciu prepojiť na iné členské ID. Systém
zároveň prestaví `createdById` na účet toho člena, ak taký existuje, aby
sa rezervácia objavila v jeho „mojich rezerváciách". Prázdna hodnota
väzbu zruší.
Vynútené: `ReservationsService::update`
Test: `ReservationMemberLinkTest`

## Čítanie

**REZ-030** — Zoznam rezervácií je **verejný**. Kto chce vedieť, či je
loď voľná, to zistí bez prihlásenia.
Vynútené: `GET /api/v1/reservations` mimo `auth:sanctum`
Test: —

**REZ-031** — Meno a kontakt rezervujúceho vidí iba potvrdený člen —
pozri [KON-030](00-conventions.md). Neprihlásený vidí, že zdroj je
obsadený, ale nie kým.
Vynútené: `ReservationResource`
Test: `ReservationsApiTest::test_customer_name_and_contact_are_hidden_for_anonymous_readers`

**REZ-032** — Prihlásený používateľ MÔŽE získať zoznam vlastných
rezervácií cez `GET /reservations/mine`.
Vynútené: `ReservationsController::mine`
Test: `MyReservationsApiTest`

**REZ-033** — Vyhľadávanie v zozname MUSÍ nájsť zhodu aj podľa
identifikátora a názvu zdroja, nielen podľa mena rezervujúceho.
Vynútené: `ReservationsService::list`
Test: `ReservationsApiTest::test_search_matches_resource_identifier_and_name`

**REZ-034** — Ku každej rezervácii sa dá stiahnuť kalendárový súbor
`.ics` cez `GET /reservations/{id}/ics`.
Vynútené: `ReservationsController::ics`
Test: —

## Obrazovky

**REZ-040** — Rezervácia sa dá založiť tromi cestami, ktoré vedú na ten
istý formulár: zo zoznamu rezervácií, kliknutím do voľného miesta na
časovej osi, a z detailu zdroja.
Vynútené: `ReservationsView`, `TimelineView`, `ResourceDetailView`
Test: —

**REZ-041** — Časová os MUSÍ umožniť vytvoriť rezerváciu ťahom myšou cez
voľný úsek riadku zdroja; vybraný rozsah sa predvyplní do formulára.
Vynútené: `TimelineView`
Test: —

**REZ-042** — Formulár MUSÍ pri vybranom zdroji upozorniť, že loď je
poškodená, a ponúknuť odkaz na detail poškodenia. Rezerváciu to
**neblokuje** — pozri POS-030.
Vynútené: `ReservationFormView`
Test: —

**REZ-043** — Vo výbere lode MUSÍ byť poškodená loď vizuálne odlíšená od
ostatných ešte pred jej vybratím.
Vynútené: `ReservationFormView`, `ResourceSelect.vue`, `DamageBadge.vue`
Test: `DamageBadge.spec.ts`

**REZ-044** — Kalendárové zobrazenie ukazuje rezervácie po mesiacoch,
časová os po dňoch alebo týždňoch. Obe čítajú tie isté dáta.
Vynútené: `CalendarView`, `TimelineView`
Test: —

## Schvaľovanie

Niektoré zdroje (klubovňa, príves, drahá loď) nemá zmysel dávať voľne.
Správca ich označí ako **vyžadujúce schválenie** a vyberie, kto smie
schvaľovať. Rezervácia takého zdroja nevzniká potvrdená, ale **čaká**,
kým o nej niekto rozhodne. Zdroje bez tohto príznaku fungujú presne ako
doteraz.

**REZ-050** — Správca MÔŽE zdroj označiť príznakom `requiresApproval` a
priradiť mu zoznam schvaľovateľov (potvrdených členov alebo správcov).
Predvolene je príznak vypnutý a zoznam prázdny. Zmeny zoznamu sa auditujú.
Vynútené: `ResourcesService::create/update`, `CreateResourceRequest`,
`UpdateResourceRequest`, tabuľka `resource_approvers`
Test: `ResourcesApiTest::test_admin_sets_requires_approval_and_approvers`,
`ResourcesApiTest::test_approver_must_be_a_confirmed_member`

**REZ-051** — Zdroj vyžadujúci schválenie MÔŽE rezervovať iba potvrdený
člen. Anonymný návštevník aj účet v stave `PENDING` dostanú **HTTP 403** s
kódom `RESERVATION_APPROVAL_MEMBER_REQUIRED`. Zámerne 403 aj pre anonyma:
SPA pri 401 zahodí prihlásenie, čo by čakajúci účet odhlásilo.
Vynútené: `ReservationsService::create` → `ApprovalMemberRequiredException`
Test: `ReservationApprovalApiTest::test_anonymous_cannot_book_a_gated_resource`,
`ReservationApprovalApiTest::test_pending_account_cannot_book_a_gated_resource`

**REZ-052** — Rezervácia zdroja vyžadujúceho schválenie vzniká v stave
`PENDING_APPROVAL`. Tento stav **blokuje termín** rovnako ako `CONFIRMED`,
takže schválenie nikdy nenarazí na prekryv.
Vynútené: `ReservationsService::create`, `ReservationStatus::blocksSlot`,
constraint `reservations_no_overlap_excl`
Test: `ReservationApprovalApiTest::test_member_booking_of_a_gated_resource_waits_for_approval`,
`ReservationApprovalApiTest::test_a_pending_request_holds_the_slot`,
`PostgresExcludeConstraintTest::test_exclude_constraint_treats_pending_approval_as_occupied`

**REZ-053** — Pri vzniku čakajúcej rezervácie systém pošle e-mail každému
aktívnemu schvaľovateľovi zdroja, každému samostatne. Ak zdroj nemá
schvaľovateľov, ide jeden e-mail na klubovú adresu (`mail.admin_address`).
Vynútené: `ReservationNotifier::approvalRequested`
Test: `ReservationNotifierTest`

**REZ-054** — O čakajúcej rezervácii MÔŽE rozhodnúť správca, alebo člen
uvedený v zozname schvaľovateľov daného zdroja. Ostatní dostanú 403.
Vynútené: `ReservationApprovalService::canDecide`
Test: `ReservationApprovalApiTest::test_member_outside_the_approver_list_cannot_decide`,
`ReservationApprovalApiTest::test_admin_can_decide_without_being_listed`

**REZ-055** — Schválenie mení stav na `CONFIRMED`, zamietnutie na
`REJECTED`. Zamietnutá rezervácia termín **uvoľňuje**. Pri oboch sa
zaznamená kto rozhodol (`decidedById`), kedy (`decidedAt`) a voliteľná
poznámka (`decisionNote`); audit dostane akciu `APPROVE` alebo `REJECT`.
Vynútené: `ReservationApprovalService::approve/reject`
Test: `ReservationApprovalApiTest::test_approver_approves`,
`ReservationApprovalApiTest::test_approver_rejects_and_frees_the_slot`

**REZ-056** — Rozhodnutie je konečné. Ďalší pokus o rozhodnutie vracia
**HTTP 409** s kódom `RESERVATION_NOT_PENDING`. Súbežné rozhodnutia rieši
podmienený zápis (`UPDATE … WHERE status = 'PENDING_APPROVAL'`) — vyhrá
prvý, druhý dostane 409.
Vynútené: `ReservationApprovalService::decide`
Test: `ReservationApprovalApiTest::test_second_decision_is_rejected_with_409`

**REZ-057** — Po rozhodnutí systém pošle e-mail účtu, ktorý rezerváciu
vytvoril. Keď člen rezervoval za niekoho iného, e-mail dostane člen, nie
tá osoba.
Vynútené: `ReservationNotifier::decided`
Test: `ReservationApprovalApiTest::test_approver_approves`,
`ReservationNotifierTest::test_decision_goes_to_the_creator`

**REZ-058** — Stav rezervácie v stave `PENDING_APPROVAL` alebo `REJECTED`
sa NEDÁ zmeniť bežnou úpravou (PATCH); pokus vracia **HTTP 409**
`RESERVATION_STATUS_LOCKED`. Na zdroji vyžadujúcom schválenie sa cez PATCH
NEDÁ dostať do `CONFIRMED` — jediná cesta je schválenie. Ostatné polia
(čas, meno, poznámka) sa upravovať dajú; rezervácia zostáva čakajúca a
schvaľovatelia sa o úprave e-mailom nedozvedia.
Vynútené: `ReservationsService::update`, `UpdateReservationRequest`
Test: `ReservationApprovalApiTest::test_patch_cannot_change_status_of_a_pending_reservation`,
`ReservationApprovalApiTest::test_patch_cannot_confirm_on_a_gated_resource`,
`ReservationApprovalApiTest::test_patch_of_other_fields_keeps_the_request_pending`

**REZ-059** — Zrušenie čakajúcej rezervácie ju prevedie do `CANCELLED`
(rezervujúci žiadosť stiahol) a termín uvoľní. Schvaľovatelia o tom
e-mail nedostanú.
Vynútené: `ReservationsService::cancel`
Test: `ReservationApprovalApiTest::test_cancelling_a_pending_request_frees_the_slot`

**REZ-060** — `GET /reservations/approvals` vracia čakajúce rezervácie, o
ktorých MÔŽE volajúci rozhodnúť: správcovi všetky, členovi tie zo zdrojov,
kde je schvaľovateľom. Neprihlásený dostane 401, účet `PENDING` 403.
Vynútené: `ReservationApprovalService::pendingFor`, `routes/api.php`
Test: `ReservationApprovalApiTest::test_approvals_list_is_scoped_to_the_caller`

**REZ-061** — Filter `status` v zozname rezervácií prijíma aj viac hodnôt
(`status[]=…`), aby si rozvrhové obrazovky vypýtali potvrdené a čakajúce
jedným dotazom. Čakajúca rezervácia MUSÍ byť v rozvrhu vizuálne odlíšená.
Vynútené: `ListReservationsRequest`, `ReservationsService::list`;
SPA `ReservationStatusPill.vue`, `TimelineView.vue`
Test: `ReservationsApiTest::test_list_accepts_multiple_statuses`,
`ReservationStatusPill.spec.ts`

**REZ-062** — Používateľ si MÔŽE v profile vypnúť e-maily označené ako
používateľsky nastaviteľné (`MailNotification::isUserConfigurable`).
Predvolene sú zapnuté. Vypnutie správcom v diagnostike má prednosť —
používateľská preferencia e-mail nikdy nezapne, iba vypne. Zmena sa
audituje.
Vynútené: `UserNotificationPreferences`, `NotificationMailer::sendToUser`,
`ProfileController::notifications/updateNotifications`
Test: `UserNotificationPreferencesTest`

**REZ-063** — Kalendárový súbor `.ics` čakajúcej rezervácie má
`STATUS:TENTATIVE`; zamietnutá a zrušená majú `CANCELLED`.
Vynútené: `ReservationsController::ics`
Test: `ReservationIcsApiTest::test_ics_marks_a_pending_reservation_tentative`

## Známe medzery

- REZ-002, REZ-003, REZ-005, REZ-011, REZ-020, REZ-022, REZ-023, REZ-025,
  REZ-030, REZ-034 a všetky REZ-04x **nemajú test**.
- REZ-011 sa na SQLite overiť nedá; potrebuje Postgres.
- Systém **nevynucuje žiadny strop na dĺžku rezervácie** ani na počet
  súbežných rezervácií jedného človeka — pozri
  [99-open-questions.md](99-open-questions.md).
- Schvaľovanie nemá automatickú expiráciu čakajúcich žiadostí ani pripomienky; úprava času čakajúcej rezervácie schvaľovateľov znova neupozorní.
