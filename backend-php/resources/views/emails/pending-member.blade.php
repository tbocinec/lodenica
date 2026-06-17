@component('emails.layout', ['title' => 'Nový člen čaká na schválenie'])
    <h1 style="margin:0 0 16px;font-size:20px;color:#0f172a;">Nový člen čaká na schválenie</h1>
    <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#334155;">
        Do systému sa zaregistroval nový používateľ a čaká na potvrdenie
        členstva:
    </p>
    <div style="margin:0 0 20px;padding:14px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
        <p style="margin:0;font-size:14px;color:#0f172a;"><strong>{{ $memberName }}</strong></p>
        <p style="margin:4px 0 0;font-size:13px;color:#475569;">{{ $memberEmail }}</p>
    </div>
    <p style="margin:0 0 24px;">
        <a href="{{ $adminUrl }}" style="display:inline-block;background:#0f172a;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:15px;font-weight:600;">
            Otvoriť správu používateľov
        </a>
    </p>
    <p style="margin:0;font-size:13px;line-height:1.6;color:#64748b;">
        V zozname používateľov nájdete účet v sekcii „Čakajú na schválenie“
        s tlačidlom na potvrdenie.
    </p>
@endcomponent
