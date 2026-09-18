<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/.github/workflows/cpanel-production-deploy.yml');
$guide = file_get_contents($root . '/docs/cpanel-push-deployment.md');
$rootRewrite = file_get_contents($root . '/.htaccess');
$cpanel = file_get_contents($root . '/.cpanel.yml');
$buildScript = file_get_contents($root . '/scripts/build_public_deployment.sh');
$shellPromoter = file_get_contents($root . '/scripts/promote_public_deployment.sh');
$ftpsPromoter = file_get_contents($root . '/scripts/promote_public_deployment_ftps.sh');

function deploy_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

deploy_expect(str_contains($workflow, 'workflow_dispatch:'), 'Production deployment must require an explicit manual run.');
deploy_expect(str_contains($workflow, 'environment: production'), 'Protected production environment is missing.');
deploy_expect(str_contains($workflow, 'ref: main'), 'Deployment must check out main.');
deploy_expect(str_contains($workflow, '"$FTP_REMOTE_PATH" != \'/\''), 'The scoped FTP root path must be accepted.');
deploy_expect(str_contains($workflow, 'CPANEL_FTP_REMOTE_PATH must be / or an absolute FTP path.'), 'Remote-path validation must report a clear error.');
deploy_expect(str_contains($ftpsPromoter, 'ftp:ssl-force true'), 'FTPS must be enforced.');
deploy_expect(str_contains($ftpsPromoter, 'ssl:verify-certificate true'), 'FTPS certificate validation must remain enabled.');
deploy_expect(str_contains($ftpsPromoter, 'lftp --env-password') && !str_contains($ftpsPromoter, 'FTP_PASSWORD_URI'), 'FTPS credentials must not be embedded in process arguments.');
deploy_expect(str_contains($ftpsPromoter, '-e "source \\"$PROMOTE_SCRIPT\\""'), 'FTPS finalization must run inside the authenticated session.');
deploy_expect(str_contains($ftpsPromoter, 'mirror --reverse'), 'Push-based deployment is missing.');
deploy_expect(!str_contains($ftpsPromoter, 'mirror --delete'), 'Deployment must not delete server-only files.');
deploy_expect(str_contains($ftpsPromoter, '.deploy-in-progress'), 'FTPS promotion maintenance lock is missing.');
deploy_expect(str_contains($ftpsPromoter, 'stale-deploy-files.txt'), 'FTPS promotion does not prune the tracked manifest delta.');
deploy_expect(str_contains($ftpsPromoter, 'cls -1a ${REMOTE_ROOT}/') && str_contains($ftpsPromoter, "grep -Eq '(^|/)\\.deploy-manifest$'"), 'FTPS promotion must distinguish a missing prior manifest from a download failure.');
deploy_expect(str_contains($ftpsPromoter, 'FTP_PORT must be between 1 and 65535.') && str_contains($ftpsPromoter, 'FTP_REMOTE_PATH must be / or an absolute FTP path.'), 'FTPS promoter must validate its own connection inputs.');
deploy_expect(str_contains($ftpsPromoter, "WORK_ROOT=\"\$(mktemp -d)\"") && str_contains($ftpsPromoter, "trap 'rm -rf -- \"\$WORK_ROOT\"' EXIT"), 'FTPS promotion scratch files must be isolated and cleaned.');
deploy_expect(str_contains($ftpsPromoter, '.htaccess.next') && str_contains($ftpsPromoter, 'mv ${REMOTE_ROOT}/.htaccess.next'), 'FTPS promotion must publish the lock-aware rewrite policy atomically.');
deploy_expect(str_contains($ftpsPromoter, '--exclude-glob .htaccess'), 'FTPS mirror must not overwrite the atomically published rewrite policy.');
$markerPosition = strpos($ftpsPromoter, 'deploy_version.txt.next');
$unlockPosition = strpos($ftpsPromoter, "rm -f %s/.deploy-in-progress");
deploy_expect($markerPosition !== false && $unlockPosition !== false && $markerPosition < $unlockPosition, 'Commit marker must publish before the maintenance lock is removed.');
deploy_expect(str_contains($shellPromoter, '--delay-updates'), 'cPanel shell promotion must stage file updates.');
deploy_expect(
    str_contains($buildScript, 'command -v rsync')
        && str_contains($buildScript, 'cPanel-compatible copy fallback'),
    'cPanel package build must work when rsync is unavailable.'
);
deploy_expect(
    str_contains($shellPromoter, 'command -v rsync')
        && str_contains($shellPromoter, 'validated manifest'),
    'cPanel promotion must have a manifest-driven fallback when rsync is unavailable.'
);
deploy_expect(
    str_contains($buildScript, '/usr/local/cpanel/3rdparty/bin/php')
        && str_contains($shellPromoter, '/usr/local/cpanel/3rdparty/bin/php')
        && str_contains($buildScript, '/opt/cpanel/ea-php*/root/usr/bin/php')
        && str_contains($shellPromoter, '/opt/cpanel/ea-php*/root/usr/bin/php'),
    'cPanel deployment must resolve a hosting-provided PHP CLI binary.'
);
deploy_expect(str_contains($shellPromoter, '.deploy-manifest.next'), 'cPanel shell promotion must atomically publish its manifest.');
deploy_expect(str_contains($buildScript, 'deployment_manifest_diff.php'), 'Deployment package manifest validation is missing.');
deploy_expect(str_contains($buildScript, 'outside an approved staging directory') && str_contains($buildScript, 'must not contain symbolic links'), 'Deployment package cleanup and symlink guards are incomplete.');
deploy_expect(str_contains($cpanel, 'build_public_deployment.sh') && str_contains($cpanel, 'promote_public_deployment.sh'), 'cPanel deployment must use the guarded release scripts.');
deploy_expect(str_contains($rootRewrite, '%{DOCUMENT_ROOT}/.deploy-in-progress'), 'Public traffic is not locked during promotion.');
deploy_expect(str_contains($buildScript, 'deploy_version.txt'), 'Commit marker generation is missing.');
deploy_expect(str_contains($workflow, 'Verify deployed commit'), 'Post-upload live verification is missing.');
deploy_expect(str_contains($rootRewrite, 'RewriteRule ^deploy_version\\.txt$ - [L,NC]'), 'Standalone host does not allow the exact deployment marker.');
deploy_expect(str_contains($buildScript, 'test ! -e "$TARGET_ROOT/private"'), 'Private directory guard is missing.');
deploy_expect(str_contains($buildScript, "-name 'config.php'"), 'Private config guard is missing.');
deploy_expect(!str_contains($workflow, 'secrets.GITHUB_TOKEN }}@'), 'Repository credentials must not be embedded in a URL.');
deploy_expect(str_contains($guide, 'Do not put credentials'), 'Secret handling guidance is missing.');

fwrite(STDOUT, "cPanel push-deployment contract passed.\n");
