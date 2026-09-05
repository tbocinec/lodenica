<?php

namespace App\Domain\Enums;

/**
 * The transactional e-mails the app sends. Each one can be switched off
 * independently from the admin diagnostics page; the backing value doubles
 * as the JSON key inside the `mail_notifications` setting AND inside a
 * user's `notificationPrefs` for the user-configurable ones.
 *
 * Two of them (password reset, account invitation) are the only way a
 * member can get into their account — {@see isCritical()} marks those so
 * the UI can warn before an admin switches them off. They stay switchable
 * on purpose: when SMTP is broken, disabling the send is what keeps the
 * surrounding request from failing.
 *
 * {@see isUserConfigurable()} marks the ones a member may switch off for
 * themselves in the profile (REZ-062). Operational mail stays mandatory.
 */
enum MailNotification: string
{
    case PASSWORD_RESET = 'password_reset';
    case ACCOUNT_INVITATION = 'account_invitation';
    case MEMBERSHIP_APPROVED = 'membership_approved';
    case PENDING_MEMBER_ADMIN = 'pending_member_admin';
    case RESERVATION_APPROVAL_REQUESTED = 'reservation_approval_requested';
    case RESERVATION_DECIDED = 'reservation_decided';

    public function label(): string
    {
        return match ($this) {
            self::PASSWORD_RESET => 'Obnova hesla',
            self::ACCOUNT_INVITATION => 'Pozvánka do systému',
            self::MEMBERSHIP_APPROVED => 'Členstvo schválené',
            self::PENDING_MEMBER_ADMIN => 'Upozornenie správcovi o novom členovi',
            self::RESERVATION_APPROVAL_REQUESTED => 'Žiadosť o schválenie rezervácie',
            self::RESERVATION_DECIDED => 'Výsledok schvaľovania rezervácie',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PASSWORD_RESET => 'Odkaz na nastavenie nového hesla po kliknutí na „Zabudnuté heslo“.',
            self::ACCOUNT_INVITATION => 'Odkaz na nastavenie prvého hesla pre účet založený správcom alebo hromadným importom.',
            self::MEMBERSHIP_APPROVED => 'Oznámenie členovi, že správca schválil jeho registráciu.',
            self::PENDING_MEMBER_ADMIN => 'Oznámenie na klubovú adresu, že sa zaregistroval nový člen a čaká na schválenie.',
            self::RESERVATION_APPROVAL_REQUESTED => 'Oznámenie schvaľovateľom zdroja, že niekto požiadal o jeho rezerváciu a čaká na rozhodnutie.',
            self::RESERVATION_DECIDED => 'Oznámenie rezervujúcemu, že jeho žiadosť o rezerváciu bola schválená alebo zamietnutá.',
        };
    }

    /** A member cannot reach their account without this e-mail. */
    public function isCritical(): bool
    {
        return match ($this) {
            self::PASSWORD_RESET, self::ACCOUNT_INVITATION => true,
            self::MEMBERSHIP_APPROVED,
            self::PENDING_MEMBER_ADMIN,
            self::RESERVATION_APPROVAL_REQUESTED,
            self::RESERVATION_DECIDED => false,
        };
    }

    /** A member may switch this one off for themselves in the profile. */
    public function isUserConfigurable(): bool
    {
        return match ($this) {
            self::RESERVATION_APPROVAL_REQUESTED, self::RESERVATION_DECIDED => true,
            self::PASSWORD_RESET,
            self::ACCOUNT_INVITATION,
            self::MEMBERSHIP_APPROVED,
            self::PENDING_MEMBER_ADMIN => false,
        };
    }

    /** What stops working while this notification is switched off. */
    public function consequence(): string
    {
        return match ($this) {
            self::PASSWORD_RESET => 'Členovia si nebudú vedieť obnoviť zabudnuté heslo. Formulár im napriek tomu potvrdí odoslanie — o vypnutí sa nedozvedia.',
            self::ACCOUNT_INVITATION => 'Novo založené účty nedostanú prihlasovacie údaje a nikto sa do nich neprihlási.',
            self::MEMBERSHIP_APPROVED => 'Schválený člen sa o schválení nedozvie e-mailom; prihlásiť sa však už môže.',
            self::PENDING_MEMBER_ADMIN => 'Správcovia nedostanú upozornenie na nového čakajúceho člena — treba ich kontrolovať ručne v zozname používateľov.',
            self::RESERVATION_APPROVAL_REQUESTED => 'Schvaľovatelia sa o čakajúcich žiadostiach nedozvedia e-mailom — musia ich kontrolovať na stránke „Na schválenie“.',
            self::RESERVATION_DECIDED => 'Rezervujúci sa o výsledku dozvie až v systéme, v zozname svojich rezervácií.',
        };
    }
}
