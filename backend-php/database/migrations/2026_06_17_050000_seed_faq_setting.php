<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the `faq` settings row with default Q&A HTML (inlined — the deploy
 * excludes deploy/ from the server upload, so a file read would fall back
 * to a stub). Idempotent: only inserts if missing, so admin edits survive
 * re-deploys.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('settings')->where('key', 'faq')->exists()) {
            return;
        }

        DB::table('settings')->insert([
            'key' => 'faq',
            'value' => $this->defaultHtml(),
            'updatedAt' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'faq')->delete();
    }

    private function defaultHtml(): string
    {
        return <<<'HTML'
<h2>Otázky a odpovede</h2>
<p>Najčastejšie otázky k rezervačnému systému lodenice. Text môže správca klubu kedykoľvek upraviť.</p>
<h3>Ako si vytvorím rezerváciu?</h3>
<p>Kliknite na „Vytvoriť rezerváciu“, vyberte typ a konkrétnu loď (alebo priestor), zvoľte termín od–do a potvrďte. Konflikty s inými rezerváciami systém kontroluje automaticky.</p>
<h3>Musím byť prihlásený, aby som mohol rezervovať?</h3>
<p>Rezerváciu môže vytvoriť aj neprihlásený návštevník. Prihlásení členovia majú navyše prehľad svojich rezervácií a vidia mená a kontakty pri rezerváciách.</p>
<h3>Kto vidí moje meno a kontakt?</h3>
<p>Meno a kontakt pri rezervácii vidia iba prihlásení členovia a správcovia klubu. Neprihlásení návštevníci vidia len to, ktorá loď je kedy obsadená, nie kto ju má rezervovanú.</p>
<h3>Ako zruším alebo upravím rezerváciu?</h3>
<p>Úpravu a zrušenie existujúcej rezervácie môže vykonať prihlásený člen klubu v zozname rezervácií.</p>
<h3>Ako sa stanem členom?</h3>
<p>Zaregistrujte sa cez „Vytvoriť účet“. Účet následne potvrdí správca klubu — potom získate plný prístup člena.</p>
<h3>Zabudol som heslo, čo teraz?</h3>
<p>Na prihlasovacej obrazovke kliknite na „Zabudnuté heslo?“, zadajte e-mail a overenie. Príde vám e-mail s odkazom na nastavenie nového hesla.</p>
<h3>Môžem rezervovať loď pre niekoho iného?</h3>
<p>Áno. Ak ste prihlásený, rezervácia sa predvyplní na vás, ale môžete zaškrtnúť „Rezervujem pre niekoho iného“ a zadať iné meno a kontakt.</p>
<h3>Na koho sa obrátim s ďalšími otázkami?</h3>
<p>Napíšte nám na <a href="mailto:rezervacie@lodenicakvs.sk">rezervacie@lodenicakvs.sk</a>.</p>
HTML;
    }
};
