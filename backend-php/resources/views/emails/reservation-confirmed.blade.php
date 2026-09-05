@component('emails.layout', ['title' => 'Rezervácia vytvorená'])
    <h1 style="margin:0 0 16px;font-size:20px;color:#0f172a;">Rezervácia je vytvorená</h1>
    <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#334155;">
        @if($name)Ahoj {{ $name }},@else Dobrý deň,@endif
        rezervácia je potvrdená a čaká na teba.
    </p>
    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;font-size:14px;color:#334155;">
        <tr>
            <td style="padding:4px 12px 4px 0;color:#64748b;">Zdroj</td>
            <td style="padding:4px 0;"><strong>{{ $resourceLabel }}</strong></td>
        </tr>
        <tr>
            <td style="padding:4px 12px 4px 0;color:#64748b;">Termín</td>
            <td style="padding:4px 0;"><strong>{{ $range }}</strong></td>
        </tr>
        <tr>
            <td style="padding:4px 12px 4px 0;color:#64748b;">Rezervované pre</td>
            <td style="padding:4px 0;">{{ $customerName }}</td>
        </tr>
        @if($note)
        <tr>
            <td style="padding:4px 12px 4px 0;color:#64748b;vertical-align:top;">Poznámka</td>
            <td style="padding:4px 0;">{{ $note }}</td>
        </tr>
        @endif
    </table>
    <p style="margin:0 0 12px;font-size:15px;line-height:1.6;color:#334155;">
        Pridaj si termín do kalendára:
    </p>
    <p style="margin:0 0 8px;">
        <a href="{{ $googleCalendarUrl }}" style="display:inline-block;background:#0f172a;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:15px;font-weight:600;">
            Pridať do Google kalendára
        </a>
    </p>
    <p style="margin:0 0 24px;">
        <a href="{{ $icsUrl }}" style="display:inline-block;background:#e2e8f0;color:#0f172a;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:15px;font-weight:600;">
            Stiahnuť .ics (Apple, Outlook)
        </a>
    </p>
    <p style="margin:0 0 8px;font-size:13px;line-height:1.6;color:#64748b;">
        Súbor .ics je aj v prílohe tohto e-mailu. Rezerváciu nájdeš v
        <a href="{{ $reservationsUrl }}" style="color:#0f172a;">Mojich rezerváciách</a>,
        kde ju vieš upraviť alebo zrušiť.
    </p>
    <p style="margin:16px 0 0;font-size:12px;line-height:1.6;color:#94a3b8;">
        Tento e-mail si zapol/zapla v profile v sekcii E-mailové notifikácie; tam sa dá aj vypnúť.
    </p>
@endcomponent
