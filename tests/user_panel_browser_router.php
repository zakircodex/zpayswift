<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

if ($path === '/test/support-viewport') {
    $width = max(320, min(1366, (int)($_GET['width'] ?? 390)));
    $height = max(560, min(1000, (int)($_GET['height'] ?? 844)));
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><style>'
        . 'html,body{margin:0;min-height:100%;background:#020a16;display:grid;place-items:start center}'
        . 'iframe{width:' . $width . 'px;height:' . $height . 'px;border:0;background:#07172f}'
        . '</style></head><body><iframe id="supportFrame" src="/user/support" title="Support viewport"></iframe></body></html>';
    exit;
}

if ($path === '/test/login-viewport') {
    $width = max(320, min(1366, (int)($_GET['width'] ?? 390)));
    $height = max(420, min(1000, (int)($_GET['height'] ?? 844)));
    $trusted = (string)($_GET['trusted'] ?? '0') === '1' ? '1' : '0';
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><style>'
        . 'html,body{margin:0;min-height:100%;background:#020a16;display:grid;place-items:start center}'
        . 'iframe{width:' . $width . 'px;height:' . $height . 'px;border:0;background:#07172f}'
        . '#loginMetrics{position:fixed;left:0;bottom:0;max-width:100%;font:10px monospace;color:#fff;background:#000}'
        . '</style></head><body><iframe id="loginFrame" src="/auth-test/login?trusted=' . $trusted . '" title="Login viewport"></iframe>'
        . '<output id="loginMetrics" aria-label="Login viewport metrics">waiting</output><script>'
        . 'const frame=document.getElementById("loginFrame"),out=document.getElementById("loginMetrics");'
        . 'frame.addEventListener("load",()=>{const report=()=>{const d=frame.contentDocument,root=d.getElementById("loginPageRoot"),input=d.activeElement,actions={loginPhone:"loginPhoneContinue",loginPassword:"loginPasswordContinue",loginPin:"loginPinContinue",loginOtpCode:"verifyLoginOtpBtn"},action=d.getElementById(actions[input?.id]||"loginPhoneContinue"),ir=input?.getBoundingClientRect(),ar=action?.getBoundingClientRect();out.textContent=JSON.stringify({width:d.documentElement.clientWidth,height:d.documentElement.clientHeight,scrollWidth:d.documentElement.scrollWidth,bodyScrollWidth:d.body.scrollWidth,rootClientHeight:root?.clientHeight,rootScrollHeight:root?.scrollHeight,activeInput:input?.id||"",inputTop:Math.round(ir?.top||0),actionBottom:Math.round(ar?.bottom||0),actionVisible:(ar?.bottom||0)<=d.documentElement.clientHeight+1});};setTimeout(report,600);setInterval(report,150);});'
        . '</script></body></html>';
    exit;
}

if ($path === '/test/forgot-viewport') {
    $width = max(320, min(1366, (int)($_GET['width'] ?? 390)));
    $height = max(420, min(1000, (int)($_GET['height'] ?? 844)));
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><style>'
        . 'html,body{margin:0;min-height:100%;background:#020a16;display:grid;place-items:start center}'
        . 'iframe{width:' . $width . 'px;height:' . $height . 'px;border:0;background:#07172f}'
        . '#forgotMetrics{position:fixed;left:0;bottom:0;max-width:100%;font:10px monospace;color:#fff;background:#000}'
        . '</style></head><body><iframe id="forgotFrame" src="/auth-test/forgot" title="Forgot viewport"></iframe>'
        . '<output id="forgotMetrics" aria-label="Forgot viewport metrics">waiting</output><script>'
        . 'const frame=document.getElementById("forgotFrame"),out=document.getElementById("forgotMetrics");'
        . 'frame.addEventListener("load",()=>{const report=()=>{const d=frame.contentDocument,root=d.getElementById("forgotPageRoot"),input=d.activeElement,actions={forgotPhone:"forgotPhoneContinue",otpCode:"forgotOtpContinue",newPassword:"updateForgotCredentialsBtn",confirmPassword:"updateForgotCredentialsBtn",newPin:"updateForgotCredentialsBtn",confirmPin:"updateForgotCredentialsBtn"},action=d.getElementById(actions[input?.id]||"forgotPhoneContinue"),ir=input?.getBoundingClientRect(),ar=action?.getBoundingClientRect();out.textContent=JSON.stringify({width:d.documentElement.clientWidth,height:d.documentElement.clientHeight,scrollWidth:d.documentElement.scrollWidth,bodyScrollWidth:d.body.scrollWidth,rootScrollHeight:root?.scrollHeight,activeInput:input?.id||"",inputTop:Math.round(ir?.top||0),actionBottom:Math.round(ar?.bottom||0),actionVisible:(ar?.bottom||0)<=d.documentElement.clientHeight+1});};setTimeout(report,600);setInterval(report,150);});'
        . '</script></body></html>';
    exit;
}

