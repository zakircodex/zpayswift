<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$assertions = 0;
$fixtureRoot = sys_get_temp_dir() . '/zpay-kyc-cleanup-' . bin2hex(random_bytes(6));
$privateRoot = $fixtureRoot . '/private';

function cleanup_expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function app_private_config_path(): string
{
    global $privateRoot;
    return $privateRoot . '/config.php';
}

function cleanup_token(string $suffix): string
{
    return 'URKYC_' . str_pad($suffix, 24, 'X');
}

function cleanup_file(string $token, string $name): string
{
    $dir = user_registration_kyc_token_root($token);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $path = $dir . '/' . $name . '.png';
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    file_put_contents($path, $png);
    return $path;
}

final class KycCleanupFixture
{
    public array $rows = [];
    public array $users = [];
    public array $identityIndexes = [];
    public array $etags = [];
    public int $fileDeleteCalls = 0;
    public $beforeDelete = null;

    public function dependencies(): array
    {
        return [
            'list_preauth' => fn(): array => ['ok' => true, 'value' => $this->rows],
            'snapshot' => function (string $token): array {
                return [
                    'ok' => array_key_exists($token, $this->rows),
                    'value' => $this->rows[$token] ?? null,
                    'etag' => (string)($this->etags[$token] ?? ''),
                ];
            },
            'claim' => function (string $token, array $row, string $etag): array {
                if (!isset($this->rows[$token]) || (string)($this->etags[$token] ?? '') !== $etag) {
                    return ['ok' => false, 'status' => 412];
                }
                $this->rows[$token] = $row;
                $this->etags[$token] = (string)((int)$this->etags[$token] + 1);
                return ['ok' => true, 'status' => 200];
            },
            'read_user' => fn(string $uid): array => [
                'ok' => true,
                'value' => $this->users[$uid] ?? null,
                'etag' => 'user',
            ],
            'read_identity_index' => fn(string $path): array => [
                'ok' => true,
                'value' => $this->identityIndexes[$path] ?? null,
                'etag' => 'index',
            ],
            'delete_record' => function (string $token, string $etag): array {
                if (!isset($this->rows[$token]) || (string)($this->etags[$token] ?? '') !== $etag) {
                    return ['ok' => false, 'status' => 412];
                }
                unset($this->rows[$token], $this->etags[$token]);
                return ['ok' => true, 'status' => 200];
            },
            'delete_files' => function (string $token): array {
                $this->fileDeleteCalls++;
                return user_registration_kyc_cleanup_remove_token_files($token);
            },
            'before_delete' => function (string $token): void {
                if (is_callable($this->beforeDelete)) {
                    ($this->beforeDelete)($token, $this);
                }
            },
        ];
    }

    public function add(string $token, array $row): void
    {
        $row['identity_type'] = $row['identity_type'] ?? 'NID';
        $row['identity_number_hash'] = $row['identity_number_hash'] ?? hash('sha256', $token);
        $this->rows[$token] = $row;
        $this->etags[$token] = '1';
    }
}

require_once $root . '/api/lib/user_registration_kyc.php';

$now = 1000;
$ttl = 100;
$fixture = new KycCleanupFixture();

$freshToken = cleanup_token('FRESH');
$freshPath = cleanup_file($freshToken, 'document');
$fixture->add($freshToken, [
    'uid' => 'UID_FRESH',
    'status' => 'DOCUMENT_UPLOADED',
    'expires_at' => 950,
    'KYC' => ['document_path_private' => $freshPath],
]);

$expiredToken = cleanup_token('EXPIRED');
$expiredPath = cleanup_file($expiredToken, 'document');
$expiredSelfie = cleanup_file($expiredToken, 'selfie');
$fixture->add($expiredToken, [
    'uid' => 'UID_EXPIRED',
    'status' => 'KYC_PENDING',
    'expires_at' => 800,
    'KYC' => [
        'document_path_private' => $expiredPath,
        'selfie_path_private' => $expiredSelfie,
    ],
]);

