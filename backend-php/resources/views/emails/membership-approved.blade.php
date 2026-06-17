@component('emails.layout', ['title' => 'Členstvo schválené'])
    <h1 style="margin:0 0 16px;font-size:20px;color:#0f172a;">Vaše členstvo bolo schválené</h1>
    <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#334155;">
        Dobrý deň{{ $name ? ', '.$name : '' }}, váš účet bol potvrdený
        správcom. Odteraz máte plný prístup člena klubu — vidíte mená a
        kontakty pri rezerváciách a môžete upravovať rezervácie.
    </p>
    <p style="margin:0 0 24px;">
        <a href="{{ $loginUrl }}" style="display:inline-block;background:#0f172a;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:15px;font-weight:600;">
            Prihlásiť sa
        </a>
    </p>
    <p style="margin:0;font-size:13px;line-height:1.6;color:#64748b;">
        Ďakujeme a tešíme sa na vodu! 🛶
    </p>
@endcomponent