if ($path === '/test/register-viewport') {
    $width = max(320, min(1366, (int)($_GET['width'] ?? 390)));
    $height = max(420, min(1000, (int)($_GET['height'] ?? 844)));
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><style>'
        . 'html,body{margin:0;min-height:100%;background:#020a16;display:grid;place-items:start center}'
        . 'iframe{width:' . $width . 'px;height:' . $height . 'px;border:0;background:#07172f}'
        . '#registerMetrics{position:fixed;left:0;bottom:0;max-width:100%;font:10px monospace;color:#fff;background:#000}'
        . '</style></head><body><iframe id="registerFrame" src="/auth-test/register" title="Register viewport"></iframe>'
        . '<output id="registerMetrics" aria-label="Register viewport metrics">waiting</output><script>'
        . 'const frame=document.getElementById("registerFrame"),out=document.getElementById("registerMetrics");'
        . 'frame.addEventListener("load",()=>{const report=()=>{const d=frame.contentDocument,root=d.getElementById("registerPageRoot"),input=d.activeElement,actions={regPhone:"registerPhoneContinue",regName:"registerPersonalContinue",regEmail:"registerPersonalContinue",regIdentityNumber:"registerIdentityContinue",regPassword:"registerPasswordContinue",regConfirmPassword:"registerPasswordContinue",regPin:"registerPinContinue",regConfirmPin:"registerPinContinue",otpCode:"verifyRegisterOtpBtn"},action=d.getElementById(actions[input?.id]||"registerPhoneContinue"),ir=input?.getBoundingClientRect(),ar=action?.getBoundingClientRect();out.textContent=JSON.stringify({width:d.documentElement.clientWidth,height:d.documentElement.clientHeight,scrollWidth:d.documentElement.scrollWidth,bodyScrollWidth:d.body.scrollWidth,rootScrollHeight:root?.scrollHeight,step:root?.dataset.registerCurrentStep||"",activeInput:input?.id||"",inputTop:Math.round(ir?.top||0),actionBottom:Math.round(ar?.bottom||0),actionVisible:(ar?.bottom||0)<=d.documentElement.clientHeight+1});};setTimeout(report,600);setInterval(report,150);});'
        . '</script></body></html>';
    exit;
}

