<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'web_root' . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR . 'bootstrap.php';

function eel_cli_write(string $message): void
{
    fwrite(STDOUT, $message);
}

function eel_cli_writeln(string $message): void
{
    eel_cli_write($message . PHP_EOL);
}

function eel_cli_prompt(string $message): string
{
    eel_cli_write($message);

    $input = fgets(STDIN);

    return $input === false ? '' : trim($input);
}

function eel_cli_prompt_hidden_windows(string $message): string
{
    eel_cli_write($message);

    $command = 'powershell -Command "$p = Read-Host -AsSecureString; ' .
               '[Runtime.InteropServices.Marshal]::PtrToStringAuto(' .
               '[Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"';

    $input = shell_exec($command);

    return $input === null ? '' : trim($input);
}

function eel_cli_prompt_hidden(string $message): string
{
    if (stripos(PHP_OS, 'WIN') === 0) {
        return eel_cli_prompt_hidden_windows($message);
    }

    eel_cli_write($message);
    system('stty -echo');
    $input = fgets(STDIN);
    system('stty echo');
    eel_cli_write(PHP_EOL);

    return $input === false ? '' : trim($input);
}

function eel_cli_prompt_yes_no(string $message): bool
{
    while (true) {
        $answer = strtolower(eel_cli_prompt($message));

        if (in_array($answer, ['y', 'yes'], true)) {
            return true;
        }

        if (in_array($answer, ['n', 'no', ''], true)) {
            return false;
        }

        eel_cli_writeln('Please answer Yes or No.');
    }
}

function eel_cli_find_user(UserAuthenticationService $userAuthenticationService, string $username): ?array
{
    $needle = strtolower(trim($username));
    if ($needle === '') {
        return null;
    }

    foreach ($userAuthenticationService->listUsers() as $user) {
        $emailAddress = strtolower(trim((string)($user['email_address'] ?? '')));
        $displayName = strtolower(trim((string)($user['display_name'] ?? '')));

        if ($needle === $emailAddress || $needle === $displayName) {
            return $user;
        }
    }

    return null;
}

function eel_cli_reset_user_password(
    UserAuthenticationService $userAuthenticationService,
    int $userId,
    string $password
): array {
    return $userAuthenticationService->setPasswordDirectly($userId, $password);
}

function eel_cli_start_user_otp_reset(OtpService $otpService, int $userId): string
{
    $otpService->resetOtp($userId);

    return $otpService->generateOTPsecret($userId);
}

function eel_cli_finish_user_otp_reset(OtpService $otpService, int $userId, string $code): bool
{
    return $otpService->enableOTP($userId, $code);
}

function eel_cli_connect_database(): void
{
    InterfaceDB::fetchColumn('SELECT 1');
}

function eel_cli_run_reset_password_tool(): int
{
    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, 'This tool can only be run from the command line.' . PHP_EOL);
        return 1;
    }

    try {
        eel_cli_connect_database();
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Database connection failed: ' . $exception->getMessage() . PHP_EOL);
        return 1;
    }

    eel_cli_writeln('Connected to database');

    $userAuthenticationService = new UserAuthenticationService();
    $otpService = new OtpService('EEL Accounts');

    $username = eel_cli_prompt('Enter Username to work on: ');
    $user = eel_cli_find_user($userAuthenticationService, $username);

    if ($user === null) {
        eel_cli_writeln('User Not found');
        eel_cli_writeln('-EOL-');
        return 1;
    }

    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        eel_cli_writeln('User Not found');
        eel_cli_writeln('-EOL-');
        return 1;
    }

    $password = eel_cli_prompt_hidden('User found, enter password: ');
    $passwordResult = eel_cli_reset_user_password($userAuthenticationService, $userId, $password);

    if (empty($passwordResult['success'])) {
        foreach ((array)($passwordResult['errors'] ?? ['Password update failed.']) as $error) {
            eel_cli_writeln((string)$error);
        }
        eel_cli_writeln('-EOL-');
        return 1;
    }

    if (!eel_cli_prompt_yes_no('Reset OTP? ')) {
        eel_cli_writeln('OTP not updated for user: ' . $username);
        eel_cli_writeln('-EOL-');
        return 0;
    }

    try {

        $secret = eel_cli_start_user_otp_reset($otpService, $userId);

    } catch (Throwable $exception) {
        eel_cli_writeln('OTP reset failed: ' . $exception->getMessage());
        eel_cli_writeln('-EOL-');
        return 1;
    }

    eel_cli_writeln('Here is the OTP Secret String: ' . $secret);

    $otpCode = eel_cli_prompt('Please enter the 6 digit OTP code: ');

    if (!eel_cli_finish_user_otp_reset($otpService, $userId, $otpCode)) {
        eel_cli_writeln('OTP Code wrong');
        eel_cli_writeln('-EOL-');
        return 1;
    }

    eel_cli_writeln('OTP updated OK for ' . $username);
    eel_cli_writeln('-EOL-');
    return 0;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(eel_cli_run_reset_password_tool());
}
