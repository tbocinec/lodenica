<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The deploy script must serve any club: every identity value comes from
 * the per-client secrets file, never from a literal in the script.
 */
class DeployScriptIsClientNeutralTest extends TestCase
{
    private function script(string $name = 'deploy-rezervacie.sh'): string
    {
        $path = base_path('../scripts/'.$name);
        if (!is_readable($path)) {
            // The deployed copy of the app has no scripts/ directory.
            $this->markTestSkipped("{$name} not available here.");
        }

        return (string) file_get_contents($path);
    }

    public function test_no_club_literal_in_the_deploy_scripts(): void
    {
        $this->assertDoesNotMatchRegularExpression('/lodenicakvs|KVŠ|Lodenica KVS/u', $this->script());
        $this->assertDoesNotMatchRegularExpression('/lodenicakvs|KVŠ|Lodenica KVS/u', $this->script('deploy-all.sh'));
    }

    public function test_site_name_is_required_and_the_site_block_reaches_env(): void
    {
        $script = $this->script();

        $this->assertStringContainsString('require_var SITE_NAME', $script);
        $this->assertStringContainsString("printf 'APP_NAME=\"%s\"\\n' \"\$SITE_NAME\"", $script);
        $this->assertStringContainsString("printf 'SITE_NAME=\"%s\"\\n'", $script);
        $this->assertStringContainsString("printf 'ADMIN_EMAIL=%s\\n'", $script);
        $this->assertStringContainsString('SITE_FEATURE_TRAFFIC_LIGHT', $script);
    }

    public function test_mail_identity_has_no_default_sender(): void
    {
        $script = $this->script();

        $this->assertStringContainsString('require_var MAIL_USERNAME MAIL_FROM_ADDRESS', $script);
        $this->assertStringContainsString('MAIL_FROM_NAME:-$SITE_NAME', $script);
    }

    public function test_logo_file_and_client_flag_are_supported(): void
    {
        $script = $this->script();

        $this->assertStringContainsString('SITE_LOGO_FILE', $script);
        $this->assertStringContainsString('--client', $script);
        $this->assertStringContainsString('storage/app/private/site', $script);
    }

    public function test_stage_directory_is_per_domain(): void
    {
        $this->assertStringContainsString('/tmp/lodenica-deploy-$PROD_DOMAIN', $this->script());
    }
}