if ($path === '/api/user/proxy.php') {
    $action = trim((string)($_GET['action'] ?? ''));
    $authBody = json_decode((string)file_get_contents('php://input'), true);
    $authBody = is_array($authBody) ? $authBody : [];
    $trustedLogin = (string)($_COOKIE['LOCAL_AUTH_TRUST'] ?? '') === 'VALID';
    $forgotIdentityType = strtoupper((string)($_COOKIE['LOCAL_FORGOT_IDENTITY'] ?? 'NID')) === 'PASSPORT' ? 'PASSPORT' : 'NID';
    $forgotPhoneCountry = strtoupper((string)($_COOKIE['LOCAL_FORGOT_COUNTRY'] ?? 'MY')) === 'BD' ? 'BD' : 'MY';
    $forgotIdentityNumber = $forgotIdentityType === 'PASSPORT' ? 'LOCAL-PASSPORT-123' : 'LOCAL-ID-123';
    if ($action === 'login_check_number' && trim((string)($authBody['phone'] ?? '')) === '0120000000') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode(['ok' => false, 'code' => 'ACCOUNT_NOT_FOUND', 'message' => 'Local account not found', 'data' => []]);
        exit;
    }
    if ($action === 'login_verify_password' && (string)($authBody['password'] ?? '') !== 'correct-password') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['ok' => false, 'code' => 'WRONG_PASSWORD', 'message' => 'Local wrong password', 'data' => []]);
        exit;
    }
    if ($action === 'login_verify_pin' && (string)($authBody['pin'] ?? '') !== '1234') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['ok' => false, 'code' => 'WRONG_PIN', 'message' => 'Local wrong PIN', 'data' => []]);
        exit;
    }
    if ($action === 'login_verify_otp' && (string)($authBody['otp'] ?? '') !== '123456') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(422);
        echo json_encode(['ok' => false, 'code' => 'OTP_INVALID', 'message' => 'Local wrong OTP', 'data' => []]);
        exit;
    }
    if ($action === 'forgot_start' && trim((string)($authBody['phone'] ?? '')) === '0120000000') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode(['ok' => false, 'code' => 'ACCOUNT_NOT_FOUND', 'message' => 'Local account not found', 'data' => []]);
        exit;
    }
    if ($action === 'forgot_verify_identity' && (string)($authBody['identity_number'] ?? '') !== $forgotIdentityNumber) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['ok' => false, 'code' => 'IDENTITY_VERIFICATION_FAILED', 'message' => 'Identity verification failed. Please check your information.', 'data' => ['attempts_remaining' => 4]]);
        exit;
    }
    if ($action === 'forgot_verify_otp' && (string)($authBody['otp'] ?? '') !== '123456') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['ok' => false, 'code' => 'OTP_INVALID', 'message' => 'Local wrong OTP', 'data' => []]);
        exit;
    }
    $authResponses = [
        'country_defaults' => [
            'phone_country' => $forgotPhoneCountry,
            'pricing_country' => $forgotPhoneCountry,
            'currency' => $forgotPhoneCountry === 'BD' ? 'BDT' : 'MYR',
        ],
        'login_check_number' => [
            'exists' => true,
            'phone' => '60123456789',
            'phone_country' => 'MY',
            'name' => 'LOCAL TEST USER',
            'account_status' => 'ACTIVE',
            'trusted_login_available' => $trustedLogin,
            'pre_auth_token' => $trustedLogin ? 'LOCAL-TRUSTED-PREAUTH' : '',
        ],
        'login_trusted_account' => [
            'exists' => $trustedLogin,
            'phone' => $trustedLogin ? '60123456789' : '',
            'phone_country' => $trustedLogin ? 'MY' : '',
            'name' => $trustedLogin ? 'LOCAL TEST USER' : '',
            'account_status' => $trustedLogin ? 'ACTIVE' : '',
            'trusted_login_available' => $trustedLogin,
            'pre_auth_token' => $trustedLogin ? 'LOCAL-TRUSTED-PREAUTH' : '',
        ],
        'login_verify_password' => [
            'pre_auth_token' => 'LOCAL-PREAUTH',
            'user' => ['name' => 'LOCAL TEST USER'],
        ],
        'login_verify_pin' => [
            'pre_auth_token' => $trustedLogin ? 'LOCAL-TRUSTED-PREAUTH' : 'LOCAL-PREAUTH',
            'otp_required' => !$trustedLogin,
            'login_complete' => $trustedLogin,
            'session_active' => $trustedLogin,
        ],
        'login_send_otp' => [
            'pre_auth_token' => 'LOCAL-PREAUTH',
            'otp_request_id' => 'LOCAL-OTP-REQUEST',
            'masked_phone' => '601*****789',
            'expires_in_seconds' => 300,
        ],
        'login_resend_otp' => [
            'pre_auth_token' => 'LOCAL-PREAUTH',
            'otp_request_id' => 'LOCAL-OTP-REQUEST-2',
            'masked_phone' => '601*****789',
            'expires_in_seconds' => 300,
        ],
        'login_verify_otp' => [
            'login_complete' => true,
            'session_active' => true,
            'redirect' => 'dashboard',
        ],
        'forgot_start' => [
            'pre_auth_token' => 'LOCAL-FORGOT-PREAUTH',
            'identity_type' => $forgotIdentityType,
            'requires_identity' => true,
            'expires_in_seconds' => 900,
        ],
        'forgot_verify_identity' => [
            'pre_auth_token' => 'LOCAL-FORGOT-PREAUTH',
            'identity_type' => $forgotIdentityType,
            'identity_verified' => true,
        ],
        'forgot_send_otp' => [
            'pre_auth_token' => 'LOCAL-FORGOT-PREAUTH',
            'otp_request_id' => 'LOCAL-FORGOT-OTP',
            'masked_phone' => '601*****789',
            'expires_in_seconds' => 300,
            'reset_type' => 'PASSWORD_PIN',
        ],
        'forgot_resend_otp' => [
            'pre_auth_token' => 'LOCAL-FORGOT-PREAUTH',
            'otp_request_id' => 'LOCAL-FORGOT-OTP-2',
            'masked_phone' => '601*****789',
            'expires_in_seconds' => 300,
            'reset_type' => 'PASSWORD_PIN',
        ],
        'forgot_verify_otp' => [
            'pre_auth_token' => 'LOCAL-FORGOT-PREAUTH',
            'reset_authorization_token' => 'LOCAL-FORGOT-PREAUTH',
            'reset_type' => 'PASSWORD_PIN',
            'expires_in_seconds' => 900,
        ],
        'forgot_reset_credentials' => [
            'sessions_revoked' => true,
            'finalization_pending' => false,
        ],
        'register_precheck' => [
            'phone_country' => $forgotPhoneCountry,
            'phone_available' => true,
            'email_available' => true,
            'identity_available' => true,
        ],
        'registration_location_check' => [
            'gps_country' => $forgotPhoneCountry,
            'ip_country' => $forgotPhoneCountry,
            'pricing_country' => $forgotPhoneCountry,
            'currency' => $forgotPhoneCountry === 'BD' ? 'BDT' : 'MYR',
            'account_status' => 'ACTIVE',
            'requires_admin_review' => false,
        ],
        'register_send_otp' => [
            'pre_auth_token' => 'LOCAL-REGISTER-PREAUTH',
            'otp_request_id' => 'LOCAL-REGISTER-OTP',
            'masked_phone' => '601*****789',
            'expires_in_seconds' => 300,
        ],
        'register_resend_otp' => [
            'pre_auth_token' => 'LOCAL-REGISTER-PREAUTH',
            'otp_request_id' => 'LOCAL-REGISTER-OTP-2',
            'masked_phone' => '601*****789',
            'expires_in_seconds' => 300,
        ],
        'register_confirm' => [
            'account_status' => 'ACTIVE',
            'requires_admin_review' => false,
        ],
    ];
    if (isset($authResponses[$action])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'code' => 'SUCCESS', 'message' => 'Local auth response', 'data' => $authResponses[$action]]);
        exit;
    }
    if ($action === 'support_attachment') {
        header('Content-Type: image/png');
        header('Cache-Control: private, no-store');
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    $user = [
        'uid' => 'U-LOCAL-TEST',
        'name' => 'LOCAL TEST USER',
        'phone' => '60123456789',
        'email' => 'test@example.invalid',
        'role' => 'USER',
        'status' => 'ACTIVE',
        'pricing_country' => 'MY',
        'wallet_currency' => 'MYR',
        'created_at' => 1700000000,
    ];
    $wallet = [
        'available_balance' => 619.03,
        'hold_balance' => 0,
        'display_available_balance' => 619.03,
        'display_hold_balance' => 0,
        'wallet_currency' => 'MYR',
        'display_currency' => 'MYR',
        'pricing_country' => 'MY',
        'rate_myr_bdt' => 28.50,
    ];
    $responses = [
        'me' => ['user' => $user, 'csrf' => 'LOCAL-CSRF'],
        'dashboard_bootstrap' => [
            'user' => $user,
            'csrf' => 'LOCAL-CSRF',
            'wallet_summary' => ['wallet' => $wallet, 'pricing_country' => 'MY'],
            'request_logs' => ['items' => [['_test' => true]], 'history_complete' => false],
        ],
        'wallet_summary' => ['wallet' => $wallet, 'pricing_country' => 'MY'],
        'notifications_unread' => ['unread_count' => 2],
        'notifications_list' => [
            'items' => [[
                'notification_id' => 'N-LOCAL',
                'title' => 'Account update',
                'body' => 'This is local browser-test content.',
                'created_at' => 1700000000,
                'is_read' => false,
            ]],
            'unread_count' => 1,
        ],
        'request_logs' => [
            'items' => [
                [
                    'request_id' => 'TOPUP-LOCAL-001',
                    'request_type' => 'TOPUP',
                    'status' => 'SUCCESSFUL',
                    'operator' => 'GP',
                    'topup_number' => '01700000001',
                    'amount_bdt' => 100,
                    'wallet_currency' => 'MYR',
                    'wallet_debit_myr' => 3.51,
                    'rate_snapshot' => 28.5,
                    'fee_amount' => 0,
                    'balance_after' => 615.52,
                    'created_at' => time() - 1800,
                ],
                [
                    'request_id' => 'BUNDLE-LOCAL-001',
                    'request_type' => 'BUNDLE',
                    'status' => 'DONE',
                    'operator' => 'GP',
                    'bundle_number' => '01700000002',
                    'bundle_name' => '35 GB 30 Days',
                    'service_amount_bdt' => 400,
                    'bundle_commission' => 50,
                    'wallet_debit_currency' => 'MYR',
                    'wallet_debit_amount' => 12.28,
                    'rate_snapshot' => 28.5,
                    'created_at' => time() - 3600,
                ],
                [
                    'request_id' => 'MFS-LOCAL-001',
                    'request_type' => 'MFS',
                    'status' => 'WAITING_ADMIN',
                    'provider' => 'BKASH',
                    'provider_name' => 'bKash',
                    'receiver_number' => '01300000677',
                    'amount_bdt' => 620,
                    'amount_myr' => 20,
                    'wallet_currency' => 'MYR',
                    'exchange_rate' => 31,
                    'fee_amount' => 3,
                    'total_debit' => 23,
                    'balance_after' => 596.03,
                    'receipt_url' => '/api/mfs/receipt.php?t=LOCAL_TEST_CAPABILITY',
                    'created_at' => time() - 5400,
                ],
            ],
            'wallet_history' => [[
                'transfer_id' => 'TRANSFER-LOCAL-RECEIVED',
                'direction' => 'CREDIT',
                'status' => 'SUCCESS',
                'sender_name' => 'LOCAL SENDER',
                'sender_account' => '60120000001',
                'amount' => 50,
                'wallet_currency' => 'MYR',
                'created_at' => time() - 7200,
            ]],
            'add_money_history' => [[
                'request_id' => 'ADD-MONEY-LOCAL-001',
                'status' => 'APPROVED',
                'payment_account_name' => 'RHB BANK',
                'amount' => 100,
                'currency' => 'MYR',
                'transaction_id' => 'LOCAL-TXN-001',
                'created_at' => time() - 9000,
            ]],
            'month' => date('Y-m'),
        ],
        'transfer_history' => ['items' => [
            [
                'transfer_id' => 'TRANSFER-LOCAL-RECEIVED',
                'request_id' => 'TRANSFER-LOCAL-RECEIVED',
                'direction' => 'CREDIT',
                'status' => 'SUCCESS',
                'counterparty_name' => 'LOCAL SENDER',
                'counterparty_phone' => '6012*****01',
                'amount' => 50,
                'amount_text' => 'RM 50.00',
                'fee_text' => 'RM 0.00',
                'balance_after_text' => 'RM 619.03',
                'wallet_currency' => 'MYR',
                'created_at' => time() - 7200,
            ],
            [
                'transfer_id' => 'TRANSFER-LOCAL-SENT',
                'request_id' => 'TRANSFER-LOCAL-SENT',
                'direction' => 'DEBIT',
                'status' => 'COMPLETED',
                'counterparty_name' => 'LOCAL RECEIVER',
                'counterparty_phone' => '6012*****02',
                'amount' => 25,
                'amount_text' => 'RM 25.00',
                'fee_text' => 'RM 0.00',
                'total_paid_text' => 'RM 25.00',
                'balance_after_text' => 'RM 569.03',
                'wallet_currency' => 'MYR',
                'receipt_url' => '/api/transfer/receipt.php?t=LOCAL_TEST_CAPABILITY',
                'created_at' => time() - 10800,
            ],
        ]],
        'profile_get' => ['profile' => $user + ['wallet_currency' => 'MYR']],
        'support_config' => [
            'config' => [
                'support_notice' => 'Never share your password, PIN or OTP.',
                'support_hours' => 'Every day, 10:00 AM - 10:00 PM',
                'average_response_text' => 'Average response time: within 24 hours.',
                'attachments_enabled' => true,
                'max_attachments' => 3,
                'max_file_size' => 5242880,
                'email_enabled' => true,
                'support_email' => 'support@example.invalid',
                'whatsapp_enabled' => false,
            ],
            'categories' => [
                ['code' => 'ACCOUNT_LOGIN', 'name' => 'Account / Login', 'related_request_enabled' => false, 'attachment_enabled' => true],
                ['code' => 'ADD_MONEY', 'name' => 'Add Money', 'related_request_enabled' => true, 'attachment_enabled' => true],
                ['code' => 'MOBILE_TOPUP', 'name' => 'Mobile Top-Up', 'related_request_enabled' => true, 'attachment_enabled' => true],
                ['code' => 'BKASH', 'name' => 'bKash', 'related_request_enabled' => true, 'attachment_enabled' => true],
                ['code' => 'NAGAD', 'name' => 'Nagad', 'related_request_enabled' => true, 'attachment_enabled' => true],
                ['code' => 'ZPAY_TRANSFER', 'name' => 'Z-Pay Transfer', 'related_request_enabled' => true, 'attachment_enabled' => true],
                ['code' => 'BUNDLE', 'name' => 'Bundle', 'related_request_enabled' => true, 'attachment_enabled' => true],
                ['code' => 'WALLET_BALANCE', 'name' => 'Wallet / Balance', 'related_request_enabled' => true, 'attachment_enabled' => true],
                ['code' => 'TRANSACTION_ISSUE', 'name' => 'Transaction Issue', 'related_request_enabled' => true, 'attachment_enabled' => true],
                ['code' => 'OTHER', 'name' => 'Other', 'related_request_enabled' => false, 'attachment_enabled' => true],
            ],
        ],
        'support_list' => ['tickets' => [
            [
                'ticket_id' => 'SP202608090001',
                'subject' => 'Transfer problem',
                'category_code' => 'ZPAY_TRANSFER',
                'category_name' => 'Z-Pay Transfer',
                'status' => 'REPLIED',
                'status_label' => 'Replied',
                'last_message_preview' => 'We checked your transfer and replied.',
                'created_at' => 1786228200,
                'updated_at' => 1786231800,
                'last_message_at' => 1786231800,
                'user_unread' => true,
            ],
            [
                'ticket_id' => 'SP202608080002',
                'subject' => 'Bundle issue',
                'category_code' => 'BUNDLE',
                'category_name' => 'Bundle',
                'status' => 'CLOSED',
                'status_label' => 'Closed',
                'last_message_preview' => 'This conversation has been closed.',
                'created_at' => 1786141800,
                'updated_at' => 1786145400,
                'last_message_at' => 1786145400,
                'user_unread' => false,
            ],
        ]],
        'transfer_favorites' => ['favorites' => []],
        'add_money_settings' => [
            'profile' => [
                'pricing_country' => 'MY',
                'currency' => 'MYR',
                'payment_accounts' => [[
                    'account_id' => 'LOCAL-RHB',
                    'country' => 'MY',
                    'currency' => 'MYR',
                    'method' => 'BANK',
                    'display_name' => 'RHB BANK',
                    'account_name' => 'LOCAL TEST USER',
                    'account_number' => '1234567890',
                    'is_active' => true,
                ]],
            ],
            'history' => [],
        ],
        'bundle_offers_panel' => ['offers' => []],
        'bundle_offers' => ['offers' => []],
    ];
    if ($action === 'support_details' || $action === 'support_reply') {
        $ticketId = trim((string)($_GET['ticket_id'] ?? $_POST['ticket_id'] ?? 'SP202608090001')) ?: 'SP202608090001';
        $closed = $ticketId === 'SP202608080002';
        $data = [
            'ticket' => [
                'ticket_id' => $ticketId,
                'subject' => $closed ? 'Bundle issue' : 'Transfer problem',
                'category_code' => $closed ? 'BUNDLE' : 'ZPAY_TRANSFER',
                'category_name' => $closed ? 'Bundle' : 'Z-Pay Transfer',
                'status' => $closed ? 'CLOSED' : 'REPLIED',
                'status_label' => $closed ? 'Closed' : 'Replied',
                'created_at' => 1786228200,
            ],
            'messages' => [
                ['message_id' => 'MSG-1', 'sender_type' => 'USER', 'sender_name' => 'LOCAL TEST USER', 'message' => 'My transfer needs checking.', 'created_at' => 1786228200, 'attachment_ids' => []],
                ['message_id' => 'MSG-2', 'sender_type' => 'SUPPORT', 'sender_name' => 'Z-Pay Swift Support', 'message' => $closed ? 'This ticket is now closed.' : 'We checked your transfer and replied.', 'created_at' => 1786231800, 'attachment_ids' => ['ATT-1']],
            ],
            'attachments' => [
                ['attachment_id' => 'ATT-1', 'message_id' => 'MSG-2', 'original_name' => 'support-reply.png'],
            ],
        ];
        echo json_encode(['ok' => true, 'code' => 'SUCCESS', 'message' => 'Local support response', 'data' => $data]);
        exit;
    }
    if ($action === 'support_create') {
        echo json_encode(['ok' => true, 'code' => 'SUPPORT_TICKET_CREATED', 'message' => 'Local ticket created', 'data' => [
            'ticket' => ['ticket_id' => 'SP202608090003', 'subject' => (string)($_POST['subject'] ?? 'Local ticket'), 'status' => 'OPEN'],
        ]]);
        exit;
    }
    $data = $responses[$action] ?? [];
    echo json_encode(['ok' => true, 'code' => 'SUCCESS', 'message' => 'Local test response', 'data' => $data]);
    exit;
}

