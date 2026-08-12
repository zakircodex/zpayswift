<?php
declare(strict_types=1);

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

function user_web_registration_identity_hashes(string $identityNumber): array
{
    $trimmed = trim($identityNumber);
    if ($trimmed === '') {
        return [];
    }

    $upper = strtoupper($trimmed);
    $canonical = auth_app_identity_hash($identityNumber);
    $compact = preg_replace('/[^A-Z0-9]+/', '', $upper) ?? '';
    $hashes = [$canonical];
    if ($compact !== '') {
        $hashes[] = hash('sha256', $compact);
    }

    return array_values(array_unique(array_filter($hashes)));
}

function user_web_registration_identity_lookup(array $hashes, string $identityType): array
{
    $identityType = strtoupper(trim($identityType));
    if (!in_array($identityType, ['NID', 'PASSPORT'], true)) {
        return ['ok' => false, 'occupied' => false, 'code' => 'IDENTITY_TYPE_INVALID'];
    }

    $validHashes = [];
    foreach ($hashes as $hash) {
        $hash = strtolower(trim((string)$hash));
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) === 1) {
            $validHashes[] = $hash;
        }
    }
    $validHashes = array_values(array_unique($validHashes));
    if (empty($validHashes)) {
        return ['ok' => false, 'occupied' => false, 'code' => 'IDENTITY_HASH_INVALID'];
    }

    foreach ($validHashes as $hash) {
        if (reg_app_document_owner_uid($hash, $identityType) !== '') {
            return ['ok' => true, 'occupied' => true, 'source' => 'INDEX'];
        }
    }

    // Pre-index registrations still have the canonical hash on their private user row.
    foreach ($validHashes as $hash) {
        $response = fb_request('GET', 'USERS', null, [
            'orderBy' => json_encode('identity_number_hash', JSON_UNESCAPED_SLASHES),
            'equalTo' => json_encode($hash, JSON_UNESCAPED_SLASHES),
            'limitToFirst' => 2,
        ]);
        if (empty($response['ok'])) {
            return ['ok' => false, 'occupied' => false, 'code' => 'IDENTITY_LOOKUP_FAILED'];
        }

        $rows = $response['json'] ?? null;
        if (is_array($rows) && !empty($rows)) {
            return [
                'ok' => true,
                'occupied' => true,
                'source' => 'LEGACY_USER_HASH',
                'multiple' => count($rows) > 1,
            ];
        }
    }

    return ['ok' => true, 'occupied' => false, 'source' => 'NONE'];
}
