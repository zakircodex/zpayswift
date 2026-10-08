<?php
declare(strict_types=1);

function stage_deploy_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$cpanel = (string) file_get_contents($root . '/.cpanel.yml');
$script = (string) file_get_contents($root . '/scripts/deploy_mysql_stage.sh');
$runtimeVerifier = (string) file_get_contents($root . '/scripts/verify_mysql_stage_runtime.php');
$readme = (string) file_get_contents($root . '/database/mysql/README.md');
$productionDeployTest = (string) file_get_contents($root . '/tests/cpanel_push_deployment_test.php');

stage_deploy_expect(
    str_contains($cpanel, '/home/zedpayhe/repositories/zpayswift-stage/scripts/deploy_mysql_stage.sh'),
    'cPanel does not invoke the isolated stage deployer'
);
stage_deploy_expect(
    !str_contains($cpanel, '/home/zedpayhe/public_html')
        && !str_contains($cpanel, 'REPOPATH=/home/zedpayhe/repositories/zpayswift;'),
    'the stage cPanel contract still targets production'
);
stage_deploy_expect(
    str_contains($script, 'REPOSITORY_ROOT="/home/zedpayhe/repositories/zpayswift-stage"')
        && str_contains($script, 'PUBLIC_ROOT="/home/zedpayhe/stage.zpayswift.com"')
        && str_contains($script, 'EXPECTED_BRANCH="cpanel/mysql-stage"')
        && str_contains($script, 'EXPECTED_UPSTREAM="origin/codex/mysql-stage"')
        && str_contains($script, 'rev-parse --is-inside-work-tree')
        && str_contains($script, 'rev-parse --show-toplevel'),
    'the deployer is not pinned to the isolated repository, branch, upstream and document root'
);
stage_deploy_expect(
    str_contains($script, 'scripts/verify_mysql_stage_runtime.php')
        && str_contains($runtimeVerifier, "constant('APP_ENVIRONMENT') === 'stage'")
        && str_contains($runtimeVerifier, "constant('DATASTORE_DRIVER') === 'mysql'")
        && str_contains($runtimeVerifier, "zpay_mysql_assert_environment('STAGE')"),
    'the deployer does not fail closed on the private runtime and database guards'
);
stage_deploy_expect(
    str_contains($runtimeVerifier, 'Live outbound integration is enabled in stage.')
        && str_contains($runtimeVerifier, 'SMSS360_API_KEY')
        && str_contains($runtimeVerifier, 'TELEGRAM_BOT_TOKEN'),
    'the deployer does not reject enabled live outbound integrations'
);
stage_deploy_expect(
    str_contains($script, '# BEGIN ZPAY STAGE AUTH')
        && str_contains($script, 'AuthUserFile /home/zedpayhe/.htpasswds/stage.zpayswift.com/passwd')
        && str_contains($script, 'Require valid-user'),
    'Basic Auth is not injected before stage promotion'
);
stage_deploy_expect(
    str_contains($script, '/bin/bash "$REPOSITORY_ROOT/scripts/build_public_deployment.sh"')
        && str_contains($script, '/bin/bash "$REPOSITORY_ROOT/scripts/promote_public_deployment.sh"'),
    'stage build and promotion must use the cPanel-compatible Bash entry points'
);
stage_deploy_expect(
    !str_contains($script, 'rm -f -- "$PUBLIC_ROOT/.stage-not-ready"')
        && !str_contains($script, 'rm -rf -- "$PUBLIC_ROOT"'),
    'the stage deployer may remove the release lock or document root'
);
stage_deploy_expect(
    str_contains($script, 'done < "$PUBLIC_ROOT/.deploy-manifest"')
        && !str_contains($script, 'find "$PUBLIC_ROOT" -type f -exec chmod'),
    'permissions are not scoped to deployment-owned files'
);
stage_deploy_expect(
    str_contains($readme, 'never removes `.stage-not-ready`'),
    'the manual stage unlock boundary is undocumented'
);
stage_deploy_expect(
    str_contains($productionDeployTest, "['codex/mysql-stage', 'cpanel/mysql-stage']")
        && str_contains($productionDeployTest, 'allowed only on an isolated MySQL stage branch'),
    'the production deployment contract does not constrain the stage cPanel exception'
);

echo "mysql stage deployment tests passed\n";
