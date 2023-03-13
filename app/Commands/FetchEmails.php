<?php

namespace App\Commands;

use App\Jobs\EmailProcess;
use App\Jobs\EvidenceSave;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use LaravelZero\Framework\Commands\Command;

class FetchEmails extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'fetch:emails';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Fetch emails to parse';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        Log::info(
            get_class($this).': '.
            'Being called upon to receive an incoming e-mail'
        );

        // Fetch email from imap
        $host = Config::get('main.emailparser.imap.host');
        $port = Config::get('main.emailparser.imap.port');
        $ssl = Config::get('main.emailparser.imap.ssl');
        $username = Config::get('main.emailparser.imap.username');
        $password = Config::get('main.emailparser.imap.password');
        $sslString = ($ssl) ? '/imap/ssl' : '';
        $connectString = sprintf('{%s:%s%s}INBOX', $host, $port, $sslString);
        $mbox = imap_open($connectString, $username, $password)
        or die("can't connect: " . imap_last_error());

        $MC = imap_check($mbox);

        $headers = imap_fetchheader($mbox, $MC->Nmsgs, FT_PREFETCHTEXT);
        $body = imap_body($mbox, $MC->Nmsgs);
        $rawEmail = $headers . "\n" . $body;
        imap_close($mbox);

        /*
         * save evidence onto disk
         */
        $evidence = new EvidenceSave();
        $evidenceData = $rawEmail;
        $evidenceFile = $evidence->save($evidenceData);

        if (!$evidenceFile) {
            Log::error(
                get_class($this).': '.
                'Error returned while asking to write evidence file, cannot continue'
            );
            $this->exception($rawEmail);
        }

        // In debug mode we don't queue the job
        Log::debug(
            get_class($this).': '.
            'Queuing disabled. Directly handling message file: '.$evidenceFile
        );

        $processer = new EmailProcess($evidenceFile);
        $processer->handle();

        Log::info(
            get_class($this).': '.
            'Successfully received the incoming e-mail'
        );

        return true;
    }

    /**
     * We've hit a snag, so we are gracefully killing ourselves after we contact the admin about it.
     *
     * @param string $rawEmail
     *
     * @return mixed
     */
    protected function exception($rawEmail)
    {
        // This only bounces with config errors or problems with installations where we cannot accept
        // the email at all. In normal cases the bounce will be handled within EmailProcess::()
        Log::error(
            get_class($this).': '.
            'Email receiver is ending with errors. The received e-mail will be bounced to the admin for investigation'
        );
    }

    /**
     * Define the command's schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
