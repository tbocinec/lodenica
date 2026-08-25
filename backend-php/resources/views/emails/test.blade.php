@component('emails.layout', ['title' => 'Testovací e-mail'])
    <h1 style="margin:0 0 16px;font-size:20px;color:#0f172a;">Testovací e-mail</h1>
    <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#334155;">
        Ak čítate túto správu, odosielanie e-mailov z rezervačného systému
        funguje. Správu vyžiadal správca zo stránky „Diagnostika e-mailov“.
    </p>
    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;font-size:14px;color:#334155;">
        <tr>
            <td style="padding:4px 12px 4px 0;color:#64748b;">Vyžiadal</td>
            <td style="padding:4px 0;"><strong>{{ $triggeredBy }}</strong></td>
        </tr>
        <tr>
            <td style="padding:4px 12px 4px 0;color:#64748b;">Odoslané</td>
            <td style="padding:4px 0;"><strong>{{ $sentAt }}</strong></td>
        </tr>
        <tr>
            <td style="padding:4px 12px 4px 0;color:#64748b;">Inštancia</td>
            <td style="padding:4px 0;"><strong>{{ $appUrl }}</strong></td>
        </tr>
    </table>
    <p style="margin:0;font-size:13px;line-height:1.6;color:#64748b;">
        Na tento e-mail nie je potrebné odpovedať.
    </p>
@endcomponent
