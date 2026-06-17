@component('emails.layout', ['title' => 'Pozvánka do systému'])
    <h1 style="margin:0 0 16px;font-size:20px;color:#0f172a;">Boli ste pridaný do systému</h1>
    <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#334155;">
        Dobrý deň{{ $name ? ', '.$name : '' }}, váš účet
        (<strong>{{ $email }}</strong>) bol pridaný do systému na správu
        rezervácií lodenice KVŠ. Kliknutím na tlačidlo nižšie si nastavíte
        heslo a aktivujete prihlásenie.
    </p>
    <p style="margin:0 0 24px;">
        <a href="{{ $setupUrl }}" style="display:inline-block;background:#0f172a;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:15px;font-weight:600;">
            Nastaviť heslo
        </a>
    </p>
    <div style="margin:0 0 20px;padding:14px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
        <p style="margin:0 0 6px;font-size:13px;font-weight:600;color:#0f172a;">Čo systém umožňuje</p>
        <p style="margin:0;font-size:13px;line-height:1.6;color:#475569;">
            Po prihlásení uvidíte obsadenosť lodí a priestorov, môžete si
            vytvárať rezervácie a sledovať svoje rezervácie. Plný prístup
            (mená a kontakty pri rezerváciách) získate po tom, ako vaše
            členstvo potvrdí správca.
        </p>
    </div>
    <p style="margin:0;font-size:13px;line-height:1.6;color:#64748b;">
        Odkaz na nastavenie hesla je platný {{ $expiresHours }} hodín.
    </p>
    <p style="margin:16px 0 0;font-size:12px;line-height:1.6;color:#94a3b8;word-break:break-all;">
        Ak tlačidlo nefunguje, skopírujte do prehliadača túto adresu:<br>
        {{ $setupUrl }}
    </p>
@endcomponent
