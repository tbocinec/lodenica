@component('emails.layout', ['title' => 'Rezervácia čaká na schválenie'])
    <h1 style="margin:0 0 16px;font-size:20px;color:#0f172a;">Rezervácia čaká na tvoje schválenie</h1>
    <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#334155;">
        Niekto požiadal o rezerváciu zdroja, ktorý schvaľuješ. Termín je
        medzitým pre ostatných blokovaný, kým o žiadosti nerozhodneš.
    </p>
    <div style="margin:0 0 20px;padding:14px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
        <p style="margin:0;font-size:14px;color:#0f172a;"><strong>{{ $resourceLabel }}</strong></p>
        <p style="margin:4px 0 0;font-size:13px;color:#475569;">{{ $range }}</p>
        <p style="margin:8px 0 0;font-size:13px;color:#475569;">
            Rezervuje: <strong>{{ $customerName }}</strong>@if($customerContact) · {{ $customerContact }}@endif
        </p>
        @if($note)
            <p style="margin:8px 0 0;font-size:13px;color:#475569;">Poznámka: {{ $note }}</p>
        @endif
    </div>
    <p style="margin:0 0 24px;">
        <a href="{{ $approvalsUrl }}" style="display:inline-block;background:#0f172a;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:15px;font-weight:600;">
            Rozhodnúť o žiadosti
        </a>
    </p>
    <p style="margin:0;font-size:13px;line-height:1.6;color:#64748b;">
        Schváliť alebo zamietnuť môžeš na stránke „Na schválenie“ alebo
        priamo v detaile rezervácie.
        @if($personal) Tieto e-maily si vieš vypnúť v profile. @endif
    </p>
@endcomponent