$activeToken = cleanup_token('ACTIVE');
$activePath = cleanup_file($activeToken, 'document');
$fixture->add($activeToken, [
    'uid' => 'UID_ACTIVE',
    'status' => 'OTP_PENDING',
    'expires_at' => 800,
    'KYC' => ['document_path_private' => $activePath],
]);
$fixture->users['UID_ACTIVE'] = [
    'uid' => 'UID_ACTIVE',
    'account_status' => 'ACTIVE',
    'KYC' => ['document_path_private' => $activePath],
];

$reviewToken = cleanup_token('REVIEW');
$reviewPath = cleanup_file($reviewToken, 'document');
$fixture->add($reviewToken, [
    'uid' => 'UID_REVIEW',
    'status' => 'OTP_PENDING',
    'expires_at' => 800,
    'KYC' => ['document_path_private' => $reviewPath],
]);
$fixture->users['UID_REVIEW'] = [
    'uid' => 'UID_REVIEW',
    'account_status' => 'REVIEW',
    'KYC' => ['document_path_private' => $reviewPath],
];

$completedToken = cleanup_token('COMPLETED');
$completedPath = cleanup_file($completedToken, 'document');
$fixture->add($completedToken, [
    'uid' => 'UID_COMPLETED',
    'status' => 'COMPLETED',
    'completed_at' => 850,
    'expires_at' => 800,
    'KYC' => ['document_path_private' => $completedPath],
]);

$rejectedToken = cleanup_token('REJECTED');
$rejectedPath = cleanup_file($rejectedToken, 'document');
$fixture->add($rejectedToken, [
    'uid' => 'UID_REJECTED',
    'status' => 'EXPIRED',
    'expires_at' => 800,
    'KYC' => ['document_path_private' => $rejectedPath],
]);
$fixture->users['UID_REJECTED'] = [
    'uid' => 'UID_REJECTED',
    'account_status' => 'REJECTED',
    'KYC' => ['document_path_private' => $rejectedPath],
];

$indexedToken = cleanup_token('INDEXED');
$indexedPath = cleanup_file($indexedToken, 'document');
$indexedHash = hash('sha256', 'completed-owner-index');
$fixture->add($indexedToken, [
    'uid' => '',
    'status' => 'EXPIRED',
    'expires_at' => 800,
    'identity_type' => 'PASSPORT',
    'identity_number_hash' => $indexedHash,
    'KYC' => ['document_path_private' => $indexedPath],
]);
$fixture->identityIndexes['USER_IDENTITY_INDEX/' . $indexedHash] = ['uid' => 'UID_INDEX_OWNER'];
$fixture->users['UID_INDEX_OWNER'] = [
    'uid' => 'UID_INDEX_OWNER',
    'account_status' => 'ACTIVE',
    'KYC' => ['document_path_private' => $indexedPath],
];

$missingToken = cleanup_token('MISSING');
$fixture->add($missingToken, [
    'uid' => 'UID_MISSING',
    'status' => 'EXPIRED',
    'expires_at' => 800,
    'KYC' => ['document_path_private' => user_registration_kyc_token_root($missingToken) . '/gone.png'],
]);

$orphanToken = cleanup_token('ORPHAN');
$orphanPath = cleanup_file($orphanToken, 'uncommitted-upload');
$fixture->add($orphanToken, [
    'uid' => 'UID_ORPHAN',
    'status' => 'EXPIRED',
    'expires_at' => 800,
    'KYC' => ['status' => 'PENDING_UPLOAD'],
]);

$raceToken = cleanup_token('RACE');
$racePath = cleanup_file($raceToken, 'document');
$fixture->add($raceToken, [
    'uid' => 'UID_RACE',
    'status' => 'EXPIRED',
    'expires_at' => 800,
    'KYC' => ['document_path_private' => $racePath],
]);
$fixture->beforeDelete = static function (string $token, KycCleanupFixture $state) use ($raceToken, $racePath): void {
    if ($token === $raceToken) {
        $state->users['UID_RACE'] = [
            'uid' => 'UID_RACE',
            'account_status' => 'ACTIVE',
            'KYC' => ['document_path_private' => $racePath],
        ];
    }
};

