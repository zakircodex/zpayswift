<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/lib/sms_smss360.php';

function sms360_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$accepted = smss360_parse_response(
    '<sms><statuscode>1606</statuscode><statusmsg>SMS accepted.</statusmsg><sms><items><referenceid>provider-123</referenceid></items></sms></sms>',
    200,
    'request-123'
);
sms360_expect($accepted['ok'] === true, 'Official accepted response was rejected');
sms360_expect($accepted['message'] === 'SMS accepted.', 'Provider status message was lost');
sms360_expect($accepted['reference_id'] === 'provider-123', 'Provider reference was lost');

$rejected = smss360_parse_response(
    '<sms><statuscode>WF0016</statuscode><statusmsg>This message is not allowed to send without being whitelisted.</statusmsg></sms>',
    200,
    'request-456'
);
sms360_expect($rejected['ok'] === false, 'Whitelisting rejection was accepted');
sms360_expect($rejected['code'] === 'WF0016', 'Provider rejection code was lost');
sms360_expect(str_contains($rejected['message'], 'whitelisted'), 'Provider rejection reason was lost');
sms360_expect($rejected['reference_id'] === 'request-456', 'Fallback reference was lost');

echo "SMS360 response tests passed.\n";