if (str_starts_with($path, '/api/') || str_starts_with($path, '/assets/')) {
    return false;
}

$authRoutes = [
    '/auth-test/login' => 'index.php',
    '/auth-test/register' => 'register.php',
    '/auth-test/forgot' => 'forgot.php',
];

if (isset($authRoutes[$path])) {
    session_name('zawtopup_user');
    session_start();
    $_SESSION = [];
    if ((string)($_GET['trusted'] ?? '') === '1') {
        setcookie('LOCAL_AUTH_TRUST', 'VALID', ['path' => '/', 'samesite' => 'Lax']);
        $_COOKIE['LOCAL_AUTH_TRUST'] = 'VALID';
    } elseif ((string)($_GET['trusted'] ?? '') === '0') {
        setcookie('LOCAL_AUTH_TRUST', '', ['expires' => time() - 3600, 'path' => '/', 'samesite' => 'Lax']);
        unset($_COOKIE['LOCAL_AUTH_TRUST']);
    }
    $forgotIdentityType = strtoupper((string)($_GET['identity'] ?? 'NID')) === 'PASSPORT' ? 'PASSPORT' : 'NID';
    $forgotPhoneCountry = strtoupper((string)($_GET['country'] ?? 'MY')) === 'BD' ? 'BD' : 'MY';
    setcookie('LOCAL_FORGOT_IDENTITY', $forgotIdentityType, ['path' => '/', 'samesite' => 'Lax']);
    setcookie('LOCAL_FORGOT_COUNTRY', $forgotPhoneCountry, ['path' => '/', 'samesite' => 'Lax']);
    $_COOKIE['LOCAL_FORGOT_IDENTITY'] = $forgotIdentityType;
    $_COOKIE['LOCAL_FORGOT_COUNTRY'] = $forgotPhoneCountry;
    require $root . '/api/user/' . $authRoutes[$path];
    exit;
}

$routes = [
    '/user/' => 'dashboard.php',
    '/user/dashboard' => 'dashboard.php',
    '/user/add-money' => 'add-money.php',
    '/user/transfer' => 'transfer.php',
    '/user/topup' => 'topup.php',
    '/user/bundle' => 'bundle.php',
    '/user/bkash' => 'bkash.php',
    '/user/nagad' => 'nagad.php',
    '/user/history' => 'history.php',
    '/user/services' => 'services.php',
    '/user/information' => 'information.php',
    '/user/info' => 'information.php',
    '/user/notifications' => 'notifications.php',
    '/user/profile' => 'profile.php',
    '/user/contact-us' => 'contact-us.php',
    '/user/support' => 'support.php',
    '/user/z-pay-transfer' => 'transfer.php',
    '/user/bundles' => 'bundle.php',
    '/user/send-money' => 'bkash.php',
];

if (isset($routes[$path])) {
    session_name('zawtopup_user');
    session_start();
    $_SESSION['user_session_token'] = 'LOCAL_BROWSER_TEST_SESSION';
    $_SESSION['user_csrf'] = 'LOCAL-CSRF';
    require $root . '/api/user/' . $routes[$path];
    exit;
}

http_response_code(404);
echo 'Not found';
