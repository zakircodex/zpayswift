<?php
declare(strict_types=1);

function stage_deploy_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function stage_deploy_current_branch(string $root): string
{
    $gitPath = $root . '/.git';
    if (is_file($gitPath)) {
        $pointer = trim((string) file_get_contents($gitPath));
        if (str_starts_with($pointer, 'gitdir: ')) {
            $gitPath = substr($pointer, 8);
            if (!str_starts_with($gitPath, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $gitPath) !== 1) {
                $gitPath = $root . '/' . $gitPath;
            }
        }
    }

    $head = is_dir($gitPath) ? trim((string) @file_get_contents($gitPath . '/HEAD')) : '';
    if (str_starts_with($head, 'ref: refs/heads/')) {
        return substr($head, strlen('ref: refs/heads/'));
    }

    return trim((string) (getenv('GITHUB_HEAD_REF') ?: getenv('GITHUB_REF_NAME') ?: ''));
}

$root = dirname(__DIR__);
$script = (string) file_get_contents($root . '/scripts/deploy_mysql_stage.sh');
$cpanel = (string) file_get_contents($root . '/.cpanel.yml');
$runtimeVerifier = (string) file_get_contents($root . '/scripts/verify_mysql_stage_runtime.php');
$readme = (string) file_get_contents($root . '/database/mysql/README.md');
$productionDeployTest = (string) file_get_contents($root . '/tests/cpanel_push_deployment_test.php');

$stageDeployment = str_contains($cpanel, 'deploy_mysql_stage.sh');
if ($stageDeployment) {
    stage_deploy_expect(
        in_array(stage_deploy_current_branch($root), ['codex/mysql-stage', 'cpanel/mysql-stage'], true),
        'the stage cPanel target is allowed only on an isolated MySQL stage branch'
    );
    stage_deploy_expect(
        str_contains($cpanel, '/home/zedpayhe/repositories/zpayswift-stage/scripts/deploy_mysql_stage.sh')
            && !str_contains($cpanel, '/home/zedpayhe/public_html'),
        'the stage branch cPanel task is not isolated from production'
    );
} else {
    stage_deploy_expect(
        str_contains($cpanel, '/home/zedpayhe/repositories/zpayswift')
            && str_contains($cpanel, '/home/zedpayhe/public_html')
            && str_contains($cpanel, 'build_public_deployment.sh')
            && str_contains($cpanel, 'promote_public_deployment.sh')
            && !str_contains($cpanel, '/home/zedpayhe/repositories/zpayswift-stage'),
        'the production cPanel task is not pinned to the guarded production release path'
    );
}
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
    str_contains($runtimeVerifier, "['USER_LOGIN', 'ADMIN_LOGIN', 'SUBADMIN_LOGIN']")
        && str_contains($runtimeVerifier, 'count($previewPhones) !== 1'),
    'stage OTP preview guard must remain limited to one phone and login purposes'
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
