<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the `privacy_policy` settings row with the canonical default HTML.
 * The HTML is inlined here (not read from deploy/privacy-policy.html)
 * because the deploy excludes the deploy/ directory from the server upload,
 * so a file read would fall back to a stub. Idempotent: only inserts if the
 * row is missing, so an admin's later edits are never clobbered on re-deploy.
 *
 * deploy/privacy-policy.html is kept as the human-readable source of truth;
 * if you change it, mirror the change into the heredoc below.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('settings')->where('key', 'privacy_policy')->exists()) {
            return;
        }

        DB::table('settings')->insert([
            'key' => 'privacy_policy',
            'value' => $this->defaultHtml(),
            'updatedAt' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'privacy_policy')->delete();
    }

    private function defaultHtml(): string
    {
        return <<<'HTML'
<h2>Ochrana osobných údajov</h2>
<p>Tento dokument popisuje, aké osobné údaje spracúva rezervačný systém lodenice Klubu vodných športov Karlova Ves (ďalej „klub“) a na aký účel. Text môže správca klubu kedykoľvek upraviť.</p>
<h3>Aké údaje spracúvame</h3>
<ul>
  <li><strong>Meno a kontakt</strong> (e-mail, prípadne telefón) — pri registrácii účtu a pri vytvorení rezervácie.</li>
  <li><strong>Prihlasovacie údaje</strong> — e-mail a heslo (heslo je uložené v zašifrovanej podobe), prípadne prepojené konto Google/Facebook.</li>
  <li><strong>Záznamy o rezerváciách a udalostiach</strong>, ktoré vytvoríte alebo na ktorých sa zúčastníte.</li>
</ul>
<h3>Na aký účel</h3>
<ul>
  <li>Správa rezervácií klubovej výbavy a priestorov.</li>
  <li>Organizácia klubových udalostí.</li>
  <li>Komunikácia ohľadom účtu (potvrdenie členstva, obnova hesla).</li>
</ul>
<h3>Komu údaje sprístupňujeme</h3>
<p>Mená a kontakty pri rezerváciách vidia iba prihlásení členovia a správcovia klubu. Anonymní návštevníci vidia len obsadenosť (ktorá loď je kedy rezervovaná), nie kto ju má rezervovanú. Údaje neposkytujeme tretím stranám okrem poskytovateľa e-mailovej služby a hostingu nevyhnutných na prevádzku systému.</p>
<h3>Ako dlho údaje uchovávame</h3>
<p>Údaje uchovávame po dobu trvania členstva, resp. kým je účet aktívny. Na požiadanie účet a súvisiace osobné údaje zmažeme.</p>
<h3>Vaše práva</h3>
<p>Máte právo na prístup k svojim údajom, ich opravu alebo vymazanie. V prípade otázok alebo žiadostí napíšte na <a href="mailto:rezervacie@lodenicakvs.sk">rezervacie@lodenicakvs.sk</a>.</p>
HTML;
    }
};