$result = user_registration_kyc_cleanup_run([
    'now' => $now,
    'ttl' => $ttl,
    'limit' => 100,
], $fixture->dependencies());

cleanup_expect(!empty($result['ok']), 'cleanup should complete without a job-level failure');
cleanup_expect(is_file($freshPath), 'fresh temporary KYC must not be deleted');
cleanup_expect(!is_file($expiredPath) && !is_file($expiredSelfie), 'expired abandoned KYC files must be deleted');
cleanup_expect(!isset($fixture->rows[$expiredToken]), 'expired abandoned metadata must be removed');
cleanup_expect(is_file($activePath), 'ACTIVE account KYC must never be deleted');
cleanup_expect(is_file($reviewPath), 'REVIEW account KYC must never be deleted');
cleanup_expect(is_file($completedPath), 'completed registration KYC must never be deleted');
cleanup_expect(is_file($rejectedPath), 'REJECTED account evidence must be retained');
cleanup_expect(is_file($indexedPath), 'canonical identity index must protect finalized KYC when the pre-auth UID is missing');
cleanup_expect(!isset($fixture->rows[$missingToken]) && (int)$result['missing'] === 1, 'missing files must be handled idempotently');
cleanup_expect(!is_file($orphanPath) && !isset($fixture->rows[$orphanToken]), 'expired uncommitted token-root upload must be cleaned');
cleanup_expect(is_file($racePath) && isset($fixture->rows[$raceToken]), 'finalize-vs-cleanup recheck must preserve newly permanent KYC');

$second = user_registration_kyc_cleanup_run([
    'now' => $now,
    'ttl' => $ttl,
    'limit' => 100,
], $fixture->dependencies());
cleanup_expect((int)$second['deleted_records'] === 0 && is_file($racePath), 'a duplicate cleanup run must not delete permanent files');

$dryFixture = new KycCleanupFixture();
$dryToken = cleanup_token('DRYRUN');
$dryPath = cleanup_file($dryToken, 'document');
$dryFixture->add($dryToken, [
    'uid' => 'UID_DRY',
    'status' => 'EXPIRED',
    'expires_at' => 800,
    'KYC' => ['document_path_private' => $dryPath],
]);
$dry = user_registration_kyc_cleanup_run([
    'now' => $now,
    'ttl' => $ttl,
    'dry_run' => true,
], $dryFixture->dependencies());
cleanup_expect((int)$dry['would_delete'] === 1 && is_file($dryPath), 'dry-run must report without deleting files');
cleanup_expect(isset($dryFixture->rows[$dryToken]) && $dryFixture->fileDeleteCalls === 0, 'dry-run must not mutate metadata or call file deletion');

$limitFixture = new KycCleanupFixture();
$limitPaths = [];
for ($i = 1; $i <= 3; $i++) {
    $token = cleanup_token('LIMIT' . $i);
    $limitPaths[$token] = cleanup_file($token, 'document');
    $limitFixture->add($token, [
        'uid' => 'UID_LIMIT_' . $i,
        'status' => 'EXPIRED',
        'expires_at' => 700 + $i,
        'KYC' => ['document_path_private' => $limitPaths[$token]],
    ]);
}
$limited = user_registration_kyc_cleanup_run([
    'now' => $now,
    'ttl' => $ttl,
    'limit' => 2,
], $limitFixture->dependencies());
cleanup_expect((int)$limited['deleted_records'] === 2 && count($limitFixture->rows) === 1, 'batch limit must bound deletions');

