<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/.github/workflows/cpanel-production-deploy.yml');
$guide = file_get_contents($root . '/docs/cpanel-push-deployment.md');

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
deploy_expect(str_contains($workflow, 'ftp:ssl-force true'), 'FTPS must be enforced.');
deploy_expect(str_contains($workflow, 'ssl:verify-certificate true'), 'FTPS certificate validation must remain enabled.');
deploy_expect(str_contains($workflow, 'mirror --reverse'), 'Push-based deployment is missing.');
deploy_expect(!str_contains($workflow, 'mirror --delete'), 'Deployment must not delete server-only files.');
deploy_expect(str_contains($workflow, 'deployment/deploy_version.txt'), 'Commit marker generation is missing.');
deploy_expect(str_contains($workflow, 'Verify deployed commit'), 'Post-upload live verification is missing.');
deploy_expect(str_contains($workflow, "test ! -e deployment/private"), 'Private directory guard is missing.');
deploy_expect(str_contains($workflow, "-name 'config.php'"), 'Private config guard is missing.');
deploy_expect(!str_contains($workflow, 'secrets.GITHUB_TOKEN }}@'), 'Repository credentials must not be embedded in a URL.');
deploy_expect(str_contains($guide, 'Do not put credentials'), 'Secret handling guidance is missing.');

fwrite(STDOUT, "cPanel push-deployment contract passed.\n");
