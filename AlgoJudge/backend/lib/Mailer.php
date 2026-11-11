<?php
require_once __DIR__ . '/../config/bootstrap.php';

final class Mailer
{
    private static function config(): array
    {
        $env = static fn(string $name, string $default = ''): string => (string)($_ENV[$name] ?? (getenv($name) !== false ? getenv($name) : $default));
        return [
            'host' => trim($env('MAIL_HOST')),
            'username' => trim($env('MAIL_USERNAME')),
            'password' => $env('MAIL_PASSWORD'),
            'from' => trim($env('MAIL_FROM_ADDRESS')),
            'port' => (int)$env('MAIL_PORT', '587'),
            'encryption' => strtolower(trim($env('MAIL_ENCRYPTION', 'tls'))),
            'name' => trim($env('MAIL_FROM_NAME', 'AlgoJudge')),
        ];
    }

    public static function isConfigured(): bool
    {
        $c = self::config();
        return $c['host'] !== '' && $c['username'] !== '' && $c['password'] !== ''
            && filter_var($c['from'], FILTER_VALIDATE_EMAIL)
            && $c['port'] > 0 && $c['port'] <= 65535
            && in_array($c['encryption'], ['tls', 'ssl'], true)
            && class_exists(\PHPMailer\PHPMailer\PHPMailer::class);
    }

    public static function sendPasswordResetOtp(string $recipient, string $otp): void
    {
        self::sendOtp($recipient, $otp, 'đặt lại mật khẩu');
    }

    public static function sendProfileOtp(string $recipient, string $otp, string $purpose): void
    {
        $labels = ['profile_password' => 'đổi mật khẩu trong hồ sơ', 'profile_email' => 'đổi email trong hồ sơ'];
        if (!isset($labels[$purpose])) throw new InvalidArgumentException('Yêu cầu OTP không hợp lệ.');
        self::sendOtp($recipient, $otp, $labels[$purpose]);
    }

    private static function sendOtp(string $recipient, string $otp, string $action): void
    {
        if (!self::isConfigured()) throw new RuntimeException('Chức năng gửi email chưa được cấu hình.');
        $c = self::config();
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $c['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $c['username'];
        $mail->Password = $c['password'];
        $mail->Port = $c['port'];
        $mail->SMTPSecure = $c['encryption'] === 'ssl'
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Timeout = 10;
        $mail->SMTPDebug = 0;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($c['from'], $c['name']);
        $mail->addAddress($recipient);
        $mail->Subject = 'Mã OTP ' . $action . ' AlgoJudge';
        $mail->isHTML(false);
        $mail->Body = "Mã OTP để $action của bạn là: $otp\nMã hết hạn sau 10 phút, tối đa 5 lần thử. Không chia sẻ mã này với người khác. Nếu bạn không yêu cầu, hãy bỏ qua email này.";
        $mail->send();
    }
}
