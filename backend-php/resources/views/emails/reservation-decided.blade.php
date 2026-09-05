@component('emails.layout', ['title' => $approved ? 'Rezervácia schválená' : 'Rezervácia zamietnutá'])
    <h1 style="margin:0 0 16px;font-size:20px;color:#0f172a;">
        {{ $approved ? 'Tvoja rezervácia bola schválená' : 'Tvoja rezervácia bola zamietnutá' }}
    </h1>
    <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#334155;">
        Dobrý deň{{ $name ? ', '.$name : '' }},
        @if($approved)
            schvaľovateľ potvrdil tvoju žiadosť. Rezervácia je platná a termín je tvoj.
        @else
            schvaľovateľ tvoju žiadosť zamietol. Termín sa uvoľnil pre ostatných.
        @endif
    </p>
    <div style="margin:0 0 20px;padding:14px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
        <p style="margin:0;font-size:14px;color:#0f172a;"><strong>{{ $resourceLabel }}</strong></p>
        <p style="margin:4px 0 0;font-size:13px;color:#475569;">{{ $range }}</p>
        @if($decisionNote)
            <p style="margin:8px 0 0;font-size:13px;color:#475569;">Poznámka schvaľovateľa: {{ $decisionNote }}</p>
        @endif
    </div>
    <p style="margin:0 0 24px;">
        <a href="{{ $reservationsUrl }}" style="display:inline-block;background:#0f172a;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:15px;font-weight:600;">
            Moje rezervácie
        </a>
    </p>
    <p style="margin:0;font-size:13px;line-height:1.6;color:#64748b;">
        Tieto e-maily si vieš vypnúť v profile.
    </p>
@endcomponent