$contendedFixture = new KycCleanupFixture();
$contendedToken = cleanup_token('CONTENDED');
$contendedPath = cleanup_file($contendedToken, 'document');
$contendedFixture->add($contendedToken, [
    'uid' => 'UID_CONTENDED',
    'status' => 'EXPIRED',
    'expires_at' => 800,
    'KYC' => ['document_path_private' => $contendedPath],
]);
$contendedDeps = $contendedFixture->dependencies();
$contendedDeps['claim'] = static fn(string $token, array $row, string $etag): array => [
    'ok' => false,
    'status' => 412,
];
$contended = user_registration_kyc_cleanup_run([
    'now' => $now,
    'ttl' => $ttl,
], $contendedDeps);
cleanup_expect(is_file($contendedPath) && isset($contendedFixture->rows[$contendedToken]), 'a lost concurrent CAS claim must leave KYC untouched');
cleanup_expect((int)$contended['deleted_records'] === 0 && $contendedFixture->fileDeleteCalls === 0, 'claim contention must not reach file deletion');

$lookupFixture = new KycCleanupFixture();
$lookupToken = cleanup_token('LOOKUPFAIL');
$lookupPath = cleanup_file($lookupToken, 'document');
$lookupFixture->add($lookupToken, [
    'uid' => 'UID_LOOKUP_FAIL',
    'status' => 'EXPIRED',
    'expires_at' => 800,
    'KYC' => ['document_path_private' => $lookupPath],
]);
$lookupDeps = $lookupFixture->dependencies();
$lookupDeps['read_user'] = static fn(string $uid): array => ['ok' => false, 'value' => null];
$lookupFailure = user_registration_kyc_cleanup_run([
    'now' => $now,
    'ttl' => $ttl,
], $lookupDeps);
cleanup_expect(empty($lookupFailure['ok']) && is_file($lookupPath), 'an uncertain user lookup must fail closed and preserve KYC');
cleanup_expect($lookupFixture->fileDeleteCalls === 0 && isset($lookupFixture->rows[$lookupToken]), 'lookup failure must not mutate file or metadata');

$helperSource = file_get_contents($root . '/api/lib/user_registration_kyc.php') ?: '';
$cliSource = file_get_contents($root . '/api/tools/cleanup_registration_kyc.php') ?: '';
$configSource = file_get_contents($root . '/api/config.example.php') ?: '';
$confirmSource = file_get_contents($root . '/api/auth/user_register_confirm.php') ?: '';
$reviewSource = file_get_contents($root . '/api/lib/account_review.php') ?: '';
cleanup_expect(str_contains($configSource, 'REGISTRATION_KYC_TEMP_TTL_SECONDS') && str_contains($configSource, '60 * 60 * 72'), 'example config must document the conservative 72-hour retention');
cleanup_expect(str_contains($cliSource, "PHP_SAPI !== 'cli'") && str_contains($cliSource, '--dry-run') && str_contains($cliSource, '--limit='), 'cleanup command must be CLI-only with dry-run and batch controls');
cleanup_expect(!str_contains($cliSource, 'document_path_private') && !str_contains($cliSource, 'selfie_path_private'), 'CLI output must not expose private KYC paths');
cleanup_expect(str_contains($helperSource, 'fb_put_if_match') && str_contains($helperSource, 'fb_delete_if_match'), 'cleanup metadata must use Firebase CAS claim/delete');
cleanup_expect(!str_contains($helperSource, "fb_get_with_etag('USERS')"), 'cleanup must not read the full user tree');
cleanup_expect(str_contains($confirmSource, "'status' => 'COMPLETED'") && str_contains($confirmSource, "'KYC' => \$userKyc"), 'completed Web registrations must keep permanent KYC references');
cleanup_expect(str_contains($reviewSource, 'account_review_send_telegram'), 'Telegram account review integration must remain available');

foreach (array_merge([$freshPath, $activePath, $reviewPath, $completedPath, $rejectedPath, $indexedPath, $racePath, $dryPath, $orphanPath, $contendedPath, $lookupPath], array_values($limitPaths)) as $path) {
    @unlink($path);
    @rmdir(dirname($path));
}
@rmdir(user_registration_kyc_private_root());
@rmdir($privateRoot);
@rmdir($fixtureRoot);

echo "Registration KYC cleanup tests passed ({$assertions} assertions).\n";
