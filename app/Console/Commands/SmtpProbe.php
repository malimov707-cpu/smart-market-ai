<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SmtpProbe extends Command
{
    protected $signature = 'ops:smtp-probe {--recipient= : Authorized allowlisted test recipient} {--send : Explicitly authorize one real SMTP message}';

    protected $description = 'Dry-run by default; never logs credentials or treats local mailers as delivery';

    public function handle(): int
    {
        $recipient = (string) $this->option('recipient');
        $mail = config('mail.mailers.'.config('mail.default'), []);
        $allowed = config('operations.smtp_test_recipients', []);
        if (! $this->option('send')) {
            $this->line('DRY RUN: no connection or message; recipient allowlist and --send are required.');

            return self::SUCCESS;
        }
        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL) || ! in_array($recipient, $allowed, true)) {
            $this->error('BLOCKED: recipient is not explicitly authorized in OPS_SMTP_TEST_RECIPIENTS.');

            return self::FAILURE;
        }
        if (($mail['transport'] ?? null) !== 'smtp' || ! (($mail['scheme'] ?? null) === 'smtps' || ($mail['require_tls'] ?? false)) || empty($mail['username']) || empty($mail['password']) || ! empty($mail['url'])) {
            $this->error('UNAVAILABLE: direct authenticated SMTP with required TLS is not configured.');

            return self::FAILURE;
        }
        $id = (string) Str::uuid();
        try {
            $sent = Mail::mailer(config('mail.default'))->raw('Controlled Smart Market operations probe '.$id.'. No business data is included.',
                fn ($message) => $message->to($recipient)->subject('Smart Market SMTP probe '.$id));
            if ($sent === null) {
                $this->error('FAILED: SMTP did not accept the message.');

                return self::FAILURE;
            }
            $this->line('SMTP_ACCEPTED: probe '.$id.'; delivery unconfirmed. Verify inbox/provider evidence separately.');

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('FAILED: SMTP connection/authentication/transport error. No automatic resend; inspect provider evidence before retry.');

            return self::FAILURE;
        }
    }
}
