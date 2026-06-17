@component('emails.layout', ['title' => 'Obnova hesla'])
    <h1 style="margin:0 0 16px;font-size:20px;color:#0f172a;">Obnova hesla</h1>
    <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#334155;">
        Dostali sme žiadosť o obnovu hesla k vášmu účtu
        <strong>{{ $email }}</strong>. Kliknutím na tlačidlo nižšie si
        nastavíte nové heslo.
    </p>
    <p style="margin:0 0 24px;">
        <a href="{{ $resetUrl }}" style="display:inline-block;background:#0f172a;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:15px;font-weight:600;">
            Nastaviť nové heslo
        </a>
    </p>
    <p style="margin:0 0 8px;font-size:13px;line-height:1.6;color:#64748b;">
        Odkaz je platný {{ $expiresMinutes }} minút. Ak ste o obnovu hesla
        nežiadali, tento e-mail ignorujte — vaše heslo sa nezmení.
    </p>
    <p style="margin:16px 0 0;font-size:12px;line-height:1.6;color:#94a3b8;word-break:break-all;">
        Ak tlačidlo nefunguje, skopírujte do prehliadača túto adresu:<br>
        {{ $resetUrl }}
    </p>
@endcomponent
